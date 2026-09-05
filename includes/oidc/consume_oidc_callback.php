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

require_once __DIR__ . '/diagnostics.php';

$code = $_GET['code'];
$state = $_GET['state'];
$expectedState = $_SESSION['oidc_state'] ?? null;

// The PKCE verifier travels with the state: read it here, and clear it in
// the same unset below, so a single callback consumes both exactly once.
// handle_oidc_callback.php, required at the end of the success path, reads
// this local value for the token exchange after the session copy is gone.
$codeVerifier = $_SESSION['oidc_code_verifier'] ?? null;

// Three different problems with three different fixes, so they get three
// different answers rather than one shared "invalid state".
$failure = null;

if (!is_string($code) || $code === '' || !is_string($state) || $state === '') {
    $failure = 'oidc_invalid_response';
} elseif (!is_string($expectedState) || $expectedState === '') {
    // No state to compare against: the session is gone. Typically a cookie
    // dropped between starting and finishing the login, or a different browser.
    $failure = 'oidc_session_expired';
} elseif (!hash_equals($expectedState, $state)) {
    $failure = 'oidc_state_mismatch';
}

if ($failure !== null) {
    wallos_oidc_log_failure($failure, [
        'had_session_state' => is_string($expectedState) && $expectedState !== '' ? 'yes' : 'no',
    ]);
    unset($_SESSION['oidc_state'], $_SESSION['oidc_code_verifier']);
    $db->close();
    header("Location: login.php?error=" . $failure);
    exit();
}

unset($_SESSION['oidc_state'], $_SESSION['oidc_code_verifier']);

require_once __DIR__ . '/handle_oidc_callback.php';
