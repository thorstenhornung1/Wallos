<?php

/**
 * Keeping a live access token for as long as the Wallos session lives.
 *
 * Issue #144. Back-channel logout worked for about five minutes after a login
 * and then went silent, on the same provider, the same configuration and the
 * same code. Measured on the test instance: within five minutes of signing in,
 * deleting the session at the provider produced the whole chain and signed the
 * browser out; seventy-two minutes later the same action deleted the session,
 * sent nothing, and left Wallos with a row it would keep honouring for thirty
 * days. Nothing failed — nothing was sent.
 *
 * The reason is that authentik builds the notification for a deleted session
 * from the AccessToken rows belonging to it, and Wallos never obtained a
 * refresh token: no `offline_access` in the authorization request, no second
 * word with the token endpoint after login. One access token, five minutes, and
 * then a session nobody could reach.
 *
 * So this file does three things: ask for the credential
 * (wallos_oidc_authorization_scopes), remember it with the session
 * (wallos_oidc_record_access_token), and spend it before the access token dies
 * (wallos_oidc_maintain_access_token).
 *
 * THE ASSUMPTION THE WHOLE FIX RESTS ON, stated here because this is where a
 * reader will look for it: a refreshed access token stays bound to the same
 * provider session, so the receiver that iterates over live access tokens for
 * that session finds the new one. This was confirmed by reading authentik's
 * refresh-token grant, where the new access token takes its session straight
 * from the refresh token it was issued against (`AccessToken(…,
 * session=self.params.refresh_token.session)` in providers/oauth2/views/token.py,
 * and the rotated refresh token carries the same). The reservation is that the
 * source was read on `main`, not on the 2026.8.1 tag the test instance runs, so
 * it is confirmed by reading and not yet by measurement. docs/test-instance.md
 * section 7.4 names the measurement that settles it; until that run exists, no
 * end-to-end claim should be made here.
 *
 * A related correction to the issue's own diagnosis, which is why the timing
 * below leaves room: the receiver's filter has no expiry condition. An expired
 * access token still appears in the list until the provider's cleanup task
 * removes the row, so the real window is the token's validity plus however long
 * the cleanup takes to run — which is not observable from here and must not be
 * reasoned about. A genuinely live token makes the cleanup schedule irrelevant,
 * which is the entire point of refreshing early rather than late.
 */

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/../oidc_settings.php';
require_once __DIR__ . '/../ssrf_helper.php';

if (!function_exists('wallos_oidc_token_endpoint_post')) {
    /**
     * The one network touch of the refresh path, separated so a test can stand
     * in for the identity provider without a socket.
     *
     * The same shape and the same reasoning as wallos_provider_http_get() in
     * includes/currency_provider.php: no test in this suite makes a request, and
     * the one that proved it the expensive way spent half a year of a free tier
     * (#104). A test defines its own version before this file is loaded; the
     * guard lets that stand.
     *
     * @param string      $url
     * @param array       $fields  form fields, one of which is a credential —
     *                             this is the only place they may appear
     * @param string|null $resolve a curl RESOLVE entry pinning the host to the
     *                             addresses the SSRF check already approved
     * @return array{body: string|false, status: int, error: string|null}
     */
    function wallos_oidc_token_endpoint_post($url, $fields, $resolve = null)
    {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);

        if (is_string($resolve) && $resolve !== '') {
            curl_setopt($handle, CURLOPT_RESOLVE, [$resolve]);
        }

        $body = curl_exec($handle);
        $error = curl_errno($handle) ? curl_error($handle) : null;
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['body' => $body, 'status' => $status, 'error' => $error];
    }
}

/**
 * The scopes the authorization request asks for.
 *
 * `offline_access` is added to whatever the operator configured, because
 * without it the provider hands over an access token and nothing to replace it
 * with — which is the defect, not a preference. The configured list stays what
 * the operator wrote; this says what Wallos needs on top of it.
 *
 * An empty list is left empty: that means "let the provider apply its default
 * scopes", and turning it into a request for one scope would be a different
 * instruction than the one that was given.
 *
 * @param string|null $configured
 * @return string
 */
