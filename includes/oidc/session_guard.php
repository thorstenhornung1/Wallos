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

/**
 * Whether the current session is still one the provider vouches for.
 *
 * Non-OIDC sessions are unaffected: there is no provider to have ended them.
 *
 * @param SQLite3|WallosDatabase $db
 * @return bool
 */
function wallos_oidc_current_session_is_valid($db)
{
    if (!isset($_SESSION['from_oidc']) || $_SESSION['from_oidc'] !== true) {
        return true;
    }

    return wallos_oidc_session_is_active($db, session_id());
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
