<?php

/**
 * Deciding whether a session the identity provider established still has the
 * provider's authority behind it.
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
 *
 * WHAT CHANGED (OIDC Session Authority v2, Phase 1). The guard used to return a
 * boolean, and it returned "valid" whenever the row survived and a token
 * refresh did not come back `revoked`. That trusted a refresh as proof the
 * provider's session still existed — and it is not. A refresh token is an OAuth
 * credential for minting access tokens; authentik's refresh tokens are
 * configured to outlive the AuthenticatedSession they were minted for, so a
 * session an administrator killed at the provider left a refresh token that
 * still worked, and the guard resurrected the session on the idle user's next
 * request (#144, the refresh-oracle). The guard now resolves one of four states
 * instead, and a successful refresh can no longer, by itself, make a session
 * valid.
 */

require_once __DIR__ . '/backchannel.php';
require_once __DIR__ . '/refresh.php';
require_once __DIR__ . '/transactions.php';

/**
 * No evidence of revocation, and inside a period where the provider's logout
 * notification can still be relied on. Protected requests proceed.
 */
define('WALLOS_OIDC_VALID', 'valid');

/**
 * A local session exists, but Wallos needs fresh browser proof that the
 * provider's session still exists before any protected work. In Phase 1 there
 * is no silent revalidation flow yet, so this refuses protected access and the
 * user re-authenticates interactively; Phase 2 turns it into a prompt=none
 * round-trip.
 */
define('WALLOS_OIDC_REVALIDATION_REQUIRED', 'revalidation_required');

/**
 * Revalidation is required but the provider or the authority store is
 * temporarily unreachable. This is NOT a revocation: the local state is kept,
 * no protected data is served, no mutation runs, and the request is asked to
 * retry. Failing open here is the one thing this state exists to prevent.
 */
define('WALLOS_OIDC_SUSPENDED', 'suspended');

/**
 * Definitive evidence the session is invalid: a back-channel logout deleted the
 * row, a definitive refresh failure ended it, or the row is marked revoked. The
 * request is refused and the local session cleared.
 */
define('WALLOS_OIDC_REVOKED', 'revoked');

/**
 * The authority state of the current session.
 *
 * Non-OIDC sessions are always valid here: there is no provider to have ended
 * them, and local authentication governs on its own.
 *
 * The order of the checks is itself the security property. The coverage
 * boundary is tested BEFORE any refresh, because once revalidation is required
 * a refresh must not run — refreshing first is exactly what recreated the #144
 * defect (an expired access token, a missed back-channel logout, a refresh that
 * still succeeds, and Wallos wrongly resurrecting the session). A refresh is
 * therefore only ever reached while the session is still inside its coverage
 * window, where it maintains the infrastructure around a session the boundary
 * already vouches for and can end it (invalid_grant) but never establish it.
 *
 * @param WallosDatabase $db
 * @return string one of the WALLOS_OIDC_* states
 */