function wallos_oidc_authorization_scopes($configured)
{
    $trimmed = trim((string) $configured);
    if ($trimmed === '') {
        return '';
    }

    $scopes = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
    if ($scopes === false) {
        return $trimmed;
    }

    if (!in_array('offline_access', $scopes, true)) {
        $scopes[] = 'offline_access';
    }

    return implode(' ', $scopes);
}

/**
 * When the access token in a token response was issued, and when it dies.
 *
 * Read from the response first, because `expires_in` is what every provider
 * sends and it needs no interpretation. Failing that, from the access token's
 * own `exp` when the provider issues a signed one. Failing both, the expiry is
 * unknown and reported as 0 — the caller then schedules nothing rather than
 * inventing an interval, because an interval invented here would be wrong on
 * the next installation.
 *
 * @param array $tokenData the parsed token response
 * @param int   $now
 * @return array{issued_at: int, expires_at: int}
 */
function wallos_oidc_access_token_validity($tokenData, $now)
{
    $issuedAt = (int) $now;

    if (isset($tokenData['expires_in']) && is_numeric($tokenData['expires_in'])
        && (int) $tokenData['expires_in'] > 0) {
        return ['issued_at' => $issuedAt, 'expires_at' => $issuedAt + (int) $tokenData['expires_in']];
    }

    if (isset($tokenData['access_token']) && is_string($tokenData['access_token'])) {
        $parsed = wallos_jwt_parse($tokenData['access_token']);

        // Not verified, and deliberately so: this is our own access token read
        // for its expiry, not a security decision about somebody else's claims.
        if ($parsed !== null && isset($parsed['payload']['exp']) && is_int($parsed['payload']['exp'])) {
            if (isset($parsed['payload']['iat']) && is_int($parsed['payload']['iat'])) {
                $issuedAt = (int) $parsed['payload']['iat'];
            }

            return ['issued_at' => $issuedAt, 'expires_at' => (int) $parsed['payload']['exp']];
        }
    }

    return ['issued_at' => $issuedAt, 'expires_at' => 0];
}

/**
 * The moment the access token should be replaced.
 *
 * Halfway through its own life, and that fraction is the only number here.
 * A provider issuing five-minute tokens is refreshed after two and a half
 * minutes, one issuing hour-long tokens after thirty — nothing has to be
 * configured and nothing breaks when a provider's validity changes.
 *
 * The margin is half rather than a few seconds before expiry on purpose. What
 * makes the notification arrive is a live token at the provider, and the moment
 * it stops being live depends on a cleanup task on the provider's side that
 * Wallos cannot see. Refreshing at the last second would win or lose that race
 * for reasons nobody could debug; refreshing halfway through removes the race
 * instead of timing it.
 *
 * @param int $issuedAt
 * @param int $expiresAt
 * @return int unix timestamp, or 0 when there is nothing to schedule from
 */
function wallos_oidc_refresh_due_at($issuedAt, $expiresAt)
{
    $issuedAt = (int) $issuedAt;
    $expiresAt = (int) $expiresAt;

    if ($expiresAt <= 0) {
        return 0;
    }

    // A row written before the timings existed, or a token claiming to have
    // been issued at the epoch or after it expires: the lifetime cannot be
    // derived from that, and half of a nonsense interval is still nonsense. The
    // answer is "now", after which the replacement token records timings that
    // do make sense. 1 rather than 0, because 0 above already means "nothing
    // can be scheduled at all".
    if ($issuedAt <= 0 || $expiresAt <= $issuedAt) {
        return 1;
    }

    return $expiresAt - intdiv($expiresAt - $issuedAt, 2);
}

/**
 * When a refresh may be attempted again after one has failed.
 *
 * One token lifetime after the failure. Derived from the same two facts as the
 * refresh moment, for the same reason: an unreachable provider must not be
 * asked once per request — every page load would wait on a timeout — and a
 * provider that is briefly unwell must be asked again soon enough that the
 * session becomes reachable again by itself.
 *
 * @param int $issuedAt
 * @param int $expiresAt
 * @param int $failedAt
 * @return int
 */
function wallos_oidc_retry_due_at($issuedAt, $expiresAt, $failedAt)
{
    $lifetime = (int) $expiresAt - (int) $issuedAt;

    return (int) $failedAt + max(1, $lifetime);
}

