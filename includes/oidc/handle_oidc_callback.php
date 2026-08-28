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

$postFields = [
    'grant_type' => 'authorization_code',
    'code' => $_GET['code'],
    'redirect_uri' => $redirectUri,
    'client_id' => $oidcSettings['client_id'],
];

// A public client has no secret, and sending client_secret= as an empty
// parameter is not the same as not authenticating with one: strict providers
// read the empty value as a failed client authentication. Omitted entirely,
// the request is a plain public-client token exchange.
if ((string) $oidcSettings['client_secret'] !== '') {
    $postFields['client_secret'] = $oidcSettings['client_secret'];
}

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_RESOLVE, ["{$tokenUrlInfo['host']}:{$tokenUrlInfo['port']}:{$tokenUrlInfo['ip']}"]);
$response = curl_exec($ch);
$curlError = curl_errno($ch) ? curl_error($ch) : null;
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
unset($ch);

$tokenData = json_decode($response, true);
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
curl_setopt($ch, CURLOPT_RESOLVE, ["{$userInfoUrlInfo['host']}:{$userInfoUrlInfo['port']}:{$userInfoUrlInfo['ip']}"]);
$response = curl_exec($ch);
unset($ch);

$userInfo = json_decode($response, true);
if (!$userInfo || !isset($userInfo[$oidcSettings['user_identifier_field']])) {
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('userinfo_failed', [
        'identifier_field' => $oidcSettings['user_identifier_field'],
        'claims_returned' => is_array($userInfo) ? implode(',', array_keys($userInfo)) : null,
        'provider_error' => is_array($userInfo) ? ($userInfo['error'] ?? null) : null,
    ]);

    $db->close();
    header("Location: login.php?error=oidc_userinfo_failed");
    exit();
}

$oidcSub = $userInfo[$oidcSettings['user_identifier_field']];

// Check if sub matches an existing user
$stmt = $db->prepare('SELECT * FROM "user" WHERE oidc_sub = :oidcSub');
$stmt->bindValue(':oidcSub', $oidcSub, SQLITE3_TEXT);
$result = $stmt->execute();
$userData = $result->fetchArray(SQLITE3_ASSOC);

if ($userData) {
    // User exists, log the user in
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
        // Update existing user with OIDC sub
        //
        // The sign-in goes ahead either way: the identity is established by the
        // token, and refusing here would lock somebody out over a write that
        // has nothing to do with who they are. But the link is what every later
        // sign-in matches on instead of falling back to the email address, so a
        // silent failure means this account keeps being matched the weaker way
        // — worth a line in the log rather than nothing at all (issue #87).
        $stmt = $db->prepare('UPDATE "user" SET oidc_sub = :oidcSub WHERE id = :userId');
        $linked = false;

        if ($stmt !== false) {
            $stmt->bindValue(':oidcSub', $oidcSub, SQLITE3_TEXT);
            $stmt->bindValue(':userId', $userData['id'], SQLITE3_INTEGER);
            $linked = $stmt->execute() !== false;
        }

        if (!$linked) {
            error_log('Wallos OIDC: signed user ' . $userData['id'] . ' in but could not record the '
                . 'provider subject, so the account stays matched by email address: '
                . $db->lastErrorMsg());
        }

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
