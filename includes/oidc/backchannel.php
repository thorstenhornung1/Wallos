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

define('WALLOS_BACKCHANNEL_LOGOUT_EVENT', 'http://schemas.openid.net/event/backchannel-logout');

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
 * Fetch the provider's signing keys.
 *
 * @param string $jwksUri
 * @return array|null
 */
function wallos_oidc_fetch_jwks($jwksUri)
{
    $jwksUri = trim((string) $jwksUri);
    if ($jwksUri === '') {
        return null;
    }

    $ch = curl_init($jwksUri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return null;
    }

    $document = json_decode($response, true);

    return (is_array($document) && isset($document['keys'])) ? $document : null;
}
