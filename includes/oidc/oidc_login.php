<?php

if (!isset($userData)) {
    die("User data missing for OIDC login.");
}

require_once __DIR__ . '/../auth_lifetime.php';

$userId = $userData['id'];
$username = $userData['username'];

// Every OIDC sign-in path arrives here — an existing subject, an account linked
// by verified email, and a freshly created one — which makes this the one place
// the provider's admin claim is turned into a role.
//
// Restating it on every login is what makes revocation work: dropping the group
// at the provider removes the role the next time the user authenticates. Only
// the `oidc` source is written, so a local administrator is never affected.
require_once __DIR__ . '/admin_role_sync.php';
if (isset($userInfo) && is_array($userInfo) && isset($oidcSettings)) {
    wallos_sync_oidc_admin_role($db, $userId, $userInfo, $oidcSettings);
}
$language = wallos_resolve_language($userData['language'] ?? null);
$main_currency = $userData['main_currency'];

session_regenerate_id(true);
$_SESSION['username'] = $username;
$_SESSION['loggedin'] = true;
$_SESSION['main_currency'] = $main_currency;
$_SESSION['userId'] = $userId;
$_SESSION['from_oidc'] = true; // Indicate this session is from OIDC login

// Kept for id_token_hint at logout: without it a provider cannot tell which
// session to end and may refuse, prompt, or quietly do nothing. It stays in the
// server-side session — never in a cookie, never rendered, never logged.
if (isset($tokenData['id_token']) && is_string($tokenData['id_token'])) {
    $_SESSION['oidc_id_token'] = $tokenData['id_token'];
}

$cookieExpire = time() + wallos_auth_max_session_lifetime();

// generate remember token
$token = bin2hex(random_bytes(32));
$addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
$addLoginTokensStmt = $db->prepare($addLoginTokens);
$addLoginTokensStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
$addLoginTokensStmt->execute();

// Mark the token as OIDC-derived so a later remember-me restore can tell it
// apart from an ordinary local token once its oidc_sessions row is gone: a
// marked token with no row is a revoked OIDC session and must not be restored as
// a local one (migration 000075, includes/remember_me.php). Guarded on the
// column so an install whose migration has not run yet still signs users in.
if ($db->columnExists('login_tokens', 'from_oidc')) {
    $markOidcToken = $db->prepare('UPDATE login_tokens SET from_oidc = 1 WHERE token = :token');
    if ($markOidcToken !== false) {
        $markOidcToken->bindValue(':token', $token);
        if ($markOidcToken->execute() === false) {
            error_log('Wallos OIDC: could not mark the remember-me token as OIDC-derived for user '
                . $userId . '; a later restore may treat it as a local session: ' . $db->lastErrorMsg());
        }
    }
}

$_SESSION['token'] = $token;

// Recorded so the provider can end this session later. The sid comes from the
// ID token when the provider issues one; without it revocation falls back to
// ending every session of this subject.
require_once __DIR__ . '/backchannel.php';
$oidcSessionId = null;
if (isset($_SESSION['oidc_id_token'])) {
    $parsedIdToken = wallos_jwt_parse($_SESSION['oidc_id_token']);
    if ($parsedIdToken !== null && isset($parsedIdToken['payload']['sid'])) {
        $oidcSessionId = (string) $parsedIdToken['payload']['sid'];
    }
}
wallos_oidc_register_session($db, $userId, $oidcSessionId, session_id(), $token,
    $_SESSION['oidc_id_token'] ?? null);

// The refresh token, and when the access token it belongs to dies.
//
// Without this the provider stops being able to end this session about five
// minutes from now — it builds a back-channel logout out of the access tokens
// belonging to the session, and until #144 Wallos took the one it was handed at
// login and never spoke to the token endpoint again. The credential is stored
// with the session row, never in the PHP session and never in a cookie: it is
// longer-lived than the id token beside it, because it can mint new access
// tokens for as long as the provider's session lasts.
//
// A provider that granted no offline_access sends none, which is recorded as
// honestly as one that did: the row then says this session has nothing to
// refresh with.
require_once __DIR__ . '/refresh.php';
$tokenResponse = isset($tokenData) && is_array($tokenData) ? $tokenData : [];
if (!wallos_oidc_record_access_token($db, session_id(), $tokenResponse, time())) {
    // Logged inside, and not fatal: the sign-in is complete and refusing it
    // would be a worse answer than a session that cannot be refreshed. What it
    // costs is remote revocability, which is what the log line says.
    error_log('Wallos OIDC: signed user ' . $userId . ' in without recording the refresh token, so '
        . 'the identity provider will lose the ability to end this session when the access token '
        . 'expires.');
}
$cookieValue = $username . "|" . $token . "|" . $main_currency;
setcookie('wallos_login', $cookieValue, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax',
    'httponly' => true,
]);

// Set language cookie
setcookie('language', $language, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
]);

// Set sort order default
if (!isset($_COOKIE['sortOrder'])) {
    setcookie('sortOrder', 'next_payment', [
        'expires' => $cookieExpire,
        'samesite' => 'Lax'
    ]);
}

// Set color theme
$query = "SELECT color_theme FROM settings WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$settings = $result->fetchArray(SQLITE3_ASSOC);
setcookie('colorTheme', $settings['color_theme'], [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
]);

// Done
$db->close();
header("Location: .");
exit();
