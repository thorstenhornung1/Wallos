<?php

/*
  The resume half of the OIDC callback (WP5 / §22).

  A prompt=none authorization response, dispatched to exactly one of:

    - restore access: the code exchanged, the same (iss, sub) confirmed, the
      authority row moved back to VALID, the session id regenerated, and the
      browser returned to where it was;
    - interactive login: the provider could not authenticate silently, the ID
      token did not bind to this identity, or the exchange definitively failed —
      the local session is ended and the user logs in normally (never switching
      accounts, §13);
    - temporarily unavailable: the provider or token endpoint was unreachable —
      a 503, the local state left exactly as it was so the next request retries,
      and nothing revoked (§22, test L).

  Included by consume_oidc_callback.php once the transaction is known to be a
  resume. Assumes $db, $transaction, $callbackCode and $callbackError are set,
  and that the session is started. Ends the request.
*/

require_once __DIR__ . '/../oidc_settings.php';
require_once __DIR__ . '/resume.php';

/**
 * Ends the local session and redirects to an interactive login.
 *
 * Mirrors the revoked-session cleanup in wallos_oidc_require_valid_session: the
 * PHP session and the remember-me cookie go, so the browser is not signed
 * straight back into a session the provider would not vouch for.
 *
 * @param WallosDatabase $db
 * @param string         $location
 * @return void
 */
function wallos_oidc_resume_end_session_and_login($db, $location)
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    setcookie('wallos_login', '', time() - 3600);
    $db->close();
    header('Location: ' . $location);
    exit();
}

/**
 * Renders the "temporarily unavailable" response and ends the request.
 *
 * A top-level browser navigation landed here, so the answer is a small HTML page
 * rather than JSON: 503 with Retry-After, no-store, and a link back. The session
 * is untouched — reloading re-enters revalidation and tries the provider again.
 *
 * @param WallosDatabase $db
 * @param string         $returnTo
 * @return void
 */
function wallos_oidc_resume_render_unavailable($db, $returnTo)
{
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 30');
    header('Cache-Control: no-store');

    $safeReturn = htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Wallos</title></head><body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;">'
        . '<h1>Sign-in temporarily unavailable</h1>'
        . '<p>Your identity provider could not be reached to confirm your session. '
        . 'Your session has not been ended &mdash; please try again in a moment.</p>'
        . '<p><a href="' . $safeReturn . '">Retry</a></p>'
        . '</body></html>';

    $db->close();
    exit();
}

$resumeReturnTo = wallos_oidc_sanitize_return_to($transaction['return_to'] ?? null);
$resumeSessionId = session_id();

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$resumeConfigured = $oidcConfiguration['enabled'] === 1 && $oidcConfiguration['is_configured'];
$oidcSettings = $oidcConfiguration['settings'];

// The signing keys and the authoritative issuer for validating the resumed ID
// token come from the discovery document, not from the stored settings, so they
// are carried alongside the settings into the exchange (WP2).
$oidcSettings['jwks_uri'] = wallos_oidc_discovery_jwks_uri($oidcConfiguration);
$oidcSettings['issuer'] = wallos_oidc_expected_issuer($oidcConfiguration);

// A provider error on prompt=none.
if ($callbackError !== '') {
    require_once __DIR__ . '/diagnostics.php';
    wallos_oidc_log_failure('oidc_prompt_none_error', ['provider_error' => $callbackError]);

    if (wallos_oidc_classify_prompt_none_error($callbackError) === 'suspended') {
        // Provider temporarily unavailable — keep the state, retry (test L).
        wallos_oidc_resume_render_unavailable($db, $resumeReturnTo);
    }

    // login_required and its siblings: the provider ended the session centrally
    // and cannot re-authenticate the browser silently, so the local session must
    // not continue. This is a central logout, not a lost or timed-out local
    // session, so it carries its own honest code (oidc_logged_out) rather than the
    // generic "session lost" one — the login page turns it into a message that
    // says the provider signed the user out, which is what actually happened.
    wallos_oidc_resume_end_session_and_login($db, 'login.php?error=oidc_logged_out');
}

if (!$resumeConfigured || $callbackCode === '') {
    // Nothing to exchange against, or no code came back with the response.
    wallos_oidc_resume_end_session_and_login($db, 'login.php');
}

$resumeResult = wallos_oidc_resume_exchange_and_confirm(
    $db,
    $oidcSettings,
    $transaction,
    $callbackCode,
    $resumeSessionId
);

switch ($resumeResult['outcome']) {
    case 'revalidated':
        // Fresh authority. Regenerate the session id (§15/§T) and move the row
        // onto it before returning to where the user was.
        $oldResumeSessionId = session_id();
        session_regenerate_id(true);
        wallos_oidc_move_session_row($db, $oldResumeSessionId, session_id());
        unset($_SESSION['oidc_refresh_after']); // a fresh token; let the guard re-evaluate
        $db->close();
        header('Location: ' . $resumeReturnTo);
        exit();

    case 'suspended':
        // Provider/token endpoint unreachable during the exchange (test L).
        wallos_oidc_resume_render_unavailable($db, $resumeReturnTo);
        // no break — render exits

    case 'account_mismatch':
    case 'nonce_mismatch':
    case 'failed':
    default:
        // The silent auth could not re-establish THIS identity. The exchange
        // already ended the authority row for these outcomes; end the PHP session
        // too and log in interactively.
        wallos_oidc_resume_end_session_and_login($db, 'login.php');
}
