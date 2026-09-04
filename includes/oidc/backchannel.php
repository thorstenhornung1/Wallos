<?php

/**
 * OIDC back-channel logout.
 *
 * Until now the only thing that could end a Wallos session was the user
 * clicking logout. An administrator disabling the account at the provider, or
 * terminating the SSO session, or removing the admin group, changed nothing
 * until the session expired on its own.
 *
 * This is the provider's way to say "that session is over" without the browser
 * being involved. It is an unauthenticated endpoint whose only defence is the
 * token's signature, so validation is where all the care goes: anything that
 * does not verify must have no effect whatsoever.
 */

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/../session_tokens.php';
require_once __DIR__ . '/../ssrf_helper.php';

define('WALLOS_BACKCHANNEL_LOGOUT_EVENT', 'http://schemas.openid.net/event/backchannel-logout');

/**
 * How long a fetched JWKS document is reused before being fetched again.
 *
 * The endpoint that reads it is unauthenticated (F2): without a cache, an
 * anonymous POST forces an outbound fetch of the provider's keys on every
 * request — a DoS lever and an amplification path through a public endpoint.
 * The keys rotate rarely and a new one is picked up within the hour, the same
 * bargain the discovery-document cache strikes (WALLOS_OIDC_DISCOVERY_TTL).
 */
define('WALLOS_OIDC_JWKS_TTL', 3600);

/**
 * Validate a logout token.
 *
 * Pure, so every rejection can be tested without a provider or a request.
 *
 * @param string $token
 * @param array  $jwks         ['keys' => [...]]
 * @param array  $expectations ['issuer' => string, 'audience' => string]
 * @param int    $now          unix timestamp
 * @param int    $leeway       seconds of clock skew tolerated
 * @return array{valid: bool, error: string|null, sub: string|null, sid: string|null}
 */
function wallos_oidc_validate_logout_token($token, $jwks, $expectations, $now, $leeway = 120)
{
    $reject = function ($error) {
        return ['valid' => false, 'error' => $error, 'sub' => null, 'sid' => null];
    };

    $parsed = wallos_jwt_parse($token);
    if ($parsed === null) {
        return $reject('malformed_token');
    }

    // Signature first. Everything after this point reads claims, and reading
    // claims from a token that has not been verified is how unsigned data ends
    // up being trusted.
    if (!wallos_jwt_verify_with_jwks($parsed, $jwks)) {
        return $reject('invalid_signature');
    }

    $claims = $parsed['payload'];

    if (($claims['iss'] ?? null) !== ($expectations['issuer'] ?? null)) {
        return $reject('wrong_issuer');
    }

    // aud may be a string or an array of strings.
    $audience = $claims['aud'] ?? null;
    $expectedAudience = $expectations['audience'] ?? null;
    $audienceMatches = is_array($audience)
        ? in_array($expectedAudience, $audience, true)
        : $audience === $expectedAudience;
    if (!$audienceMatches || $expectedAudience === null) {
        return $reject('wrong_audience');
    }

    $issuedAt = $claims['iat'] ?? null;
    if (!is_int($issuedAt)) {
        return $reject('missing_iat');
    }
    if ($issuedAt > $now + $leeway) {
        return $reject('issued_in_the_future');
    }
    // Logout tokens are meant to be acted on immediately. Accepting an old one
    // would let a captured token end a session the user has since re-created.
    if ($issuedAt < $now - 300 - $leeway) {
        return $reject('token_too_old');
    }

    if (isset($claims['exp']) && is_int($claims['exp']) && $claims['exp'] < $now - $leeway) {
        return $reject('expired');
    }

    // A nonce is forbidden in a logout token. Its presence means this is an ID
    // token being replayed as a logout token.
    if (array_key_exists('nonce', $claims)) {
        return $reject('nonce_present');
    }

    $events = $claims['events'] ?? null;
    if (!is_array($events) || !array_key_exists(WALLOS_BACKCHANNEL_LOGOUT_EVENT, $events)) {
        return $reject('not_a_logout_event');
    }

    // An empty string is not an identifier. A token carrying sid:"" or sub:""
    // names nothing, and reading it as a value is how the guard that exists to
    // refuse an anonymous token stops refusing one: "" === null is false, so the
    // empty string slips past the check below as if it named a session. An empty
    // claim is read as absent, here, so everything downstream sees null.
    $sub = isset($claims['sub']) && is_string($claims['sub']) && $claims['sub'] !== ''
        ? $claims['sub'] : null;
    $sid = isset($claims['sid']) && is_string($claims['sid']) && $claims['sid'] !== ''
        ? $claims['sid'] : null;

    // Without either, the token says a session ended but not whose.
    if ($sub === null && $sid === null) {
        return $reject('no_subject_or_session');
    }

    return ['valid' => true, 'error' => null, 'sub' => $sub, 'sid' => $sid];
}

