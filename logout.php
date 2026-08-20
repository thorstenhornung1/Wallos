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

// Revoke the remember-me token before the session goes away.
//
// $userId was never assigned in this file, so the statement bound null and
// "user_id = null" matched no row: every logout left a usable token behind and
// the next request signed the user straight back in. The id comes from the
// session, which is where login.php put it.
//
// The result is checked because a failed revocation and nothing to revoke are
// different outcomes, and only one of them is safe to ignore.
if (isset($_SESSION['token'], $_SESSION['userId'])) {
    $token = $_SESSION['token'];
    $userId = $_SESSION['userId'];
    $sql = "DELETE FROM login_tokens WHERE token = :token AND user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':token', $token, SQLITE3_TEXT);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

    if ($stmt->execute() === false) {
        error_log('Wallos: could not revoke the login token on logout for user ' . $userId
            . '; the remember-me cookie may still be usable');
    }
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
