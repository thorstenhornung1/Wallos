<?php
require_once 'includes/connect.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/oidc/logout.php';
require_once 'includes/session_tokens.php';

$secondsInMonth = 30 * 24 * 60 * 60;
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
wallos_revoke_login_token($db, $sessionToken);

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
