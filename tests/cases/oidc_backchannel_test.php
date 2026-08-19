<?php
/*
  Back-channel logout: the provider ending a session without the browser.

  This is an unauthenticated endpoint whose only defence is a signature, so the
  cases below sign real tokens with real keys and check that every way of being
  wrong is refused. A token that does not verify must have no effect at all.

  The other rule, stated as plainly as it can be: no path here deletes a user or
  any user-owned data. An identity disappearing at the provider is not
  permission to destroy somebody's financial history.
*/

require_once WALLOS_ROOT . '/includes/oidc/jwt.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/user_roles.php';

/**
 * A signing key pair plus its JWKS entry, made once and reused.
 *
 * @return array ['private' => resource, 'jwks' => array]
 */
function backchannel_key()
{
    static $key = null;

    if ($key === null) {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($resource);

        $key = [
            'private' => $resource,
            'jwks' => ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'wallos-test',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => backchannel_b64($details['rsa']['n']),
                'e' => backchannel_b64($details['rsa']['e']),
            ]]],
        ];
    }

    return $key;
}

/**
 * @param string $data
 * @return string
 */
function backchannel_b64($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Sign a token the way a provider would.
 *
 * @param array      $claims
 * @param array      $header
 * @param mixed|null $privateKey  a different key, to forge with
 * @return string
 */
function backchannel_token($claims, $header = [], $privateKey = null)
{
    $key = backchannel_key();
    $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'wallos-test'], $header);

    $input = backchannel_b64(json_encode($header)) . '.' . backchannel_b64(json_encode($claims));
    openssl_sign($input, $signature, $privateKey ?? $key['private'], OPENSSL_ALGO_SHA256);

    return $input . '.' . backchannel_b64($signature);
}

/**
 * The claims of a well-formed logout token.
 *
 * @param array $overrides
 * @return array
 */
function backchannel_claims($overrides = [])
{
    return array_merge([
        'iss' => 'https://auth.example.com',
        'aud' => 'wallos-client',
        'iat' => 1000000,
        'jti' => 'unique-id',
        'events' => [WALLOS_BACKCHANNEL_LOGOUT_EVENT => new stdClass()],
        'sid' => 'provider-session-1',
        'sub' => 'user-subject-1',
    ], $overrides);
}

/**
 * @param string $token
 * @param array  $expectations
 * @param int    $now
 * @return array
 */
function backchannel_validate($token, $expectations = [], $now = 1000000)
{
    return wallos_oidc_validate_logout_token(
        $token,
        backchannel_key()['jwks'],
        array_merge(['issuer' => 'https://auth.example.com', 'audience' => 'wallos-client'], $expectations),
        $now
    );
}

// ------------------------------------------------------------ the happy path

wallos_test('a properly signed logout token is accepted', function () {
    $verdict = backchannel_validate(backchannel_token(backchannel_claims()));

    assert_true($verdict['valid'], 'accepted: ' . ($verdict['error'] ?? ''));
    assert_same('provider-session-1', $verdict['sid'], 'the session it names');
    assert_same('user-subject-1', $verdict['sub'], 'and the subject');
});

wallos_test('an audience array containing the client is accepted', function () {
    $token = backchannel_token(backchannel_claims(['aud' => ['other-client', 'wallos-client']]));

    assert_true(backchannel_validate($token)['valid'], 'aud may be a list');
});

// ------------------------------------------------------------------ forgeries

wallos_test('a token signed with the wrong key is refused', function () {
    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $token = backchannel_token(backchannel_claims(), [], $other);

    assert_same('invalid_signature', backchannel_validate($token)['error'], 'not the provider');
});

wallos_test('a tampered payload is refused', function () {
    $token = backchannel_token(backchannel_claims());
    [$header, $payload, $signature] = explode('.', $token);
    $forged = $header . '.' . backchannel_b64(json_encode(backchannel_claims(['sub' => 'somebody-else'])))
        . '.' . $signature;

    assert_same('invalid_signature', backchannel_validate($forged)['error'],
        'the signature no longer covers the claims');
});

