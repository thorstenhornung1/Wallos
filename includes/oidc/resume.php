<?php

/**
 * Silent Resume Guard — the authoritative recovery path (OIDC Session Authority
 * v2, WP5 / §8, §13, §22, §23).
 *
 * When the session guard resolves REVALIDATION_REQUIRED it does not force an
 * interactive re-login. Instead the browser makes a silent Authorization Code
 * round-trip: Wallos → provider `/authorize` with `prompt=none` and the previous
 * ID token as `id_token_hint` → back to the same redirect URI. The round-trip is
 * in the BROWSER because the provider's session lives in the browser's cookies at
 * the provider; a server-side refresh can never prove those still exist (which is
 * the whole of the #144 defect).
 *
 * Two things make this safe rather than a second way in:
 *
 *   - it re-establishes the EXISTING identity only. A successful prompt=none for
 *     the same (iss, sub) clears the flag; a different subject or issuer is
 *     refused outright and never switches accounts (§13, test K);
 *
 *   - a provider that CANNOT authenticate silently (login_required and its
 *     siblings) means the session must not continue — interactive login — while a
 *     provider that is merely unreachable is SUSPENDED and retried, never read as
 *     revocation (§22, test L).
 *
 * The full ID-token validator (signature/aud/exp/azp) is Phase 3 (WP2). Phase 2
 * parses the ID token and enforces the two checks resume turns on: the exact
 * `nonce` for this transaction (test C) and the (iss, sub) binding.
 */

require_once __DIR__ . '/pkce.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/refresh.php';
require_once __DIR__ . '/backchannel.php';
require_once __DIR__ . '/../ssrf_helper.php';

/**
 * The authorization URL for a resume transaction: response_type=code with
 * prompt=none, a fresh state/nonce/PKCE challenge, and the previous ID token as
 * id_token_hint when one is held.
 *
 * The redirect_uri is the SAME one login uses and the provider has registered —
 * the resume callback lands on the same page an ordinary login callback does, and
 * consume_oidc_callback.php tells them apart by the transaction's mode.
 *
 * @param array       $oidcSettings the effective OIDC settings
 * @param array       $transaction  a resume transaction
 * @param string|null $idTokenHint  the previously validated ID token, if held
 * @return string
 */
function wallos_oidc_build_resume_authorize_url($oidcSettings, $transaction, $idTokenHint)
{
    $params = [
        'response_type' => 'code',
        'client_id' => $oidcSettings['client_id'] ?? '',
        'redirect_uri' => $oidcSettings['redirect_url'] ?? '',
        'scope' => wallos_oidc_authorization_scopes($oidcSettings['scopes'] ?? ''),
        'state' => $transaction['state'],
        'nonce' => $transaction['nonce'],
        // The one thing that makes this the recovery path: the provider is asked
        // to authenticate the browser WITHOUT any interaction, and to say
        // login_required rather than show a login form if it cannot.
        'prompt' => 'none',
        'code_challenge' => wallos_oidc_code_challenge($transaction['pkce_verifier']),
        'code_challenge_method' => 'S256',
    ];

    // SHOULD, per §8: it tells the provider which session to check, and some
    // providers refuse prompt=none without it. Absent when the row carried none.
    if (is_string($idTokenHint) && $idTokenHint !== '') {
        $params['id_token_hint'] = $idTokenHint;
    }

    return rtrim((string) ($oidcSettings['authorization_url'] ?? ''), '?') . '?' . http_build_query($params);
}

/**
 * How a prompt=none provider error is treated (§22).
 *
 *   'interactive' — the provider could not authenticate silently
 *                   (login_required, interaction_required, consent_required,
 *                   account_selection_required, or any other definitive refusal).
 *                   The local session must not continue; the user logs in
 *                   interactively.
 *   'suspended'   — the provider is temporarily unavailable
 *                   (temporarily_unavailable, server_error). This is NOT
 *                   revocation: keep the local state and retry.
 *
 * @param mixed $error the `error` query parameter the provider returned
 * @return string 'interactive' or 'suspended'
 */
