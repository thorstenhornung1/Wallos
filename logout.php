<?php
require_once 'includes/connect.php';
require_once 'includes/oidc_settings.php';
$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

$logoutOIDC = false;

// Check if user is logged in with OIDC
if (isset($_SESSION['from_oidc']) && $_SESSION['from_oidc'] === true) {
    $logoutOIDC = true;
    $oidcConfiguration = wallos_get_effective_oidc_configuration($db);
    $oidcSettings = $oidcConfiguration['settings'];
    $logoutUrl = $oidcSettings['logout_url'] ?? '';
}

// Revoke the persistent login token.
//
// This used to also match on :userId, but $userId is never assigned in this
// file — it bound NULL, and `user_id = NULL` is never true, so the delete
// matched nothing and every logout left a usable token behind. The token value
// identifies the row by itself.
require_once __DIR__ . '/includes/session_tokens.php';
if (isset($_SESSION['token'])) {
    wallos_revoke_login_token($db, $_SESSION['token']);
}
$_SESSION = array();
session_destroy();
$cookieExpire = time() - 3600;
setcookie('wallos_login', '', $cookieExpire);
$db->close();

if ($logoutOIDC && !empty($logoutUrl)) {
    $returnTo = urlencode($oidcSettings['redirect_url'] ?? '');
    header("Location: $logoutUrl?post_logout_redirect_uri=$returnTo");
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