function wallos_oidc_session_authority($db)
{
    if (!isset($_SESSION['from_oidc']) || $_SESSION['from_oidc'] !== true) {
        return WALLOS_OIDC_VALID;
    }

    // The pre-migration bypass, and the only one left: while the table is
    // genuinely absent the feature is not installed, so an OIDC-marked session
    // keeps working exactly as it did before this existed. Once the table is
    // present, every failure below fails CLOSED (§17) — a store that cannot be
    // read is uncertainty, and uncertainty withholds access rather than granting
    // it.
    if (!$db->tableExists('oidc_sessions')) {
        return WALLOS_OIDC_VALID;
    }

    $sessionId = session_id();
    $record = wallos_oidc_load_authority_record($db, $sessionId);

    if ($record === false) {
        // The table is here but the authority record could not be read. If the
        // Phase 1 columns are not there yet the migration is mid-flight, which
        // is "feature installing", not "store broken": fall back to the pre-v2
        // behaviour rather than lock everyone out during an upgrade. Otherwise
        // the store is genuinely unreadable and the session is suspended.
        if (!$db->columnExists('oidc_sessions', 'status')) {
            return wallos_oidc_legacy_authority($db, $sessionId);
        }

        return WALLOS_OIDC_SUSPENDED;
    }

    if ($record === null) {
        // No row: a back-channel logout deleted it, or a definitive refusal did.
        // Either way the provider has ended this session.
        return WALLOS_OIDC_REVOKED;
    }

    $status = isset($record['status']) ? $record['status'] : null;

    // A legacy row (no status) predates the authority model and cannot prove it
    // validated the provider's assertion. It reads as revoked so the user signs
    // in once more and comes back with a real status (§25/WP10).
    if ($status === null || $status === '' || $status === WALLOS_OIDC_REVOKED) {
        return WALLOS_OIDC_REVOKED;
    }

    // Already parked in revalidation. The ordering rule forbids a refresh from
    // here, so this returns without reaching maintenance.
    if ($status === WALLOS_OIDC_REVALIDATION_REQUIRED) {
        return WALLOS_OIDC_REVALIDATION_REQUIRED;
    }

    // The back-channel coverage boundary. Past it the provider may have ended
    // the session without Wallos hearing, so the browser must revalidate FIRST
    // and no refresh may run. Persisting the transition makes it stick: every
    // later request short-circuits above rather than trying to refresh again.
    $coverageUntil = wallos_oidc_coverage_deadline($record);
    if ($coverageUntil > 0 && time() >= $coverageUntil) {
        wallos_oidc_mark_revalidation_required($db, $sessionId, 'backchannel_coverage_expired');

        return WALLOS_OIDC_REVALIDATION_REQUIRED;
    }

    // Inside coverage: the proactive refresh may run. It costs no query when
    // nothing is due (the next-attempt moment is cached in the PHP session). A
    // definitive rejection ends the session; every other outcome — a success, a
    // transient failure, nothing due — leaves it valid, because a refresh never
    // creates authority, it only maintains what the boundary already grants.
    $maintenance = wallos_oidc_maintain_access_token($db, $sessionId);
    if ($maintenance['action'] === 'revoked') {
        return WALLOS_OIDC_REVOKED;
    }

    return WALLOS_OIDC_VALID;
}

/**
 * Whether the current session may proceed with protected work.
 *
 * The boolean the HTML page bootstrap still asks for: valid means proceed,
 * anything else means refuse. Kept as a thin reading of the state machine so
 * that a caller wanting the reason (to answer 401 vs 503, say) reaches for
 * wallos_oidc_session_authority() instead.
 *
 * @param WallosDatabase $db
 * @return bool
 */
function wallos_oidc_current_session_is_valid($db)
{
    return wallos_oidc_session_authority($db) === WALLOS_OIDC_VALID;
}

/**
 * Reads the authority columns for one PHP session.
 *
 * Three answers, and the difference between two of them is the whole of §17:
 *   - an array: the row, to be read by the state machine;
 *   - null: no such row — the session was revoked;
 *   - false: the read itself failed. The caller must NOT treat this as "no row"
 *     and must NOT fail open; with the schema present it means the store is
 *     unreadable and the session is suspended.
 *
 * The two backends fail a broken read at different moments — the file-backed one
 * refuses to prepare, PostgreSQL prepares and throws at execute — so both are
 * turned into the same false here rather than one being read as success.
 *
 * No type constants and no SQLITE3_ fetch mode: the database boundary infers the
 * binding, and fetchArray() without a mode returns a row addressable by column
 * name on both backends. Keeping this file free of SQLite-specific tokens is
 * also what keeps it inside the db-audit boundary.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @return array|null|false
 */
