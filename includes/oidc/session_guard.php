<?php

/**
 * Rejecting a session the identity provider has ended.
 *
 * Back-channel logout deletes the `oidc_sessions` row. That only takes effect
 * if something checks the row on the next request — and the check has to sit on
 * every entry point, not just the one that renders pages.
 *
 * It was on `checksession.php` alone. The 112 files that bootstrap through
 * `connect_endpoint.php` never asked, so after the provider ended a session the
 * browser kept full API access — including user administration and database
 * backup — until the PHP session expired, up to thirty days later. Only
 * navigating to an HTML page logged the user out.
 */

require_once __DIR__ . '/backchannel.php';
require_once __DIR__ . '/refresh.php';

/**
 * Whether the current session is still one the provider vouches for.
 *
 * Non-OIDC sessions are unaffected: there is no provider to have ended them.
 *
 * It also keeps the provider able to answer that question at all. The identity
 * provider builds a back-channel logout out of the access tokens belonging to a
 * session, so a session whose access token has expired is one it stops
 * notifying anybody about — silently, five minutes after login, while the
 * session itself lives thirty days (#144). Refreshing before that happens is
 * what keeps the two lifetimes in the same order of magnitude.
 *
 * The refresh is here, in the guard, rather than at each entry point, because
 * this is the one function every authenticated request already reaches. Putting
 * it anywhere else means one path getting it and another not — which is exactly
 * how 112 endpoints once went unguarded with the suite green. It costs no query
 * when nothing is due; see wallos_oidc_maintain_access_token().
 *
 * @param WallosDatabase $db
 * @return bool
 */
function wallos_oidc_current_session_is_valid($db)
{
    if (!isset($_SESSION['from_oidc']) || $_SESSION['from_oidc'] !== true) {
        return true;
    }

    if (!wallos_oidc_session_is_active($db, session_id())) {
        return false;
    }

    // Never allowed to change the verdict: a refresh that fails leaves the
    // session valid and records that it can no longer be revoked remotely. A
    // provider having a bad minute must not sign anybody out.
    wallos_oidc_maintain_access_token($db, session_id());

    return true;
}

/**
 * Ends the request when the session has been revoked.
 *
 * Endpoints answer JSON, so they get a JSON refusal rather than a redirect to
 * logout.php — an AJAX caller handed an HTML page would report a parse error
 * and leave the user staring at an interface that no longer works.
 *
 * @param SQLite3|WallosDatabase $db
 * @return void
 */
function wallos_oidc_require_valid_session($db)
{
    if (wallos_oidc_current_session_is_valid($db)) {
        return;
    }

    $_SESSION = [];
    session_destroy();
    setcookie('wallos_login', '', time() - 3600);

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Session ended by the identity provider. Please sign in again.',
    ]);
    exit();
}