/**
 * Whether this installation has the columns the refresh needs.
 *
 * Asked explicitly rather than inferred from a failed statement, because the
 * two backends fail at different moments — the file-backed one refuses to
 * prepare against a missing column, PostgreSQL prepares happily and fails at
 * execute. An installation whose migration has not run yet keeps working
 * exactly as it did before, which is the same rule the session check follows.
 *
 * @param WallosDatabase $db
 * @return bool
 */
function wallos_oidc_refresh_is_supported($db)
{
    return $db->tableExists('oidc_sessions') && $db->columnExists('oidc_sessions', 'refresh_token');
}

/**
 * Records the access token state of a session: the credential that can replace
 * the token, and when the current one was issued and expires.
 *
 * Called at login with the token response the provider sent, and after every
 * refresh with the new one. A successful write also clears any recorded
 * failure, because the session is reachable again and saying otherwise would be
 * a stale alarm.
 *
 * The refresh token itself never leaves this row: not into the PHP session, not
 * into a cookie, not into a log line, not into any response. The row is deleted
 * at logout and at back-channel revocation, which is what bounds the
 * credential's life to the session's.
 *
 * The bindings carry no type constants. The database boundary infers them, and
 * both backends are reached through the same call.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId the PHP session id
 * @param array          $tokenData the parsed token response
 * @param int            $now
 * @return bool whether the state was recorded
 */
function wallos_oidc_record_access_token($db, $sessionId, $tokenData, $now)
{
    if (!wallos_oidc_refresh_is_supported($db)) {
        return false;
    }

    $validity = wallos_oidc_access_token_validity($tokenData, $now);
    $refreshToken = isset($tokenData['refresh_token']) && is_string($tokenData['refresh_token'])
        ? $tokenData['refresh_token']
        : '';

    $stmt = $db->prepare('UPDATE oidc_sessions
                             SET refresh_token = :refreshToken,
                                 access_token_issued_at = :issuedAt,
                                 access_token_expires_at = :expiresAt,
                                 refresh_failed_at = 0,
                                 refresh_error = \'\'
                           WHERE session_id = :sessionId');
    if ($stmt === false) {
        error_log('Wallos OIDC: could not record the access token state, so this session cannot be '
            . 'kept reachable by back-channel logout: ' . $db->lastErrorMsg());

        return false;
    }

    $stmt->bindValue(':refreshToken', $refreshToken);
    $stmt->bindValue(':issuedAt', $validity['issued_at']);
    $stmt->bindValue(':expiresAt', $validity['expires_at']);
    $stmt->bindValue(':sessionId', $sessionId);

    // Read, because a session whose refresh token was not stored is one that
    // becomes unreachable the moment its access token expires — which is the
    // defect this file exists for, and it would arrive silently.
    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: the refresh token was not stored with the session, so the identity '
            . 'provider will stop being able to end it once the access token expires: '
            . $db->lastErrorMsg());

        return false;
    }

    return true;
}

/**
 * Records that a refresh failed.
 *
 * Deliberately not a logout. A provider having a bad minute would otherwise
 * sign out every session that happened to be due, which turns an outage into a
 * logout storm — and the user has done nothing wrong. What is lost is remote
 * revocability, so that is what gets written down: the row now says when the
 * refresh failed and why, and a session carrying that is one the provider can
 * no longer end from the outside.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param int            $now
 * @param string         $reason
 * @param bool           $forget    the provider says the credential is dead, so
 *                                  it is removed rather than kept
 * @return bool
 */
function wallos_oidc_record_refresh_failure($db, $sessionId, $now, $reason, $forget = false)
{
    if (!wallos_oidc_refresh_is_supported($db)) {
        return false;
    }

    // A credential the provider has rejected as invalid is not worth keeping:
    // it will never work again, and a stored secret that cannot be spent is
    // only a liability. Kept for every other kind of failure, which may well be
    // temporary.
    $sql = 'UPDATE oidc_sessions SET refresh_failed_at = :now, refresh_error = :reason'
        . ($forget ? ', refresh_token = \'\'' : '')
        . ' WHERE session_id = :sessionId';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        error_log('Wallos OIDC: could not record a failed token refresh: ' . $db->lastErrorMsg());

        return false;
    }

    $stmt->bindValue(':now', (int) $now);
    $stmt->bindValue(':reason', (string) $reason);
    $stmt->bindValue(':sessionId', $sessionId);

    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: a token refresh failed and the failure could not be recorded either, '
            . 'so nothing marks this session as no longer revocable: ' . $db->lastErrorMsg());

        return false;
    }

    return true;
}

