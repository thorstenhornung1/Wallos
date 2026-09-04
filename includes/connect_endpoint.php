<?php

require_once __DIR__ . '/database/connection.php';

$databaseFile = wallos_database_path();
$db = wallos_database_connect();

if (!$db) {
    die('Connection to the database failed.');
}

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';
require_once 'remember_me.php';
require_once __DIR__ . '/auth_lifetime.php';

$secondsInMonth = wallos_auth_max_session_lifetime();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $userId = $_SESSION['userId'];

    // Every endpoint bootstraps through this file, so this is where a session
    // the provider has ended has to stop working. checksession.php performs the
    // same check for page loads; without it here, back-channel logout left the
    // whole API reachable until the PHP session expired.
    require_once __DIR__ . '/oidc/session_guard.php';
    wallos_oidc_require_valid_session($db);
} else {
    // The PHP session can be garbage-collected (default ~24 min) long before
    // the "remember me" cookie should expire (30 days). Fall back to it here,
    // the same way full page loads do via checksession.php, so AJAX/API
    // endpoints don't silently behave as logged-out after an idle period.
    $restoredUser = restoreSessionFromRememberMeCookie($db);
    $userId = $restoredUser !== false ? $restoredUser['id'] : 0;

    // A restored OIDC session must clear the same guard a live one does above,
    // before the endpoint runs. This is the long-idle gap: the PHP session was
    // collected past the point its access token should have been refreshed, so
    // this first request is where the refresh happens and where a provider that
    // has definitively rejected the session ends it — all before any protected
    // data is served. (Req 4)
    if ($restoredUser !== false && isset($_SESSION['from_oidc'])
        && $_SESSION['from_oidc'] === true) {
        require_once __DIR__ . '/oidc/session_guard.php';
        wallos_oidc_require_valid_session($db);
    }
}

?>