/**
 * Record a signed-in OIDC session so it can be revoked later.
 *
 * @param SQLite3     $db
 * @param int         $userId
 * @param string|null $sid       the provider's session id, when it sends one
 * @param string      $sessionId the PHP session id
 * @param string|null $loginToken
 * @param string|null $idToken   kept for id_token_hint at logout — the PHP
 *                               session that also holds it does not survive a
 *                               container restart, this row does (#123)
 */
function wallos_oidc_register_session($db, $userId, $sid, $sessionId, $loginToken, $idToken = null)
{
    $stmt = $db->prepare('INSERT INTO oidc_sessions (user_id, sid, session_id, login_token, id_token)
                          VALUES (:userId, :sid, :sessionId, :loginToken, :idToken)');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':sid', $sid === null ? '' : $sid, SQLITE3_TEXT);
    $stmt->bindValue(':sessionId', $sessionId, SQLITE3_TEXT);
    $stmt->bindValue(':loginToken', $loginToken === null ? '' : $loginToken, SQLITE3_TEXT);
    $stmt->bindValue(':idToken', $idToken === null ? '' : $idToken, SQLITE3_TEXT);

    // Checked, because a session with no row here can never be revoked — the
    // guard reads the row's absence as "not an OIDC session" and lets it
    // through forever.
    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: the session was not recorded and cannot be revoked later: '
            . $db->lastErrorMsg());
    }
}

/**
 * Whether this PHP session is still one the provider vouches for.
 *
 * Checked on every authenticated request from an OIDC session. A session whose
 * row is gone was revoked, and revoking has to reach a session that is already
 * running — deleting the remember-me token alone would leave the current
 * browser signed in until it expired.
 *
 * @param SQLite3 $db
 * @param string  $sessionId
 * @return bool
 */
function wallos_oidc_session_is_active($db, $sessionId)
{
    // The table is missing when the migration has not run. Treating that as
    // "revoked" would sign every OIDC user out; this check exists to add a way
    // to end sessions, not to become one itself.
    //
    // Asked explicitly rather than inferred from a failed prepare, because the
    // two backends fail at different moments: SQLite refuses to prepare a
    // statement against a table that does not exist, PostgreSQL prepares it
    // happily and fails at execute. Reading only the prepare result was correct
    // on SQLite and inverted the behaviour on PostgreSQL — every OIDC session
    // would have been treated as revoked.
    if (!$db->tableExists('oidc_sessions')) {
        return true;
    }

    $stmt = $db->prepare('SELECT 1 FROM oidc_sessions WHERE session_id = :sessionId LIMIT 1');
    if ($stmt === false) {
        return true;
    }
    $stmt->bindValue(':sessionId', $sessionId, SQLITE3_TEXT);
    $result = $stmt->execute();

    return $result !== false && $result->fetchArray(SQLITE3_ASSOC) !== false;
}

/**
 * The sessions a logout token identifies.
 *
 * Correlates by sid when the provider sent one, but scoped to the subject named
 * in the same token: a provider session id two accounts ever share must end only
 * the one the token is about, and the sub that scopes it arrived in the very same
 * token. With only a sid — a token that named no subject — the session is ended
 * by its sid alone, because there is nothing to scope by. With only a sub, every
 * session of that subject is ended, the documented "this person is logged out".
 *
 * The one subtlety is the empty-sid fallback. A provider that leaves sid out of
 * the ID token makes Wallos record '' for every session, then sends a sid only in
 * its logout tokens — so the sid can never match a row, and without a fallback
 * that session can never be ended (it lives its full local lifetime while the
 * provider, answered 200, never retries). When the scoped sid reaches nothing,
 * the subject's sessions that carry no recorded sid are taken instead: those are
 * exactly the ones a sid could not reach, still scoped to the named subject so
 * another account is never touched, and a session that does carry its own sid is
 * left to be ended by that sid rather than swept up here.
 *
 * @param SQLite3     $db
 * @param string|null $sub
 * @param string|null $sid
 * @return array<int, array> the session rows to revoke
 */