function wallos_oidc_load_authority_record($db, $sessionId)
{
    $stmt = $db->prepare('SELECT id, user_id, status, backchannel_coverage_until,
                                 access_token_expires_at, revoked_at, revocation_reason
                            FROM oidc_sessions
                           WHERE session_id = :sessionId LIMIT 1');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':sessionId', $sessionId);
    $result = $stmt->execute();
    if ($result === false) {
        return false;
    }

    $row = $result->fetchArray();

    return $row === false ? null : $row;
}

/**
 * The moment past which back-channel coverage can no longer be relied on.
 *
 * It is `backchannel_coverage_until`, which login and every successful refresh
 * keep equal to the access token's own expiry: while a live access token exists
 * the provider can still build a logout notification from it. The fallback to
 * `access_token_expires_at` covers a row written before the coverage column
 * existed. Zero means no boundary is known — a session with no access-token
 * timing — and reads as covered, the behaviour such a session had before v2.
 *
 * @param array $record
 * @return int unix timestamp, or 0 when there is no boundary to enforce
 */
function wallos_oidc_coverage_deadline($record)
{
    $coverage = (int) ($record['backchannel_coverage_until'] ?? 0);
    if ($coverage <= 0) {
        $coverage = (int) ($record['access_token_expires_at'] ?? 0);
    }

    return $coverage;
}

/**
 * Persists the transition into revalidation, so the ordering rule stays enforced
 * across requests: once a session is marked, the state machine returns
 * revalidation_required before it would ever reach a refresh.
 *
 * Only a currently-valid row is moved, so this never overwrites a more final
 * state. The row itself is kept — revalidation must persist it, where the old
 * code deleted a session the moment it stopped being trusted.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param string         $reason
 * @return void
 */
function wallos_oidc_mark_revalidation_required($db, $sessionId, $reason)
{
    if (!$db->columnExists('oidc_sessions', 'status')) {
        return;
    }

    $stmt = $db->prepare('UPDATE oidc_sessions
                             SET status = :required,
                                 revocation_reason = :reason
                           WHERE session_id = :sessionId AND status = :valid');
    if ($stmt === false) {
        error_log('Wallos OIDC: could not mark a session as needing revalidation: ' . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':required', WALLOS_OIDC_REVALIDATION_REQUIRED);
    $stmt->bindValue(':reason', $reason);
    $stmt->bindValue(':sessionId', $sessionId);
    $stmt->bindValue(':valid', WALLOS_OIDC_VALID);

    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: a session needing revalidation was not marked, so the guard will '
            . 'recompute it from the coverage boundary each request: ' . $db->lastErrorMsg());
    }
}

/**
 * The pre-v2 authority decision, used only during the narrow window where the
 * table exists but migration 000082 has not added the status column yet.
 *
 * It reproduces the old guard exactly — the session is valid while its row lives
 * and a refresh has not come back revoked — so an in-progress upgrade does not
 * sign every OIDC user out. It is deliberately NOT reached once the status
 * column is present: from then on the state machine above governs.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @return string
 */
function wallos_oidc_legacy_authority($db, $sessionId)
{
    if (!wallos_oidc_session_is_active($db, $sessionId)) {
        return WALLOS_OIDC_REVOKED;
    }

    $maintenance = wallos_oidc_maintain_access_token($db, $sessionId);

    return $maintenance['action'] === 'revoked' ? WALLOS_OIDC_REVOKED : WALLOS_OIDC_VALID;
}

/**
 * Ends the request unless the session may proceed, with a refusal shaped for the
 * caller.
 *
 * Endpoints answer JSON, so a refusal is JSON rather than a redirect to
 * logout.php — an AJAX caller handed an HTML page would report a parse error and
 * leave the user staring at an interface that no longer works. The mutation an
 * endpoint would run never executes: this is called from the bootstrap, before
 * the endpoint body, and it exits.
 *
 *   - revoked: 401, and the local session and remember-me cookie are cleared.
 *   - revalidation_required: 401 carrying the #159 contract —
 *     {success:false, code:"oidc_revalidation_required", revalidation_url:...}.
 *     The authority row and cookie are left in place so the browser round-trip
 *     the URL points at can find them. The centralized JS handler (WP9) reads the
 *     code and navigates to the URL; a mutating request never reaches its own
 *     body, because this exits from the bootstrap before it, and the client MUST
 *     NOT auto-replay it (§21).
 *   - suspended: 503, and NOTHING is cleared — the state is kept so the next
 *     request can retry once the store or provider is reachable again.
 *
 * @param WallosDatabase $db
 * @return void
 */
function wallos_oidc_require_valid_session($db)
{
    $state = wallos_oidc_session_authority($db);

    if ($state === WALLOS_OIDC_VALID) {
        return;
    }

    if ($state === WALLOS_OIDC_SUSPENDED) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Retry-After: 30');
        echo json_encode([
            'success' => false,
            'message' => 'Authentication state is temporarily unavailable. Please retry.',
        ]);
        exit();
    }

    // Needs revalidation: refuse this request, but keep the row and the cookie —
    // the browser can prove the provider session still exists with a silent
    // prompt=none round-trip rather than logging in from scratch. The JSON names
    // the code the one JS handler switches on and the URL it navigates to (#159).
    if ($state === WALLOS_OIDC_REVALIDATION_REQUIRED) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'code' => 'oidc_revalidation_required',
            'revalidation_url' => wallos_oidc_revalidation_url(),
            'message' => 'Your session needs to be re-confirmed by the identity provider.',
        ]);
        exit();
    }

    // Revoked: cleared locally, definitively ended.
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

