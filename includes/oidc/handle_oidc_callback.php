<?php

require_once __DIR__ . '/../oidc_settings.php';

function generate_username_from_email($email)
{
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    // Take the part before the @, remove non-alphanumeric characters, and lowercase
    $username = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', explode('@', $email)[0]));
    return $username;
}

require_once __DIR__ . '/../ssrf_helper.php';
require_once __DIR__ . '/id_token.php';

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
if ($oidcConfiguration['enabled'] !== 1 || !$oidcConfiguration['is_configured']) {
    header("Location: login.php?error=oidc_user_not_found");
    exit();
}

$oidcSettings = $oidcConfiguration['settings'];

$tokenUrl = $oidcSettings['token_url'];
$redirectUri = $oidcSettings['redirect_url'];

$tokenUrlInfo = validate_oidc_endpoint_url($tokenUrl, $db);
if ($tokenUrlInfo === false) {
    header("Location: login.php?error=oidc_invalid_config");
    exit();
}

// The exchange fields, including the PKCE verifier consume_oidc_callback.php
// took out of the session (single-use, in lockstep with the state) and left
// in $codeVerifier. Built by a pure helper so the request body is checkable
// without a socket; the empty-secret and absent-verifier omissions live there.
require_once __DIR__ . '/pkce.php';
$postFields = wallos_oidc_token_request_fields(
    $oidcSettings,
    $_GET['code'],
    $redirectUri,
    $codeVerifier ?? null
);

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_RESOLVE, ["{$tokenUrlInfo['host']}:{$tokenUrlInfo['port']}:" . implode(',', $tokenUrlInfo['ips'])]);
$response = curl_exec($ch);
$curlError = curl_errno($ch) ? curl_error($ch) : null;
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
unset($ch);

$tokenData = $response !== false ? json_decode($response, true) : null;
if (!$tokenData || !isset($tokenData['access_token'])) {
    // The provider's own error body says why — invalid_client, invalid_grant,
    // redirect_uri mismatch — and discarding it is what turns a five-minute
    // fix into an afternoon.
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('token_exchange_failed', [
        'http_status' => $httpCode ?: null,
        'curl_error' => $curlError,
        'provider_error' => is_array($tokenData) ? ($tokenData['error'] ?? null) : null,
        'provider_error_description' => is_array($tokenData) ? ($tokenData['error_description'] ?? null) : null,
    ]);

    $db->close();
    header("Location: login.php?error=oidc_token_exchange_failed");
    exit();
}

// The ID token is the authentication assertion (OIDC Session Authority v2, WP2 /
// §11). It is put through the full standards-compliant validator — signature,
// algorithm allowlist, exact issuer, audience/azp, exp, iat, non-empty sub, and
// the exact nonce this login transaction carried — BEFORE any identity is read
// from it or from UserInfo. The nonce travels in $oidcNonce, which
// consume_oidc_callback.php took out of the login transaction. A validated ID
// token, not a UserInfo field, is what identity is bound to.
$idToken = isset($tokenData['id_token']) && is_string($tokenData['id_token']) ? $tokenData['id_token'] : '';
$idTokenValidation = wallos_oidc_validate_id_token($db, $idToken, [
    'issuer' => wallos_oidc_expected_issuer($oidcConfiguration),
    'client_id' => $oidcSettings['client_id'] ?? '',
    'nonce' => $oidcNonce ?? '',
    'jwks_uri' => wallos_oidc_discovery_jwks_uri($oidcConfiguration),
], time());

if (!$idTokenValidation['valid']) {
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('idtoken_validation_failed', [
        'reason' => $idTokenValidation['error'],
    ]);

    $db->close();
    header("Location: login.php?error=oidc_user_not_found");
    exit();
}

$idTokenClaims = $idTokenValidation['claims'];
$idTokenSub = (string) $idTokenClaims['sub'];
$idTokenIssuer = (string) $idTokenClaims['iss'];

