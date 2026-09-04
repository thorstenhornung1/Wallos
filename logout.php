<?php
require_once 'includes/connect.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/oidc/logout.php';
require_once 'includes/session_tokens.php';
require_once 'includes/auth_lifetime.php';

$secondsInMonth = wallos_auth_max_session_lifetime();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

// Everything the remote logout needs is read before the session is destroyed.
$fromOidc = isset($_SESSION['from_oidc']) && $_SESSION['from_oidc'] === true;
$idToken = $_SESSION['oidc_id_token'] ?? null;
$sessionToken = $_SESSION['token'] ?? null;

$endSessionUrl = null;
$postLogoutRedirectUrl = null;
$logoutState = null;

if ($fromOidc) {
    $oidcConfiguration = wallos_get_effective_oidc_configuration($db);
    $endSessionUrl = wallos_oidc_end_session_url(
        $oidcConfiguration['settings'],
        $oidcConfiguration['discovery_document']
    );
    $postLogoutRedirectUrl = wallos_oidc_post_logout_redirect_url($oidcConfiguration['settings']);
    $logoutState = bin2hex(random_bytes(16));
}

// Local logout happens first and completely. A provider that is unreachable,
// misconfigured or slow must never be able to leave the user logged in here.
//
// The token surviving is the one failure that undoes the logout: the session is
// destroyed and the cookie cleared, but the row it names is still valid, so any
// browser still holding that cookie — the shared machine the user just walked
// away from — is signed straight back in.
if (wallos_revoke_login_token($db, $sessionToken) === false) {
    error_log('Wallos: logout could not revoke the remember-me token; '
        . 'any browser still holding it stays signed in');
}

// Drop the back-channel session row as well, so a provider ending a session
// that already ended finds nothing to do rather than a stale row.
$stmt = $db->prepare('DELETE FROM oidc_sessions WHERE session_id = :sessionId');
if ($stmt !== false) {
    $stmt->bindValue(':sessionId', session_id(), SQLITE3_TEXT);
    if ($stmt->execute() === false) {
        error_log('Wallos: logout left a stale OIDC session row: ' . $db->lastErrorMsg());
    }
}

$_SESSION = array();
session_destroy();
$cookieExpire = time() - 3600;
setcookie('wallos_login', '', $cookieExpire);

if ($endSessionUrl !== null) {
    // The state is carried in a fresh session purely so the return can be
    // recognised; it holds nothing else.
    session_start();
    session_regenerate_id(true);
    $_SESSION['oidc_logout_state'] = $logoutState;
    session_write_close();
}

$db->close();

if ($endSessionUrl !== null) {
    header('Location: ' . wallos_oidc_build_end_session_url(
        $endSessionUrl,
        $idToken,
        $postLogoutRedirectUrl,
        $logoutState
    ));
    exit();
}

?>
<!DOCTYPE html>
<html>
<head>
<script>
  async function clearAndRedirect() {
    if ('caches' in window) {
      await caches.delete('pages-cache-v1');
    }
    sessionStorage.removeItem('sw_prefetched');
    window.location.href = '.';
  }
  clearAndRedirect();
</script>
</head>
<body></body>
</html>
<?php
exit();