/**
 * Asks the provider for a new access token.
 *
 * @param WallosDatabase $db
 * @param array          $settings
 * @param string         $refreshToken
 * @return array{success: bool, error: string|null, definitive: bool, tokens: array|null}
 */
function wallos_oidc_request_refreshed_token($db, $settings, $refreshToken)
{
    $failure = function ($error, $definitive = false) {
        return ['success' => false, 'error' => $error, 'definitive' => $definitive, 'tokens' => null];
    };

    $tokenUrl = trim((string) ($settings['token_url'] ?? ''));
    if ($tokenUrl === '') {
        return $failure('no_token_endpoint');
    }

    // The same SSRF check the login-time exchange makes, and for the same
    // reason: the endpoint comes from configuration or from a discovery
    // document, neither of which Wallos controls.
    $tokenUrlInfo = validate_oidc_endpoint_url($tokenUrl, $db);
    if ($tokenUrlInfo === false) {
        return $failure('token_endpoint_refused');
    }

    $fields = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $settings['client_id'] ?? '',
    ];

    // A public client has no secret, and sending an empty client_secret is not
    // the same as not sending one: strict providers read the empty value as a
    // failed client authentication. The same rule the login exchange follows.
    if ((string) ($settings['client_secret'] ?? '') !== '') {
        $fields['client_secret'] = $settings['client_secret'];
    }

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

        if ($providerError !== '') {
            // invalid_grant is the provider saying this credential is gone —
            // expired, revoked, or belonging to a session that no longer
            // exists. It is the only answer here that will never change.
            return $failure($providerError, $providerError === 'invalid_grant');
        }

        return $failure($response['error'] !== null ? 'transport_error' : 'http_' . $response['status']);
    }

    return ['success' => true, 'error' => null, 'definitive' => false, 'tokens' => $tokens];
}

/**
 * Refreshes this session's access token when it is time, and only then.
 *
 * WHERE THE REFRESH RUNS, and why here rather than in a cron job:
 *
 * On the user's own next request. A cron job walking every stored session would
 * hold tokens alive for accounts nobody is using — which costs the provider a
 * request per session per token lifetime, and, worse, tells it that an
 * abandoned session is active, defeating the provider's own inactivity
 * timeouts. Refreshing on a request ties the token's life to the session's
 * actual use, costs nothing when nobody is signed in, and needs no scheduling
 * to be correct.
 *
 * What that trades away, said plainly: a session left idle past its access
 * token's life is again unreachable until its next request, so a revocation
 * issued while it is idle can still be lost. The session becomes reachable
 * again the moment it is used — which is also the moment it starts to matter —
 * but this is not the same guarantee a cron job would give, and it should not
 * be described as if it were.
 *
 * It runs from the session guard, which is the one place every authenticated
 * request already passes through. A second call site is exactly how 112
 * endpoints once went unguarded while the suite stayed green.
 *
 * "Only then" is the other half. Steady state costs no query at all: the moment
 * of the next attempt is kept in the PHP session, so a request that is not due
 * returns without touching the database. When it is due, one row is read and,
 * at most, one request goes to the provider.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId the PHP session id
 * @param int|null       $now
 * @return array{action: string, error: string|null}
 *   action is one of: unsupported, no_session, no_refresh_token, no_expiry,
 *   not_due, backing_off, refreshed, failed
 */