if (!$idTokenValidation['signature_verified']) {
    // This install has no discovery document, so no jwks_uri, so the signature
    // could not be verified — every other claim still was. Not fatal (the token
    // came down the pinned-TLS token channel and carries this transaction's
    // server-side nonce), but the operator should know identity is not
    // cryptographically proven until an issuer is configured for discovery.
    error_log('Wallos OIDC: signed a user in without verifying the ID token signature because no '
        . 'JWKS endpoint is configured (set an OIDC issuer so discovery can supply one). Every other '
        . 'ID-token claim was validated.');
}

$userInfoUrl = $oidcSettings['user_info_url'];

$userInfoUrlInfo = validate_oidc_endpoint_url($userInfoUrl, $db);
if ($userInfoUrlInfo === false) {
    header("Location: login.php?error=oidc_invalid_config");
    exit();
}

$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token']
]);
curl_setopt($ch, CURLOPT_RESOLVE, ["{$userInfoUrlInfo['host']}:{$userInfoUrlInfo['port']}:" . implode(',', $userInfoUrlInfo['ips'])]);
$response = curl_exec($ch);
$curlError = curl_errno($ch) ? curl_error($ch) : null;
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
unset($ch);

$userInfo = $response !== false ? json_decode($response, true) : null;
if (!$userInfo || !isset($userInfo[$oidcSettings['user_identifier_field']])) {
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('userinfo_failed', [
        'http_status' => $httpCode ?: null,
        'curl_error' => $curlError,
        'identifier_field' => $oidcSettings['user_identifier_field'],
        'claims_returned' => is_array($userInfo) ? implode(',', array_keys($userInfo)) : null,
        'provider_error' => is_array($userInfo) ? ($userInfo['error'] ?? null) : null,
    ]);

    $db->close();
    header("Location: login.php?error=oidc_userinfo_failed");
    exit();
}

// UserInfo MUST describe the same subject the ID token asserted (§12, test E).
// A response whose `sub` differs is a substituted identity — the profile of one
// account attached to another's authenticated token — and is refused, so the
// (iss, sub) identity below is always the one the validated ID token named.
if (!wallos_oidc_userinfo_sub_matches($idTokenSub, $userInfo)) {
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('userinfo_sub_mismatch', [
        'consequence' => 'the UserInfo subject did not equal the ID token subject; login refused',
    ]);

    $db->close();
    header("Location: login.php?error=oidc_user_not_found");
    exit();
}

// Identity is the (iss, sub) pair from the validated ID token, never a UserInfo
// field (§12). The subject is the canonical one the token asserted; the issuer
// is which provider minted it.
$oidcSub = $idTokenSub;
$oidcIssuer = $idTokenIssuer;