function wallos_oidc_sessions_for_logout($db, $sub, $sid)
{
    // An empty string names nothing; read as absent so a token carrying sid:""
    // or sub:"" reaches no row. The validator refuses such a token outright, but
    // this path must be correct on its own — it is reached by callers, not only
    // by the endpoint.
    $sub = (is_string($sub) && $sub !== '') ? $sub : null;
    $sid = (is_string($sid) && $sid !== '') ? $sid : null;

    if ($sid !== null && $sub !== null) {
        $rows = wallos_oidc_run_session_select(
            $db,
            'SELECT s.id, s.user_id, s.login_token FROM oidc_sessions s
             JOIN "user" u ON u.id = s.user_id
             WHERE s.sid = :sid AND u.oidc_sub = :sub',
            [':sid' => $sid, ':sub' => $sub]
        );

        if ($rows !== []) {
            return $rows;
        }

        return wallos_oidc_run_session_select(
            $db,
            'SELECT s.id, s.user_id, s.login_token FROM oidc_sessions s
             JOIN "user" u ON u.id = s.user_id
             WHERE u.oidc_sub = :sub AND (s.sid IS NULL OR s.sid = \'\')',
            [':sub' => $sub]
        );
    }

    if ($sid !== null) {
        return wallos_oidc_run_session_select(
            $db,
            'SELECT id, user_id, login_token FROM oidc_sessions WHERE sid = :sid',
            [':sid' => $sid]
        );
    }

    if ($sub !== null) {
        return wallos_oidc_run_session_select(
            $db,
            'SELECT s.id, s.user_id, s.login_token FROM oidc_sessions s
             JOIN "user" u ON u.id = s.user_id
             WHERE u.oidc_sub = :sub',
            [':sub' => $sub]
        );
    }

    return [];
}

/**
 * Runs one of the selects above and returns its rows, or [] when the statement
 * could not even be prepared — an unprepared select finding nothing is the same
 * outcome for a caller whose next step is to iterate the rows.
 *
 * @param SQLite3              $db
 * @param string               $sql
 * @param array<string, mixed> $bindings
 * @return array<int, array>
 */
function wallos_oidc_run_session_select($db, $sql, $bindings)
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }

    foreach ($bindings as $name => $value) {
        $stmt->bindValue($name, $value);
    }

    $result = $stmt->execute();
    $rows = [];
    while ($result !== false && $row = $result->fetchArray()) {
        $rows[] = $row;
    }

    return $rows;
}

/**
 * The account a provider subject belongs to, if any.
 *
 * So a sub-identified logout can drop the provider-granted admin role even when
 * the subject held no session row to iterate — the account whose only live reach
 * is then a never-expiring API key (finding F1). Returns null when no account
 * carries the subject, and for an empty or absent sub, which names nobody.
 *
 * @param WallosDatabase $db
 * @param string|null    $sub
 * @return int|null
 */