wallos_test('an unsigned token is refused', function () {
    // The classic JWT forgery: claim there is no algorithm and hope the
    // verifier agrees.
    $unsigned = backchannel_b64(json_encode(['alg' => 'none']))
        . '.' . backchannel_b64(json_encode(backchannel_claims())) . '.';

    assert_true(!backchannel_validate($unsigned)['valid'], 'alg none is not a signature');
});

wallos_test('an HMAC token signed with the public key is refused', function () {
    // Algorithm confusion: the attacker has the public key, because it is
    // public, and signs an HS256 token with it hoping the verifier uses the
    // same bytes as an HMAC secret.
    $key = backchannel_key();
    $pem = wallos_jwk_to_pem($key['jwks']['keys'][0]);
    $input = backchannel_b64(json_encode(['alg' => 'HS256', 'kid' => 'wallos-test']))
        . '.' . backchannel_b64(json_encode(backchannel_claims()));
    $forged = $input . '.' . backchannel_b64(hash_hmac('sha256', $input, $pem, true));

    assert_true(!backchannel_validate($forged)['valid'], 'the HMAC family is not accepted at all');
});

wallos_test('a token naming an unknown key is refused', function () {
    $token = backchannel_token(backchannel_claims(), ['kid' => 'some-other-key']);

    assert_same('invalid_signature', backchannel_validate($token)['error'], 'no such key');
});

wallos_test('garbage is refused without crashing', function () {
    foreach (['', 'not-a-token', 'a.b', 'a.b.c.d', '!!!.???.***'] as $garbage) {
        $verdict = backchannel_validate($garbage);
        assert_true(!$verdict['valid'], 'refused: ' . $garbage);
    }
});

// -------------------------------------------------------------- claim checks

wallos_test('a token from a different issuer is refused', function () {
    $token = backchannel_token(backchannel_claims(['iss' => 'https://evil.example.com']));

    assert_same('wrong_issuer', backchannel_validate($token)['error'], 'not our provider');
});

wallos_test('a token for a different client is refused', function () {
    // Correctly signed by the same provider, but meant for another application
    // — replaying it here must not end a Wallos session.
    $token = backchannel_token(backchannel_claims(['aud' => 'someone-elses-client']));

    assert_same('wrong_audience', backchannel_validate($token)['error'], 'not for us');
});

wallos_test('an ID token replayed as a logout token is refused', function () {
    // A nonce is forbidden in a logout token precisely so this is detectable.
    $token = backchannel_token(backchannel_claims(['nonce' => 'n-0S6_WzA2Mj']));

    assert_same('nonce_present', backchannel_validate($token)['error'], 'that is an ID token');
});

wallos_test('a token without the logout event is refused', function () {
    assert_same('not_a_logout_event',
        backchannel_validate(backchannel_token(backchannel_claims(['events' => []])))['error'],
        'empty events');
    assert_same('not_a_logout_event',
        backchannel_validate(backchannel_token(backchannel_claims([
            'events' => ['http://schemas.openid.net/event/something-else' => new stdClass()],
        ])))['error'],
        'a different event');

    $claims = backchannel_claims();
    unset($claims['events']);
    assert_same('not_a_logout_event', backchannel_validate(backchannel_token($claims))['error'],
        'no events claim at all');
});

wallos_test('a stale token is refused', function () {
    // Acting on an old logout token could end a session the user has since
    // legitimately re-created.
    $token = backchannel_token(backchannel_claims(['iat' => 1000000]));

    assert_same('token_too_old', backchannel_validate($token, [], 1000000 + 3600)['error'], 'an hour later');
    assert_true(backchannel_validate($token, [], 1000000 + 60)['valid'], 'a minute later is fine');
});

wallos_test('a token issued in the future is refused', function () {
    $token = backchannel_token(backchannel_claims(['iat' => 1000000]));

    assert_same('issued_in_the_future', backchannel_validate($token, [], 1000000 - 3600)['error'], 'clock skew has limits');
    assert_true(backchannel_validate($token, [], 1000000 - 30)['valid'], 'a little skew is tolerated');
});

wallos_test('an expired token is refused', function () {
    $token = backchannel_token(backchannel_claims(['iat' => 1000000, 'exp' => 1000060]));

    assert_same('expired', backchannel_validate($token, [], 1000260)['error'], 'past exp');
});

