<?php

/*
  /oidc/revalidate — the entry point of the Silent Resume Guard (WP5, §8).

  The session guard resolves REVALIDATION_REQUIRED when a local OIDC session is
  past its back-channel coverage boundary: the row still lives, but Wallos needs
  fresh browser proof that the provider's session does too. Rather than force an
  interactive re-login, the browser is sent here, and here it is bounced to the
  provider's /authorize with prompt=none and the previous ID token as
  id_token_hint. If the provider still has a session for this browser it answers
  silently and the callback restores access; if it does not, it answers
  login_required and the callback falls through to an interactive login.

  Reached by an HTML redirect (checksession.php) or by the centralized JS reauth
  handler following a 401 that named this URL (WP9/#159). It is a GET that only
  starts the victim's own silent re-auth, so it needs no CSRF token — exactly
  like the "Sign in with <provider>" link that starts an ordinary login.

  This file lives at the web root (not under includes/, which nginx refuses to
  execute) so the browser can reach it.
*/

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_lifetime.php';
require_once __DIR__ . '/../includes/oidc_settings.php';
require_once __DIR__ . '/../includes/oidc/transactions.php';
require_once __DIR__ . '/../includes/oidc/resume.php';

$secondsInMonth = wallos_auth_max_session_lifetime();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,
        'httponly' => true,
        'samesite' => 'Lax',
        // Secure on HTTPS (§15): the resumed session cookie is never sent over
        // plaintext. Conditioned on HTTPS so http://localhost development works.
        'secure' => wallos_request_is_https(),
    ]);
    session_start();
}

// Only an OIDC-derived, still-logged-in session has anything to resume. Anyone
// else is sent to the ordinary login page rather than into a prompt=none loop.
$isOidcSession = isset($_SESSION['from_oidc']) && $_SESSION['from_oidc'] === true;
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
if (!$isOidcSession || !$isLoggedIn) {
    $db->close();
    header('Location: ../login.php');
    exit();
}

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
if ($oidcConfiguration['enabled'] !== 1 || !$oidcConfiguration['is_configured']) {
    // The provider was reconfigured away under a live session. There is nothing
    // to revalidate against, so send the user to log in the normal way.
    $db->close();
    header('Location: ../login.php?error=oidc_invalid_config');
    exit();
}
$oidcSettings = $oidcConfiguration['settings'];

// Where to send the browser once access is restored. Validated to a local
// relative path so it can never become an open redirect.
$returnTo = wallos_oidc_sanitize_return_to($_GET['return_to'] ?? null);

// A resume transaction, targeting THIS session's authority row, with its own
// fresh state, nonce and PKCE verifier so it cannot collide with a login flow
// running in another tab.
$transaction = wallos_oidc_create_transaction('resume', $returnTo, session_id());

$idTokenHint = isset($_SESSION['oidc_id_token']) && is_string($_SESSION['oidc_id_token'])
    ? $_SESSION['oidc_id_token']
    : null;

$authorizeUrl = wallos_oidc_build_resume_authorize_url($oidcSettings, $transaction, $idTokenHint);

$db->close();
header('Location: ' . $authorizeUrl);
exit();