function wallos_oidc_user_id_for_sub($db, $sub)
{
    if (!is_string($sub) || $sub === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT id FROM "user" WHERE oidc_sub = :sub LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bindValue(':sub', $sub);
    $result = $stmt->execute();
    $row = $result === false ? false : $result->fetchArray();

    return $row === false ? null : (int) $row['id'];
}

/**
 * Whether the user still holds any OIDC session at all.
 *
 * Asked after a revocation has deleted the rows it was going to, so that the
 * provider-granted admin role is dropped only when the last session backing it
 * is gone — never while another device of the same account is still signed in.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_oidc_user_has_active_session($db, $userId)
{
    $stmt = $db->prepare('SELECT 1 FROM oidc_sessions WHERE user_id = :userId LIMIT 1');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();

    return $result !== false && $result->fetchArray() !== false;
}

/**
 * Revoke the sessions a logout token identifies.
 *
 * Never deletes the user or any of their data. An identity disappearing at the
 * provider is not permission to destroy subscriptions or financial history.
 *
 * @param SQLite3     $db
 * @param string|null $sub
 * @param string|null $sid
 * @return int sessions revoked
 */
function wallos_oidc_revoke_sessions($db, $sub, $sid)
{
    $rows = wallos_oidc_sessions_for_logout($db, $sub, $sid);

    $affectedUsers = [];
    $revoked = 0;

    foreach ($rows as $row) {
        // The remember-me token has to go as well, or the next request signs
        // the browser straight back in.
        //
        // And it is checked, because this is the failure that matters most
        // here: the session row can be deleted perfectly while the token
        // survives, and the browser holding that cookie is then signed back in
        // — into a session with no oidc_sessions row, which no future
        // back-channel logout can reach. The provider, meanwhile, was told the
        // session ended and does not retry a success.
        if (($row['login_token'] ?? '') !== '') {
            if (wallos_revoke_login_token($db, $row['login_token']) === false) {
                error_log('Wallos OIDC revocation: the remember-me token for session '
                    . $row['id'] . ' survived; not counting the session as revoked: '
                    . $db->lastErrorMsg());
                continue;
            }
        }

        // Both results checked, and the count taken from what was deleted
        // rather than from what was found.
        //
        // This function used to return count($rows) — the size of the SELECT
        // above — with the DELETE's prepare and execute both discarded. It was
        // therefore structurally incapable of noticing the delete failing, and
        // answered the identity provider with a number of sessions it had not
        // revoked. The provider does not retry a successful response.
        //
        // That is defect #45 living inside the function written to fix it:
        // a statement whose failure is reported as success.
        $delete = $db->prepare('DELETE FROM oidc_sessions WHERE id = :id');
        if ($delete === false) {
            error_log('Wallos OIDC revocation: could not prepare the session delete: ' . $db->lastErrorMsg());
            continue;
        }
        $delete->bindValue(':id', (int) $row['id']);
        if ($delete->execute() === false) {
            error_log('Wallos OIDC revocation: session ' . $row['id'] . ' was not revoked: ' . $db->lastErrorMsg());
            continue;
        }

        $revoked++;
        if (isset($row['user_id'])) {
            $affectedUsers[(int) $row['user_id']] = true;
        }
    }

    // F1: a sub-identified logout must be able to drop the provider-granted
    // admin role even when it matched no session row to iterate. The set above
    // is built only from rows the delete loop touched, so a logout token naming
    // a subject whose sessions are already gone — the browser session expired,
    // and only a never-expiring API key remains — left the cached `oidc` admin
    // role in place and the key kept administering.
    //
    // Resolving the subject and adding it to the same set lets the guard below
    // decide, unchanged: it drops the role only when no OIDC session of that
    // account survives. A still-signed-in sibling session therefore keeps its
    // rights (the surviving-session rule the QA pass added), and in the no-rows
    // case there is no surviving session, so the role goes. A sid-only token
    // names no subject, so this adds nothing and the sid path is unaffected.
    //
    // Residual, with no code fix here: a provider that removes the admin group
    // WITHOUT sending any back-channel logout leaves the cached role — and the
    // never-expiring API key — in place, because nothing arrives to trigger this
    // path. Closing that needs periodic claim re-validation, deliberately not
    // built (see docs/next-steps.md); until it exists, an OIDC user's API key
    // must be rotated when they are de-provisioned at the identity provider.
    $subjectUserId = wallos_oidc_user_id_for_sub($db, $sub);
    if ($subjectUserId !== null) {
        $affectedUsers[$subjectUserId] = true;
    }

    // The provider-derived admin role goes with the session — but only when it
    // was the account's last one.
    //
    // Otherwise an administrator removed from the admin group keeps the role
    // until they next sign in, and back-channel logout is precisely the signal
    // that says they are not going to. The role, though, backs the account's
    // OIDC sessions collectively, not the one row this token named: signing the
    // phone out must not de-administer the laptop, which is still signed in and
    // still working. So the role is dropped only when this revocation ended the
    // last session behind it — checked after the deletes above. Source-scoped,
    // so a local administrator is untouched, and the next successful login
    // re-grants it if the claim is still there, the same rule the login-time
    // sync follows.
    require_once __DIR__ . '/../user_roles.php';
    foreach (array_keys($affectedUsers) as $userId) {
        if (wallos_oidc_user_has_active_session($db, $userId)) {
            continue;
        }

        if (wallos_revoke_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC) === false) {
            // The session is gone either way, so this does not change the count
            // reported to the provider. But an administrator whose group
            // membership was withdrawn keeps the role until it is revoked, and
            // nothing else will try again.
            error_log('Wallos OIDC revocation: could not revoke the provider-granted admin role for user '
                . $userId . ': ' . $db->lastErrorMsg());
        }
    }

    return $revoked;
}

