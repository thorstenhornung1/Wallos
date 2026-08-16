<?php
/*
  Consumes an OIDC authorization response when this request carries one.

  Both the document root and login.php are legitimate redirect targets: the
  root because index.php pulls in checksession.php, and login.php because it is
  the page an administrator naturally configures as the redirect URI. Without
  this shared step, a callback arriving at login.php was discarded in silence —
  the provider reported success and the user was simply not logged in.

  Every path through the callback handler ends in a redirect, so control
  returns here only when the request is not a callback.

  Callers must have started the session and opened $db.
*/

if (!isset($_GET['code']) || !isset($_GET['state'])) {
    return;
}

$code = $_GET['code'];
$state = $_GET['state'];
$expectedState = $_SESSION['oidc_state'] ?? null;

if (
    !is_string($code) || $code === '' ||
    !is_string($state) || $state === '' ||
    !is_string($expectedState) || $expectedState === '' ||
    !hash_equals($expectedState, $state)
) {
    unset($_SESSION['oidc_state']);
    $db->close();
    header("Location: login.php?error=oidc_invalid_state");
    exit();
}

unset($_SESSION['oidc_state']);

require_once __DIR__ . '/handle_oidc_callback.php';