wallos_test('a token naming neither subject nor session is refused', function () {
    $claims = backchannel_claims();
    unset($claims['sid'], $claims['sub']);

    assert_same('no_subject_or_session', backchannel_validate(backchannel_token($claims))['error'],
        'a session ended, but whose?');
});

// ---------------------------------------------------------------- revocation

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $sub
 */
function backchannel_create_oidc_user($db, $userId, $sub)
{
    wallos_test_create_user($db, $userId, 'user' . $userId);
    $stmt = $db->prepare('UPDATE "user" SET oidc_sub = :sub WHERE id = :id');
    $stmt->bindValue(':sub', $sub, SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $token
 */
function backchannel_add_login_token($db, $userId, $token)
{
    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->execute();
}

wallos_test('revoking by session id ends exactly that session', function () {
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-session-phone', 'token-phone');
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-session-laptop', 'token-laptop');
    backchannel_add_login_token($db, 1, 'token-phone');
    backchannel_add_login_token($db, 1, 'token-laptop');

    $revoked = wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-phone');

    assert_same(1, $revoked, 'one session');
    assert_true(!wallos_oidc_session_is_active($db, 'php-session-phone'), 'the phone is signed out');
    assert_true(wallos_oidc_session_is_active($db, 'php-session-laptop'), 'the laptop is not');

    $db->close();
});

wallos_test('revoking takes the remember-me token with it', function () {
    // Otherwise the very next request signs the browser straight back in and
    // the logout achieved nothing.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', 'remember-me-token');
    backchannel_add_login_token($db, 1, 'remember-me-token');

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-1');

    $remaining = $db->querySingle("SELECT COUNT(*) FROM login_tokens WHERE token = 'remember-me-token'");
    assert_same(0, (int) $remaining, 'the token is gone too');

    $db->close();
});

wallos_test('a provider that sends only a subject ends every session of that person', function () {
    // The documented fallback: without a sid there is no way to tell the
    // sessions apart, and leaving some running would ignore the logout.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, '', 'php-session-1', '');
    wallos_oidc_register_session($db, 1, '', 'php-session-2', '');

    $revoked = wallos_oidc_revoke_sessions($db, 'subject-a', null);

    assert_same(2, $revoked, 'both sessions');
    assert_true(!wallos_oidc_session_is_active($db, 'php-session-1'), 'first ended');
    assert_true(!wallos_oidc_session_is_active($db, 'php-session-2'), 'second ended');

    $db->close();
});

wallos_test('another user is never signed out', function () {
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    backchannel_create_oidc_user($db, 2, 'subject-b');
    wallos_oidc_register_session($db, 1, 'sid-a', 'php-session-a', '');
    wallos_oidc_register_session($db, 2, 'sid-b', 'php-session-b', '');

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-a');

    assert_true(wallos_oidc_session_is_active($db, 'php-session-b'), 'an unrelated session is untouched');

    $db->close();
});

wallos_test('revocation never deletes the account or its data', function () {
    // The hard boundary. OIDC session lifecycle and application data lifecycle
    // are different concerns, and a central account disappearing is not a
    // reason to destroy somebody's subscriptions.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', '');
    $db->query("INSERT INTO subscriptions (name, price, currency_id, next_payment, cycle, frequency,
                payer_user_id, category_id, notify, inactive, user_id)
                VALUES ('Netflix', 9.99, 9010, '2026-01-01', 3, 1, 1, 1, 0, 0, 1)");

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-1');

    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM "user" WHERE id = 1'),
        'the account still exists');
    assert_same(1, (int) $db->querySingle("SELECT COUNT(*) FROM subscriptions WHERE user_id = 1"),
        'and so does the subscription');

    $db->close();
});

wallos_test('a token naming no known session revokes nothing and breaks nothing', function () {
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', '');

    $revoked = wallos_oidc_revoke_sessions($db, 'unknown-subject', 'unknown-sid');

    assert_same(0, $revoked, 'nothing matched');
    assert_true(wallos_oidc_session_is_active($db, 'php-session-1'), 'and nothing was touched');

    $db->close();
});

wallos_test('a missing session table does not sign everybody out', function () {
    // If the migration has not run, this check should be inert. It exists to
    // add a way to end sessions, not to become one.
    $db = wallos_test_open_database();
    $db->query('DROP TABLE oidc_sessions');

    assert_true(wallos_oidc_session_is_active($db, 'any-session'), 'inert rather than hostile');

    $db->close();
});

// ------------------------------------------------------------- wiring checks

wallos_test('every authenticated entry point checks the session', function () {
    // This case used to read the source of checksession.php and look for the
    // string 'wallos_oidc_session_is_active'. It passed while 112 endpoints
    // bootstrapping through connect_endpoint.php never checked at all — so
    // after the provider ended a session the browser kept full API access,
    // including user administration and database backup, until the PHP session
    // expired up to thirty days later. Only navigating to an HTML page logged
    // the user out.
    //
    // The test name claimed coverage the assertion did not provide, which is
    // why nobody looked again. It now names the entry points and checks each.
    foreach (['includes/checksession.php', 'includes/connect_endpoint.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(
            strpos($source, 'session_guard.php') !== false,
            $path . ' must consult the session guard'
        );
    }
});

wallos_test('a revoked session is rejected, whichever way it arrives', function () {
    // The behaviour itself, not the presence of a call.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', 'token-1');

    assert_true(wallos_oidc_session_is_active($db, 'php-session-1'), 'active to start with');

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-1');

    assert_true(!wallos_oidc_session_is_active($db, 'php-session-1'), 'revoked');

    $db->close();
});

wallos_test('revocation also removes the provider-derived admin role', function () {
    // Back-channel logout is the provider saying this person is not coming
    // back. Leaving the role until their next login means a de-privileged
    // administrator keeps administering — and combined with a session that
    // outlives the check, indefinitely.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', '');

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-1');

    assert_true(!wallos_user_is_admin($db, 1), 'the OIDC role went with the session');

    $db->close();
});

wallos_test('revocation never removes a local admin role', function () {
    // The rule the source column exists for, applied here too.
    $db = wallos_test_open_database();
    backchannel_create_oidc_user($db, 1, 'subject-a');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
    wallos_oidc_register_session($db, 1, 'sid-1', 'php-session-1', '');

    wallos_oidc_revoke_sessions($db, 'subject-a', 'sid-1');

    assert_true(wallos_user_is_admin($db, 1), 'still an administrator');
    assert_same(['local'], wallos_user_admin_sources($db, 1), 'by local grant only');

    $db->close();
});

wallos_test('a restored remember-me session stays subject to revocation', function () {
    // A PHP session is collected after ~24 minutes idle while the cookie lives
    // 30 days, so most long-lived sessions come back through the remember-me
    // path. It used not to restore from_oidc, which exempted the rebuilt
    // session from the check permanently — and regenerating the session id left
    // oidc_sessions pointing at a session that no longer existed.
    $source = file_get_contents(WALLOS_ROOT . '/includes/remember_me.php');

    assert_true(strpos($source, "from_oidc") !== false,
        'the OIDC origin is restored');
    assert_true(strpos($source, 'UPDATE oidc_sessions SET session_id') !== false,
        'and the recorded session id follows the regenerated one');
});

wallos_test('signing in records the session', function () {
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_login.php');

    assert_true(strpos($source, 'wallos_oidc_register_session') !== false, 'recorded at login');
    assert_true(strpos($source, "payload']['sid']") !== false,
        'with the provider session id when one was issued');
});

wallos_test('the endpoint deletes no users under any circumstances', function () {
    $source = file_get_contents(WALLOS_ROOT . '/backchannel-logout.php');

    assert_true(strpos($source, 'DELETE FROM "user"') === false, 'no user deletion');
    assert_true(strpos($source, 'DELETE FROM subscriptions') === false, 'no data deletion');
    assert_true(strpos($source, 'wallos_oidc_validate_logout_token') !== false, 'the token is validated');
});

wallos_test('the endpoint accepts only POST', function () {
    $source = file_get_contents(WALLOS_ROOT . '/backchannel-logout.php');

    assert_true(strpos($source, "REQUEST_METHOD'] !== 'POST'") !== false, 'POST only');
});