/**
 * A cheap pre-filter for a logout token, run before any signing key is fetched.
 *
 * F2. The endpoint is unauthenticated, so an anonymous POST would otherwise
 * force an outbound JWKS fetch before anything cheap had a chance to reject the
 * token. This reads the same non-signature claims the full validator reads —
 * issuer, issued-at freshness, the logout event — and refuses obvious junk
 * without a network touch.
 *
 * It is a junk filter, NOT a trust decision: nothing that passes here is acted
 * on. The signature is still verified first inside
 * wallos_oidc_validate_logout_token(), before any claim is trusted. A token
 * that clears this filter is simply one worth spending a key fetch to verify.
 *
 * @param string      $token
 * @param string|null $expectedIssuer
 * @param int         $now
 * @param int         $leeway
 * @return string|null an error code when rejected, null when it may proceed
 */
function wallos_oidc_logout_token_prefilter($token, $expectedIssuer, $now, $leeway = 120)
{
    $parsed = wallos_jwt_parse($token);
    if ($parsed === null) {
        return 'malformed_token';
    }

    $claims = $parsed['payload'];

    if (($claims['iss'] ?? null) !== $expectedIssuer) {
        return 'wrong_issuer';
    }

    $issuedAt = $claims['iat'] ?? null;
    if (!is_int($issuedAt)) {
        return 'missing_iat';
    }
    if ($issuedAt > $now + $leeway) {
        return 'issued_in_the_future';
    }
    if ($issuedAt < $now - 300 - $leeway) {
        return 'token_too_old';
    }

    $events = $claims['events'] ?? null;
    if (!is_array($events) || !array_key_exists(WALLOS_BACKCHANNEL_LOGOUT_EVENT, $events)) {
        return 'not_a_logout_event';
    }

    return null;
}

/**
 * Validate a logout token, fetching the signing keys only if it is worth it.
 *
 * The endpoint's entry point: pre-filter first (no network), then fetch the
 * JWKS through the cache, then the full signature-first validation. Separated
 * from the endpoint so a test can watch the transport seam and confirm a junk
 * token reaches no fetch, and a well-formed one does.
 *
 * @param WallosDatabase $db
 * @param string      $token
 * @param string      $jwksUri
 * @param array       $expectations ['issuer' => string, 'audience' => string]
 * @param int         $now
 * @param int         $leeway
 * @return array{valid: bool, error: string|null, sub: string|null, sid: string|null}
 */
function wallos_oidc_authorize_logout_token($db, $token, $jwksUri, $expectations, $now, $leeway = 120)
{
    $reject = function ($error) {
        return ['valid' => false, 'error' => $error, 'sub' => null, 'sid' => null];
    };

    $prefilterError = wallos_oidc_logout_token_prefilter(
        $token,
        $expectations['issuer'] ?? null,
        $now,
        $leeway
    );
    if ($prefilterError !== null) {
        return $reject($prefilterError);
    }

    $jwks = wallos_oidc_fetch_jwks($db, $jwksUri);
    if ($jwks === null) {
        return $reject('jwks_unavailable');
    }

    return wallos_oidc_validate_logout_token($token, $jwks, $expectations, $now, $leeway);
}

/**
 * A fresh-enough cached JWKS document for this URI, or null.
 *
 * Returns ['document' => array, 'fresh' => bool]: a stale entry is still handed
 * back so the fetch path can fall back to it when the provider cannot be
 * reached, the same "stale beats absent" the discovery cache follows.
 *
 * @param WallosDatabase $db
 * @param string         $jwksUri
 * @return array{document: array, fresh: bool}|null
 */
function wallos_oidc_jwks_cache_read($db, $jwksUri)
{
    if (!$db->tableExists('oidc_jwks_cache')) {
        return null;
    }

    $stmt = $db->prepare('SELECT document, fetched_at FROM oidc_jwks_cache WHERE jwks_uri = :uri');
    if ($stmt === false) {
        return null;
    }
    $stmt->bindValue(':uri', $jwksUri);
    $result = $stmt->execute();
    $row = $result === false ? false : $result->fetchArray();
    if ($row === false) {
        return null;
    }

    $document = json_decode($row['document'], true);
    if (!is_array($document) || !isset($document['keys'])) {
        return null;
    }

    return [
        'document' => $document,
        'fresh' => (time() - (int) $row['fetched_at']) < WALLOS_OIDC_JWKS_TTL,
    ];
}