function wallos_oidc_maintain_access_token($db, $sessionId, $now = null)
{
    $now = $now === null ? time() : (int) $now;
    $verdict = function ($action, $error = null) {
        return ['action' => $action, 'error' => $error];
    };

    // The cheap gate, and the reason this is not a per-request cost. Nothing
    // secret lives in it: a timestamp saying when it is next worth looking.
    if (isset($_SESSION['oidc_refresh_after']) && is_int($_SESSION['oidc_refresh_after'])
        && $now < $_SESSION['oidc_refresh_after']) {
        return $verdict('not_due');
    }

    if (!wallos_oidc_refresh_is_supported($db)) {
        return $verdict('unsupported');
    }

    $stmt = $db->prepare('SELECT id, user_id, refresh_token, access_token_issued_at,
                                 access_token_expires_at, refresh_failed_at
                            FROM oidc_sessions
                           WHERE session_id = :sessionId LIMIT 1');
    if ($stmt === false) {
        return $verdict('unsupported');
    }

    $stmt->bindValue(':sessionId', $sessionId);
    $result = $stmt->execute();
    $row = $result === false ? false : $result->fetchArray();

    if ($row === false) {
        // No row: either not an OIDC session, or one that has just been
        // revoked. Neither is this function's business.
        return $verdict('no_session');
    }

    $refreshToken = (string) ($row['refresh_token'] ?? '');
    $issuedAt = (int) ($row['access_token_issued_at'] ?? 0);
    $expiresAt = (int) ($row['access_token_expires_at'] ?? 0);
    $failedAt = (int) ($row['refresh_failed_at'] ?? 0);

    // A session that signed in before this existed, or a provider that granted
    // no offline_access. It keeps working; it simply cannot be kept reachable,
    // which is the state every session was in before #144. Remembered for the
    // rest of the session so the row is not re-read on every request — only a
    // new sign-in can change the answer.
    if ($refreshToken === '') {
        $_SESSION['oidc_refresh_after'] = PHP_INT_MAX;

        return $verdict('no_refresh_token');
    }

    $dueAt = wallos_oidc_refresh_due_at($issuedAt, $expiresAt);
    if ($dueAt <= 0) {
        $_SESSION['oidc_refresh_after'] = PHP_INT_MAX;

        return $verdict('no_expiry');
    }

    if ($failedAt > 0) {
        $retryAt = wallos_oidc_retry_due_at($issuedAt, $expiresAt, $failedAt);
        if ($now < $retryAt) {
            $_SESSION['oidc_refresh_after'] = $retryAt;

            return $verdict('backing_off');
        }
    }

    if ($now < $dueAt) {
        $_SESSION['oidc_refresh_after'] = $dueAt;

        return $verdict('not_due');
    }

    $configuration = wallos_get_effective_oidc_configuration($db);
    $outcome = wallos_oidc_request_refreshed_token($db, $configuration['settings'], $refreshToken);

    if (!$outcome['success']) {
        wallos_oidc_record_refresh_failure($db, $sessionId, $now, $outcome['error'], $outcome['definitive']);
        $_SESSION['oidc_refresh_after'] = wallos_oidc_retry_due_at($issuedAt, $expiresAt, $now);

        // Loud in the right direction: the user stays signed in, and what was
        // lost is said out loud rather than left to be discovered the next time
        // somebody tries to end this session and nothing happens. No token and
        // no session id in the line — the row id and the account are what an
        // operator needs to find it.
        wallos_oidc_log_failure('token_refresh_failed', [
            'session_row' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'provider_error' => $outcome['error'],
            'consequence' => 'session stays signed in but is no longer revocable by back-channel logout',
        ]);

        return $verdict('failed', $outcome['error']);
    }

    $tokens = $outcome['tokens'];

    // Rotation: authentik hands back a new refresh token and retires the one
    // just spent. A provider that does not rotate sends none, and the stored
    // one stays valid — overwriting it with an empty string would throw away
    // the credential on the first successful refresh.
    if (!isset($tokens['refresh_token']) || !is_string($tokens['refresh_token'])
        || $tokens['refresh_token'] === '') {
        $tokens['refresh_token'] = $refreshToken;
    }

    if (!wallos_oidc_record_access_token($db, $sessionId, $tokens, $now)) {
        // The provider issued a token and Wallos could not remember what
        // replaces it. Recorded as a failure for the same reason as above: the
        // session is no longer reachable and nothing else would say so.
        wallos_oidc_record_refresh_failure($db, $sessionId, $now, 'not_stored');
        $_SESSION['oidc_refresh_after'] = wallos_oidc_retry_due_at($issuedAt, $expiresAt, $now);

        return $verdict('failed', 'not_stored');
    }

    $validity = wallos_oidc_access_token_validity($tokens, $now);
    $nextDueAt = wallos_oidc_refresh_due_at($validity['issued_at'], $validity['expires_at']);
    $_SESSION['oidc_refresh_after'] = $nextDueAt > 0 ? $nextDueAt : PHP_INT_MAX;

    return $verdict('refreshed');
}