/**
 * The URL the browser is sent to in order to revalidate silently: the resume
 * entry point with a validated local return target.
 *
 * A relative path (no leading slash) so it resolves under a sub-path deployment:
 * the JS handler assigns it relative to the current page, and an HTML redirect
 * resolves it against the current URL. The return target is taken from the
 * Referer for an XHR (the page that made the call) and reduced to a same-origin
 * path, so it can never become an open redirect.
 *
 * @param string|null $returnTo an explicit return target, or null to derive one
 * @return string
 */
function wallos_oidc_revalidation_url($returnTo = null)
{
    if ($returnTo === null) {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $returnTo = wallos_oidc_return_to_from_referer($referer);
    }

    return 'oidc/revalidate.php?return_to=' . rawurlencode(wallos_oidc_sanitize_return_to($returnTo));
}

/**
 * Reduces a Referer to the same-origin path+query it names, or the app root.
 *
 * Only the path and query are kept, so the value is same-origin whatever host the
 * Referer carried — it is later used as a redirect on Wallos's own domain.
 *
 * @param string $referer
 * @return string
 */
function wallos_oidc_return_to_from_referer($referer)
{
    if (!is_string($referer) || $referer === '') {
        return 'index.php';
    }

    $path = parse_url($referer, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return 'index.php';
    }

    $query = parse_url($referer, PHP_URL_QUERY);
    $candidate = $path . (is_string($query) && $query !== '' ? '?' . $query : '');

    return wallos_oidc_sanitize_return_to($candidate);
}

/**
 * The HTML-page counterpart of the guard: the same authority result, presented
 * as a redirect or a page rather than JSON.
 *
 * Called from the page bootstrap only after wallos_oidc_current_session_is_valid()
 * has already said the session is NOT valid, so it resolves the precise state to
 * present it correctly:
 *   - revalidation_required → redirect to the silent revalidation round-trip,
 *     with the current page as the return target;
 *   - suspended → a 503 "temporarily unavailable" page, nothing cleared;
 *   - revoked (or anything else) → the ordinary logout redirect.
 *
 * Re-resolving the state here is safe: none of the non-valid states reach the
 * proactive refresh, so it does no second token work.
 *
 * @param WallosDatabase $db
 * @return void
 */
function wallos_oidc_gate_html_response($db)
{
    $state = wallos_oidc_session_authority($db);

    if ($state === WALLOS_OIDC_REVALIDATION_REQUIRED) {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : 'index.php';
        header('Location: ' . wallos_oidc_revalidation_url($requestUri));
        exit();
    }

    if ($state === WALLOS_OIDC_SUSPENDED) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: 30');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Wallos</title></head>'
            . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;">'
            . '<h1>Temporarily unavailable</h1>'
            . '<p>Your authentication provider could not be reached. Your session has '
            . 'not been ended &mdash; please try again in a moment.</p></body></html>';
        exit();
    }

    // Revoked, or a state that leaves nothing to serve: the ordinary logout.
    header('Location: logout.php');
    exit();
}