function wallos_oidc_classify_prompt_none_error($error)
{
    $error = is_string($error) ? strtolower(trim($error)) : '';

    if ($error === 'temporarily_unavailable' || $error === 'server_error') {
        return 'suspended';
    }

    return 'interactive';
}

/**
 * The identity behind the session a resume is re-establishing: the account and
 * its immutable provider subject.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @return array{user_id:int, oidc_sub:string}|null
 */
function wallos_oidc_resume_existing_identity($db, $sessionId)
{
    $stmt = $db->prepare('SELECT s.user_id, u.oidc_sub
                            FROM oidc_sessions s
                            JOIN "user" u ON u.id = s.user_id
                           WHERE s.session_id = :sessionId LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bindValue(':sessionId', $sessionId);
    $result = $stmt->execute();
    $row = $result === false ? false : $result->fetchArray();

    if ($row === false) {
        return null;
    }

    return [
        'user_id' => (int) $row['user_id'],
        'oidc_sub' => (string) ($row['oidc_sub'] ?? ''),
    ];
}

/**
 * Exchanges a resume authorization code and, when the returned identity matches
 * the existing session, re-establishes authority.
 *
 * Outcomes (the caller maps each to a redirect and, for the failing ones, to
 * ending the local session):
 *   'revalidated'      same (iss, sub): the row is moved back to VALID with a
 *                      fresh authority_confirmed_at and coverage, and §23 sync
 *                      re-runs. Access is restored.
 *   'account_mismatch' a different subject or issuer: the session is REVOKED
 *                      (§13) and must never inherit the other account.
 *   'nonce_mismatch'   the ID token's nonce did not match this transaction: the
 *                      session is ended and refused (test C).
 *   'failed'           a definitive exchange or token failure: interactive login.
 *   'suspended'        the provider/token endpoint was unreachable: keep the
 *                      local state and retry (test L). Nothing is revoked.
 *
 * @param WallosDatabase $db
 * @param array          $oidcSettings
 * @param array          $transaction  the consumed resume transaction
 * @param string         $code         the authorization code
 * @param string         $sessionId    the PHP session id the resume targets
 * @param int|null       $now
 * @return array{outcome:string, reason:?string, user_id?:int}
 */
function wallos_oidc_resume_exchange_and_confirm($db, $oidcSettings, $transaction, $code, $sessionId, $now = null)
{
    $now = $now === null ? time() : (int) $now;

    $tokenUrl = trim((string) ($oidcSettings['token_url'] ?? ''));
    if ($tokenUrl === '') {
        return ['outcome' => 'suspended', 'reason' => 'no_token_endpoint'];
    }

    // The same SSRF check the login-time and refresh exchanges make; the endpoint
    // is operator- or discovery-supplied, so it is validated before every call.
    $tokenUrlInfo = validate_oidc_endpoint_url($tokenUrl, $db);
    if ($tokenUrlInfo === false) {
        return ['outcome' => 'suspended', 'reason' => 'token_endpoint_refused'];
    }

    $fields = wallos_oidc_token_request_fields(
        $oidcSettings,
        $code,
        (string) ($oidcSettings['redirect_url'] ?? ''),
        $transaction['pkce_verifier'] ?? null
    );

    // Through the shared seam refresh.php defines, so a test stands in for the
    // provider without a socket.
    $response = wallos_oidc_token_endpoint_post(
        $tokenUrl,
        $fields,
        $tokenUrlInfo['host'] . ':' . $tokenUrlInfo['port'] . ':' . implode(',', $tokenUrlInfo['ips'])
    );

    $tokens = is_string($response['body']) ? json_decode($response['body'], true) : null;

    if (!is_array($tokens) || !isset($tokens['access_token']) || !is_string($tokens['access_token'])) {
        $providerError = is_array($tokens) && isset($tokens['error']) && is_string($tokens['error'])
            ? $tokens['error']
            : '';

        // No usable body and a transport error or a 5xx/0 status is the provider
        // being unreachable, not it rejecting the session — SUSPENDED, retry.
        if ($providerError === ''
            && ($response['error'] !== null || $response['status'] >= 500 || $response['status'] === 0)) {
            return ['outcome' => 'suspended', 'reason' => 'transport_error'];
        }

        // A provider error body on the code exchange after a silent authorize is
        // a definitive "cannot complete" — interactive login.
        return ['outcome' => 'failed', 'reason' => $providerError !== '' ? $providerError : 'http_' . $response['status']];
    }

    $idToken = isset($tokens['id_token']) && is_string($tokens['id_token']) ? $tokens['id_token'] : '';
    $parsed = $idToken !== '' ? wallos_jwt_parse($idToken) : null;
    if ($parsed === null || !isset($parsed['payload']) || !is_array($parsed['payload'])) {
        // The ID token is the authentication assertion; without one there is
        // nothing to bind the identity to. Interactive login.
        return ['outcome' => 'failed', 'reason' => 'no_id_token'];
    }
    $claims = $parsed['payload'];

    // The nonce MUST be the one this transaction sent (test C). A token minted for
    // any other request — replayed, injected — fails here and never re-validates.
    $nonce = isset($claims['nonce']) && is_string($claims['nonce']) ? $claims['nonce'] : '';
    if ($nonce === '' || !hash_equals((string) ($transaction['nonce'] ?? ''), $nonce)) {
        wallos_oidc_terminate_session($db, $sessionId);

        return ['outcome' => 'nonce_mismatch', 'reason' => 'nonce'];
    }

    $subject = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : '';
    if ($subject === '') {
        wallos_oidc_terminate_session($db, $sessionId);

        return ['outcome' => 'failed', 'reason' => 'no_subject'];
    }
    $issuer = isset($claims['iss']) && is_string($claims['iss']) ? $claims['iss'] : '';

    $identity = wallos_oidc_resume_existing_identity($db, $sessionId);
    if ($identity === null) {
        // The session row is gone — a back-channel logout reached it first. There
        // is nothing to re-establish; the session is already ended.
        return ['outcome' => 'account_mismatch', 'reason' => 'no_session'];
    }

    // The (iss, sub) binding (§13). A configured issuer must match; without one
    // (manual configuration, no discovery) the subject alone is the immutable key
    // Phase 2 can check — which is exactly what test K turns on.
    $configuredIssuer = trim((string) ($oidcSettings['issuer'] ?? ''));
    if ($configuredIssuer !== '' && $issuer !== ''
        && rtrim($issuer, '/') !== rtrim($configuredIssuer, '/')) {
        wallos_oidc_terminate_session($db, $sessionId);

        return ['outcome' => 'account_mismatch', 'reason' => 'issuer'];
    }
    if (!hash_equals($identity['oidc_sub'], $subject)) {
        // A different account is active in the browser at the provider. Never
        // switch the local session to it — end this one and log in normally.
        wallos_oidc_terminate_session($db, $sessionId);

        return ['outcome' => 'account_mismatch', 'reason' => 'subject'];
    }

    // Same (iss, sub): re-establish authority. The row moves back to VALID with a
    // fresh confirmation moment and (via the recorded token) a fresh coverage
    // boundary, so the guard admits it again and stays inside coverage.
    $newSid = isset($claims['sid']) && is_string($claims['sid']) && $claims['sid'] !== ''
        ? (string) $claims['sid']
        : null;
    wallos_oidc_confirm_resume($db, $sessionId, $newSid, $now);
    wallos_oidc_record_access_token($db, $sessionId, $tokens, $now);
    if ($idToken !== '') {
        wallos_oidc_store_resume_id_token($db, $sessionId, $idToken);
    }

    // §23: a revalidation MUST NOT leave stale provider-derived authorization.
    // The ID token is the assertion here, so its claims drive the re-sync.
    wallos_oidc_resume_resync($db, $identity['user_id'], $oidcSettings, $claims);

    return ['outcome' => 'revalidated', 'reason' => null, 'user_id' => $identity['user_id']];
}

/**
 * Moves a session's authority row back to VALID after a successful resume, with a
 * fresh authority_confirmed_at and, when the ID token carried one, the new sid.
 *
 * Guarded on the status column so an install still mid-migration keeps working.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param string|null    $newSid
 * @param int            $now
 * @return void
 */
function wallos_oidc_confirm_resume($db, $sessionId, $newSid, $now)
{
    if (!$db->columnExists('oidc_sessions', 'status')) {
        return;
    }

    $sidClause = $newSid !== null ? ', sid = :sid' : '';
    $stmt = $db->prepare('UPDATE oidc_sessions
                             SET status = :valid,
                                 authority_confirmed_at = :now,
                                 revocation_reason = \'\'' . $sidClause . '
                           WHERE session_id = :sessionId');
    if ($stmt === false) {
        error_log('Wallos OIDC: a resumed session could not be moved back to valid: '
            . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':valid', 'valid');
    $stmt->bindValue(':now', (int) $now);
    if ($newSid !== null) {
        $stmt->bindValue(':sid', $newSid);
    }
    $stmt->bindValue(':sessionId', $sessionId);

    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: a resumed session was not confirmed valid, so the next request '
            . 'will require revalidation again: ' . $db->lastErrorMsg());
    }
}

/**
 * Stores the ID token a resume returned, so the next logout has an id_token_hint.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param string         $idToken
 * @return void
 */
function wallos_oidc_store_resume_id_token($db, $sessionId, $idToken)
{
    if (!$db->columnExists('oidc_sessions', 'id_token')) {
        return;
    }

    $stmt = $db->prepare('UPDATE oidc_sessions SET id_token = :idToken WHERE session_id = :sessionId');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':idToken', $idToken);
    $stmt->bindValue(':sessionId', $sessionId);
    if ($stmt->execute() === false) {
        // Not fatal: authority is already re-established. What is lost is the
        // id_token_hint for the next logout, which is worth a line, not a failure.
        error_log('Wallos OIDC: could not store the resumed ID token for the next logout hint: '
            . $db->lastErrorMsg());
    }
}