// Look the account up by the (iss, sub) pair. A row whose issuer is still empty
// is a legacy account from before this column existed (migration 000084): it is
// matched on the subject alone and its issuer is backfilled below, so nobody is
// locked out by the stricter key. A row bound to a DIFFERENT issuer with the
// same subject is deliberately NOT matched — that is the collision the pair
// prevents.
$stmt = $db->prepare('SELECT * FROM "user"
                       WHERE oidc_sub = :oidcSub
                         AND (issuer = :issuer OR issuer IS NULL OR issuer = \'\')');
$stmt->bindValue(':oidcSub', $oidcSub);
$stmt->bindValue(':issuer', $oidcIssuer);
$result = $stmt->execute();
$userData = $result === false ? false : $result->fetchArray();

if ($userData) {
    // Adopt a legacy row: record which issuer minted this subject the first time
    // it signs in after the column was added. Not fatal if it fails — the account
    // is still matched by subject next time — so it is logged, not blocked.
    $existingIssuer = isset($userData['issuer']) ? (string) $userData['issuer'] : '';
    if ($existingIssuer === '' && $oidcIssuer !== '') {
        $backfill = $db->prepare('UPDATE "user" SET issuer = :issuer WHERE id = :userId');
        if ($backfill !== false) {
            $backfill->bindValue(':issuer', $oidcIssuer);
            $backfill->bindValue(':userId', (int) $userData['id']);
            if ($backfill->execute() === false) {
                error_log('Wallos OIDC: could not backfill the issuer for user ' . (int) $userData['id']
                    . '; the account stays bound by subject alone: ' . $db->lastErrorMsg());
            } else {
                $userData['issuer'] = $oidcIssuer;
            }
        }
    }

    // User exists, log the user in
    // A returning user's provider picture may update their avatar, within the
    // strict policy in includes/oidc/oidc_avatar.php: only the default or a
    // previously imported avatar is ever replaced, and only by a changed
    // picture. A bad picture is ignored and never fails the login.
    require_once __DIR__ . '/oidc_avatar.php';
    wallos_oidc_maybe_update_avatar($db, $userData, $userInfo['picture'] ?? null, $oidcSub);
    require_once __DIR__ . '/oidc_profile_sync.php';
    wallos_oidc_maybe_update_profile($db, $userData, $userInfo, $oidcSettings);
    require_once('oidc_login.php');

} else {
    // Might be an existing user with the same email
    $email = $userInfo['email'] ?? null;

    if (!$email) {
        // Login failed, we have nothing to go on with, redirect to login page with error
        header("Location: login.php?error=oidc_user_not_found");
        exit();
    }

    // Require email_verified when the setting is enabled (default on).
    // Prevents account takeover by an attacker who presents an unverified email
    // matching an existing local account at a permissive or attacker-controlled IdP.
    if ($oidcSettings['require_email_verified'] && ($userInfo['email_verified'] ?? false) !== true) {
        header("Location: login.php?error=oidc_email_not_verified");
        exit();
    }

    $stmt = $db->prepare('SELECT * FROM "user" WHERE email = :email');
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $result = $stmt->execute();
    $userData = $result->fetchArray(SQLITE3_ASSOC);
    if ($userData) {
        // Update existing user with the OIDC (issuer, subject) pair
        //
        // The sign-in goes ahead either way: the identity is established by the
        // token, and refusing here would lock somebody out over a write that
        // has nothing to do with who they are. But the link is what every later
        // sign-in matches on instead of falling back to the email address, so a
        // silent failure means this account keeps being matched the weaker way
        // — worth a line in the log rather than nothing at all (issue #87). The
        // issuer is written alongside the subject so the account is bound to the
        // canonical (iss, sub) pair from this first link (§12).
        $stmt = $db->prepare('UPDATE "user" SET oidc_sub = :oidcSub, issuer = :issuer WHERE id = :userId');
        $linked = false;

        if ($stmt !== false) {
            $stmt->bindValue(':oidcSub', $oidcSub);
            $stmt->bindValue(':issuer', $oidcIssuer);
            $stmt->bindValue(':userId', (int) $userData['id']);
            $linked = $stmt->execute() !== false;
        }

        if (!$linked) {
            error_log('Wallos OIDC: signed user ' . $userData['id'] . ' in but could not record the '
                . 'provider subject, so the account stays matched by email address: '
                . $db->lastErrorMsg());
        }

        require_once __DIR__ . '/oidc_avatar.php';
        wallos_oidc_maybe_update_avatar($db, $userData, $userInfo['picture'] ?? null, $oidcSub);
        $userData['oidc_sub'] = $oidcSub;
        $userData['issuer'] = $oidcIssuer;
        require_once __DIR__ . '/oidc_profile_sync.php';
        wallos_oidc_maybe_update_profile($db, $userData, $userInfo, $oidcSettings);
        // Log the user in
        require_once('oidc_login.php');
    } else {
        // Check if auto-create is enabled
        if ($oidcSettings['auto_create_user']) {
            // Create a new user

            //check if username is already taken
            $usernameBase = $userInfo['preferred_username'] ?? generate_username_from_email($email);
            $username = $usernameBase;
            $attempt = 1;

            while (true) {
                $stmt = $db->prepare('SELECT COUNT(*) as count FROM "user" WHERE username = :username');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $result = $stmt->execute();
                $row = $result->fetchArray(SQLITE3_ASSOC);

                if ($row['count'] == 0) {
                    break; // Username is available
                }

                $username = $usernameBase . $attempt;
                $attempt++;
            }

            require_once('oidc_create_user.php');


        } else {
            // Login failed, redirect to login page with error
            header("Location: login.php?error=oidc_user_not_found");
            exit();
        }
    }
}


?>
