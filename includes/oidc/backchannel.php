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

    $sub = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : null;
    $sid = isset($claims['sid']) && is_string($claims['sid']) ? $claims['sid'] : null;

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
 */
function wallos_oidc_register_session($db, $userId, $sid, $sessionId, $loginToken)
{
    $stmt = $db->prepare('INSERT INTO oidc_sessions (user_id, sid, session_id, login_token)
                          VALUES (:userId, :sid, :sessionId, :loginToken)');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':sid', $sid === null ? '' : $sid, SQLITE3_TEXT);
    $stmt->bindValue(':sessionId', $sessionId, SQLITE3_TEXT);
    $stmt->bindValue(':loginToken', $loginToken === null ? '' : $loginToken, SQLITE3_TEXT);
    $stmt->execute();
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
    $stmt = $db->prepare('SELECT 1 FROM oidc_sessions WHERE session_id = :sessionId LIMIT 1');
    if ($stmt === false) {
        // The table is missing, which means the migration has not run. Treating
        // that as "revoked" would sign every OIDC user out; this check exists to
        // add a way to end sessions, not to become one itself.
        return true;
    }
    $stmt->bindValue(':sessionId', $sessionId, SQLITE3_TEXT);
    $result = $stmt->execute();

    return $result !== false && $result->fetchArray(SQLITE3_ASSOC) !== false;
}

/**
 * Revoke the sessions a logout token identifies.
 *
 * Correlates by sid when the provider sent one — that ends exactly the session
 * it means. With only sub, every session of that subject is ended, which is the
 * documented fallback and the right reading of "this person is logged out".
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
    $rows = [];

    if ($sid !== null && $sid !== '') {
        $stmt = $db->prepare('SELECT id, login_token FROM oidc_sessions WHERE sid = :sid');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bindValue(':sid', $sid, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('SELECT s.id, s.login_token FROM oidc_sessions s
                              JOIN user u ON u.id = s.user_id
                              WHERE u.oidc_sub = :sub');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bindValue(':sub', $sub, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    foreach ($rows as $row) {
        // The remember-me token has to go as well, or the next request signs
        // the browser straight back in.
        if (($row['login_token'] ?? '') !== '') {
            wallos_revoke_login_token($db, $row['login_token']);
        }

        $delete = $db->prepare('DELETE FROM oidc_sessions WHERE id = :id');
        $delete->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $delete->execute();
    }

    return count($rows);
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