/**
 * Moves the authority row onto a new PHP session id, after the request has
 * regenerated it (session fixation, §15/§T). Mirrors the remember-me restore.
 *
 * @param WallosDatabase $db
 * @param string         $oldSessionId
 * @param string         $newSessionId
 * @return void
 */
function wallos_oidc_move_session_row($db, $oldSessionId, $newSessionId)
{
    if ($oldSessionId === $newSessionId) {
        return;
    }

    $stmt = $db->prepare('UPDATE oidc_sessions SET session_id = :new WHERE session_id = :old');
    if ($stmt === false) {
        error_log('Wallos OIDC: a resumed session id could not be moved onto the regenerated '
            . 'session, so revocation would target a stale id: ' . $db->lastErrorMsg());

        return;
    }
    $stmt->bindValue(':new', $newSessionId);
    $stmt->bindValue(':old', $oldSessionId);
    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: could not move a resumed session id onto the regenerated session, '
            . 'so revocation would target a stale id: ' . $db->lastErrorMsg());
    }
}

/**
 * Re-runs the provider-managed profile and role synchronisation after a resume
 * (§23), so a revalidation never leaves stale provider-derived authorization.
 *
 * The claim source is the ID token, the assertion the resume validated. Local-only
 * roles are untouched — the admin sync writes only the `oidc` source.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param array          $oidcSettings
 * @param array          $claims        the ID token claims
 * @return void
 */
function wallos_oidc_resume_resync($db, $userId, $oidcSettings, $claims)
{
    require_once __DIR__ . '/oidc_profile_sync.php';
    require_once __DIR__ . '/admin_role_sync.php';

    $stmt = $db->prepare('SELECT * FROM "user" WHERE id = :id LIMIT 1');
    if ($stmt !== false) {
        $stmt->bindValue(':id', (int) $userId);
        $result = $stmt->execute();
        // fetchArray() without a mode returns a column-name-addressable row on
        // both backends; no backend-specific fetch constant, which keeps this
        // file inside the db-audit boundary (like session_guard.php).
        $userData = $result === false ? false : $result->fetchArray();
        if (is_array($userData)) {
            wallos_oidc_maybe_update_profile($db, $userData, $claims, $oidcSettings);
        }
    }

    wallos_sync_oidc_admin_role($db, (int) $userId, $claims, $oidcSettings);
}