/**
 * Records a fetched JWKS document against its URI.
 *
 * An upsert keyed on jwks_uri, spelled the portable way (ON CONFLICT, not the
 * SQLite-only REPLACE) so it runs on both backends, exactly as the discovery
 * cache does. The write's result is read: a cache that silently fails to store
 * would fetch on every request, which is the cost F2 exists to remove.
 *
 * @param WallosDatabase $db
 * @param string         $jwksUri
 * @param array          $document
 * @return void
 */
function wallos_oidc_jwks_cache_write($db, $jwksUri, $document)
{
    if (!$db->tableExists('oidc_jwks_cache')) {
        return;
    }

    $stmt = $db->prepare('INSERT INTO oidc_jwks_cache (jwks_uri, document, fetched_at)
                          VALUES (:uri, :document, :fetchedAt)
                          ON CONFLICT (jwks_uri) DO UPDATE
                          SET document = excluded.document, fetched_at = excluded.fetched_at');
    if ($stmt === false) {
        error_log('Wallos OIDC: could not prepare the JWKS cache write: ' . $db->lastErrorMsg());

        return;
    }
    $stmt->bindValue(':uri', $jwksUri);
    $stmt->bindValue(':document', json_encode($document));
    $stmt->bindValue(':fetchedAt', time());
    if ($stmt->execute() === false) {
        error_log('Wallos OIDC: could not store the fetched JWKS, so it will be fetched again next time: '
            . $db->lastErrorMsg());
    }
}

if (!function_exists('wallos_oidc_jwks_http_get')) {
    /**
     * The one network touch of the JWKS path, separated so a test can stand in
     * for the provider without a socket and watch whether it was called at all.
     * A test defines its own version before this file loads; the guard lets that
     * stand, the same arrangement wallos_oidc_token_endpoint_post() uses.
     *
     * @param string      $jwksUri
     * @param string|null $resolve a curl RESOLVE entry pinning the host to the
     *                             addresses the SSRF check already approved
     * @return array{body: string|false, status: int}
     */
    function wallos_oidc_jwks_http_get($jwksUri, $resolve = null)
    {
        $ch = curl_init($jwksUri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        if (is_string($resolve) && $resolve !== '') {
            curl_setopt($ch, CURLOPT_RESOLVE, [$resolve]);
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['body' => $response, 'status' => $httpCode];
    }
}

/**
 * Fetch the provider's signing keys, through a cache and the SSRF allowlist.
 *
 * F2: a fresh cached copy is served without any network touch, so the
 * unauthenticated endpoint cannot be made to fetch the provider's keys on every
 * request.
 *
 * P2: the fetch is routed through validate_oidc_endpoint_url — the same check
 * the token, userinfo and refresh fetches make — and pinned to the addresses it
 * approved. The jwks_uri comes from the operator-configured issuer's discovery
 * document, which Wallos does not control, so this is defence in depth rather
 * than a live hole; a disallowed address is refused rather than fetched.
 *
 * A fetch that fails, or an address the allowlist refuses, falls back to a stale
 * cached copy when one exists: a provider having a bad minute should not take
 * back-channel logout down with it, the same bargain the discovery cache makes.
 *
 * @param WallosDatabase $db
 * @param string         $jwksUri
 * @return array|null
 */
function wallos_oidc_fetch_jwks($db, $jwksUri)
{
    $jwksUri = trim((string) $jwksUri);
    if ($jwksUri === '') {
        return null;
    }

    $cached = wallos_oidc_jwks_cache_read($db, $jwksUri);
    if ($cached !== null && $cached['fresh']) {
        return $cached['document'];
    }

    $validation = validate_oidc_endpoint_url($jwksUri, $db);
    if ($validation === false) {
        return $cached !== null ? $cached['document'] : null;
    }

    $response = wallos_oidc_jwks_http_get(
        $jwksUri,
        $validation['host'] . ':' . $validation['port'] . ':' . implode(',', $validation['ips'])
    );

    if ($response['body'] === false || $response['status'] >= 400) {
        return $cached !== null ? $cached['document'] : null;
    }

    $document = json_decode($response['body'], true);
    if (!is_array($document) || !isset($document['keys'])) {
        return $cached !== null ? $cached['document'] : null;
    }

    wallos_oidc_jwks_cache_write($db, $jwksUri, $document);

    return $document;
}
