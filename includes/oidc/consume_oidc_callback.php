<?php
/*
  Consumes an OIDC authorization response when this request carries one.

  Both the document root and login.php are legitimate redirect targets: the
  root because index.php pulls in checksession.php, and login.php because it is
  the page an administrator naturally configures as the redirect URI. Without
  this shared step, a callback arriving at login.php was discarded in silence —
  the provider reported success and the user was simply not logged in.

  A callback carries a state and EITHER a code (the authorization succeeded) OR
  an error (it did not). The error branch is mandatory for the Silent Resume
  Guard: a prompt=none request the provider cannot satisfy returns
  ?error=login_required&state=..., and §10 requires it be handled rather than
  ignored. Every path through the handler ends in a redirect or a rendered
  response, so control returns here only when the request is not a callback.

  A flow is a transaction now (WP1): the state names it, and the transaction
  carries the mode (login or resume), the PKCE verifier, the nonce and, for a
  resume, the session it re-establishes. The transaction is consumed exactly
  once, whatever the outcome.

  Callers must have started the session and opened $db.
*/

// Not a callback at all: the request carries neither a state nor a code/error.
if (!isset($_GET['state']) || (!isset($_GET['code']) && !isset($_GET['error']))) {
    return;
}

require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/transactions.php';

$callbackState = is_string($_GET['state']) ? $_GET['state'] : '';
$callbackCode = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
$callbackError = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';

// Three different problems with three different fixes, so they get three
// different answers rather than one shared "invalid state".

// A malformed response: the parameters are present but empty or the wrong type.
if ($callbackState === '' || ($callbackCode === '' && $callbackError === '')) {
    wallos_oidc_log_failure('oidc_invalid_response', ['had_state' => $callbackState !== '' ? 'yes' : 'no']);
    $db->close();
    header("Location: login.php?error=oidc_invalid_response");
    exit();
}

// Locate and consume the transaction (single-use, §10). An unknown, replayed or
// expired state matches nothing and is refused — the CSRF check the old code did
// with hash_equals against one global state, now over the per-state map.
$transaction = wallos_oidc_consume_transaction($callbackState);
if ($transaction === null) {
    // No transaction for this state. A session that held none at all was most
    // likely dropped between starting and finishing (a cookie lost, a different
    // browser); one that held others but not this is a state that does not match.
    $heldOthers = isset($_SESSION['oidc_transactions']) && is_array($_SESSION['oidc_transactions'])
        && count($_SESSION['oidc_transactions']) > 0;
    $failure = $heldOthers ? 'oidc_state_mismatch' : 'oidc_session_expired';
    wallos_oidc_log_failure($failure, ['held_other_transactions' => $heldOthers ? 'yes' : 'no']);
    $db->close();
    header("Location: login.php?error=" . $failure);
    exit();
}

$transactionMode = isset($transaction['mode']) ? $transaction['mode'] : 'login';

// A resume is its own flow (WP5): prompt=none, bound to the existing identity.
// consume_resume_callback.php ends the request on every path.
if ($transactionMode === 'resume') {
    require __DIR__ . '/consume_resume_callback.php';
    exit();
}

// ---- Login mode: the ordinary Authorization Code flow. ----

// A provider error on a login means the interactive sign-in itself failed (the
// user cancelled, the provider refused). Reported rather than ignored.
if ($callbackError !== '') {
    wallos_oidc_log_failure('oidc_authorization_error', ['provider_error' => $callbackError]);
    $db->close();
    header("Location: login.php?error=oidc_user_not_found");
    exit();
}

// The PKCE verifier for this flow travels in the transaction and is consumed
// with it; handle_oidc_callback.php reads $codeVerifier for the token exchange.
$codeVerifier = isset($transaction['pkce_verifier']) ? $transaction['pkce_verifier'] : null;

require_once __DIR__ . '/handle_oidc_callback.php';
