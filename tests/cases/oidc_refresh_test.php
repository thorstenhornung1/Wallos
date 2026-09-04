<?php
/*
  Keeping the identity provider able to end a session (issue #144).

  Back-channel logout worked for five minutes after a login and then stopped,
  because the provider builds the notification out of the access tokens
  belonging to a session and Wallos never obtained a refresh token to replace
  the one it was handed. The cases below cover the three halves of the fix: the
  authorization request asks for the credential, the credential is stored with
  the session, and it is spent before the access token dies — early enough that
  the provider's own cleanup of expired tokens cannot decide the outcome.

  No case here makes a request. The refresh path's one network touch is
  wallos_oidc_token_endpoint_post(), guarded by function_exists, so the child
  processes below define their own transport before loading the code and count
  what would have gone over the wire.

  What these cases cannot prove, and must not be read as proving: that a
  refreshed access token still belongs to the same provider session at the far
  end. That is authentik's behaviour, not Wallos's, and it is settled by the
  live measurement written down in docs/test-instance.md section 7.4.
*/

require_once WALLOS_ROOT . '/includes/oidc/refresh.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * Local to this file on purpose: the runner loads only the case files the
 * filter matches, so a helper from another file may not exist.
 *
 * @param string $body PHP code, without the opening tag.
 * @return string
 */
function refresh_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/refresh-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/**
 * A token the way a provider hands it over, with claims and no real signature —
 * nothing here verifies one, and the expiry is what is being read.
 *
 * @param array $claims
 * @return string
 */
function refresh_fake_token($claims)
{
    $encode = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    return $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $encode(json_encode($claims)) . '.' . $encode('not-a-signature');
}

/**
 * An account, a recorded OIDC session, and a provider Wallos can be pointed at.
 *
 * The address is a literal so that nothing in this file resolves a name; the
 * transport is replaced in the child processes anyway.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @return void
 */
function refresh_fixture($db, $sessionId)
{
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :token)');
    if ($stmt === false) {
        wallos_test_fail('the fixture could not prepare the login token insert');

        return;
    }
    $stmt->bindValue(':token', 'remember-token');
    if ($stmt->execute() === false) {
        wallos_test_fail('the fixture could not create a login token: ' . $db->lastErrorMsg());
    }

    wallos_oidc_register_session($db, 1, 'sid-1', $sessionId, 'remember-token', 'id.token.here');

    $saved = wallos_save_oidc_settings($db, [
        'name' => 'Test provider',
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'token_url' => 'http://93.184.216.34/token',
    ], []);

    assert_true($saved['success'], 'the fixture configured a provider: ' . (string) $saved['error']);
}

/**
 * What one column of the session row says now.
 *
 * @param WallosDatabase $db
 * @param string         $column
 * @param string         $sessionId
 * @return mixed
 */
function refresh_session_column($db, $column, $sessionId)
{
    return $db->scalar('SELECT ' . $column . ' FROM oidc_sessions WHERE session_id = :s',
        [':s' => $sessionId]);
}

/**
 * A child process that stands in for the provider and runs the maintenance.
 *
 * @param array  $responses queued transport answers, one per attempt
 * @param string $body      the PHP that drives the code under test
 * @return string
 */
function refresh_with_provider($responses, $body)
{
    return refresh_run_php(
        '$GLOBALS["calls"] = 0;' . "\n"
        . '$GLOBALS["grant"] = "";' . "\n"
        . '$GLOBALS["sent_token"] = "";' . "\n"
        . '$GLOBALS["responses"] = ' . var_export($responses, true) . ';' . "\n"
        . 'function wallos_oidc_token_endpoint_post($url, $fields, $resolve = null) {' . "\n"
        . '    $GLOBALS["calls"]++;' . "\n"
        . '    $GLOBALS["grant"] = $fields["grant_type"] ?? "";' . "\n"
        . '    $GLOBALS["sent_token"] = $fields["refresh_token"] ?? "";' . "\n"
        . '    $next = array_shift($GLOBALS["responses"]);' . "\n"
        . '    return $next === null' . "\n"
        . '        ? ["body" => false, "status" => 0, "error" => "no answer queued"]' . "\n"
        . '        : $next;' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/refresh.php', true) . ';' . "\n"
        . $body
    );
}

/**
 * A provider answer carrying a new access token.
 *
 * @param string $refreshToken
 * @param int    $expiresIn
 * @return array
 */
function refresh_provider_answer($refreshToken, $expiresIn = 300)
{
    return [
        'body' => json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]),
        'status' => 200,
        'error' => null,
    ];
}

// ------------------------------------------------------- asking for the token

wallos_test('the authorization request asks for a refresh token', function () {
    // Without offline_access the provider hands over one access token and
    // nothing to replace it with, which is the whole defect.
    assert_same('openid email profile offline_access',
        wallos_oidc_authorization_scopes('openid email profile'),
        'the configured list keeps its scopes and gains the one Wallos needs');

    assert_same('openid offline_access email',
        wallos_oidc_authorization_scopes('openid offline_access email'),
        'a list that already asks for it is left alone');

    assert_same('openid email offline_access', wallos_oidc_authorization_scopes("  openid \n  email  "),
        'whitespace is normalised, not counted as a scope');

    assert_same('', wallos_oidc_authorization_scopes(''),
        'an empty list stays empty — that means "the provider\'s defaults", not "one scope"');

    assert_true(wallos_test_file_calls('login.php', 'wallos_oidc_authorization_scopes'),
        'the login page builds its scope list through it, as a call rather than a mention');
});

// --------------------------------------------------------- when to refresh it

wallos_test('the refresh moment comes from the token, not from a number', function () {
    // A provider handing out five-minute tokens and one handing out hour-long
    // tokens must both work, and neither may be written down here.
    assert_same(1000150, wallos_oidc_refresh_due_at(1000000, 1000300),
        'halfway through a five-minute token');
    assert_same(1001800, wallos_oidc_refresh_due_at(1000000, 1003600),
        'halfway through an hour-long one');

    // The margin is what makes the provider's own cleanup of expired tokens
    // irrelevant: refreshing at the last second would race it.
    $lifetime = 1000300 - 1000000;
    assert_true(wallos_oidc_refresh_due_at(1000000, 1000300) <= 1000300 - intdiv($lifetime, 2),
        'at least half the life is left when the replacement is fetched');
});

wallos_test('a token that will not say when it dies schedules nothing', function () {
    // Inventing an interval here would be wrong on the next installation.
    assert_same(0, wallos_oidc_refresh_due_at(1000000, 0), 'no expiry, no schedule');

    // A row from before the timings existed, or a token that claims to expire
    // before it was issued: the lifetime cannot be derived, so the answer is
    // "now" rather than half of a nonsense interval — and never 0, which would
    // read as "this session needs nothing".
    assert_same(1, wallos_oidc_refresh_due_at(0, 1000300), 'no issue time means refresh now');
    assert_same(1, wallos_oidc_refresh_due_at(1000300, 1000000), 'nor does an expiry before the issue');
});

wallos_test('the expiry is read from the response, and from the token when it has to be', function () {
    $fromResponse = wallos_oidc_access_token_validity(['expires_in' => 300], 1000000);
    assert_same(1000000, $fromResponse['issued_at'], 'issued when it arrived');
    assert_same(1000300, $fromResponse['expires_at'], 'expires_in is taken at its word');

    $signed = refresh_fake_token(['iat' => 900000, 'exp' => 900300]);
    $fromToken = wallos_oidc_access_token_validity(['access_token' => $signed], 1000000);
    assert_same(900000, $fromToken['issued_at'], 'the token says when it was issued');
    assert_same(900300, $fromToken['expires_at'], 'and when it expires');

    $opaque = wallos_oidc_access_token_validity(['access_token' => 'opaque-string'], 1000000);
    assert_same(0, $opaque['expires_at'], 'a token that says nothing is reported as saying nothing');
});

// ------------------------------------------------------------ storing it

wallos_test('the refresh token is stored per session, with the session', function () {
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');

    $recorded = wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'first-access-token',
        'refresh_token' => 'first-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    assert_true($recorded, 'the state was recorded');
    assert_same('first-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'the credential is on the session row, not on the account');
    assert_same(1000000, (int) refresh_session_column($db, 'access_token_issued_at', 'php-session-1'),
        'with the moment it was issued');
    assert_same(1000300, (int) refresh_session_column($db, 'access_token_expires_at', 'php-session-1'),
        'and the moment it dies');

    // Per session: a second sign-in of the same account carries its own
    // credential, and refreshing one must never touch the other.
    wallos_oidc_register_session($db, 1, 'sid-2', 'php-session-2', 'remember-token-2');
    wallos_oidc_record_access_token($db, 'php-session-2', [
        'access_token' => 'other-access-token',
        'refresh_token' => 'second-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    assert_same('first-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'the first session keeps its own');
    assert_same('second-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-2'),
        'and the second has its own');

    $db->close();
});

wallos_test('signing in records the credential the provider sent', function () {
    // As a call, because commenting the line out is exactly the change that
    // would leave every new session unrefreshable with the suite green.
    assert_true(
        wallos_test_file_calls('includes/oidc/oidc_login.php', 'wallos_oidc_record_access_token'),
        'the login path stores the refresh token'
    );
});

// --------------------------------------------------------------- spending it

wallos_test('a session whose access token is still fresh asks the provider nothing', function () {
    // The refresh must not happen on every request: that would be one call to
    // the identity provider per page load.
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'live-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    // The connection stays open across the child process: the fixture database
    // is named by the environment the child inherits, so both are looking at
    // the same rows, and re-opening in the parent would hand back a fresh copy
    // of the template instead.
    $output = refresh_with_provider([refresh_provider_answer('unused')],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000010);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";' . "\n"
        . 'echo "calls=" . $GLOBALS["calls"] . "\n";');

    assert_contains('action=not_due', $output, 'ten seconds into a five-minute token (' . $output . ')');
    assert_contains('calls=0', $output, 'and nothing went over the wire');

    $db->close();
});

wallos_test('a session past the halfway mark gets a new access token', function () {
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'ageing-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    // 1000200 is past the due moment (1000150) and before expiry (1000300):
    // the token is replaced while the old one is still alive, which is what
    // keeps the provider's list of live tokens from ever being empty.
    $output = refresh_with_provider([refresh_provider_answer('rotated-refresh-token')],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";' . "\n"
        . 'echo "calls=" . $GLOBALS["calls"] . "\n";' . "\n"
        . 'echo "grant=" . $GLOBALS["grant"] . "\n";' . "\n"
        . 'echo "sent=" . $GLOBALS["sent_token"] . "\n";');

    assert_contains('action=refreshed', $output, 'the token was replaced (' . $output . ')');
    assert_contains('calls=1', $output, 'one request, not one per request');
    assert_contains('grant=refresh_token', $output, 'as a refresh grant');
    assert_contains('sent=stored-refresh-token', $output, 'spending the credential stored for this session');

    assert_same('rotated-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'the rotated credential replaced the spent one');
    assert_same(1000500, (int) refresh_session_column($db, 'access_token_expires_at', 'php-session-1'),
        'and the new expiry moved forward');
    assert_same(0, (int) refresh_session_column($db, 'refresh_failed_at', 'php-session-1'),
        'a working session carries no failure');
    $db->close();
});

wallos_test('a provider that rotates nothing keeps the credential it was given', function () {
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'ageing-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    $answer = [
        'body' => json_encode(['access_token' => 'new-access-token', 'expires_in' => 300]),
        'status' => 200,
        'error' => null,
    ];

    $output = refresh_with_provider([$answer],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";');

    assert_contains('action=refreshed', $output, 'the refresh succeeded (' . $output . ')');

    assert_same('stored-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'overwriting it with nothing would have thrown the credential away on the first refresh');
    $db->close();
});

// ------------------------------------------------------- when it goes wrong

wallos_test('a failed refresh is recorded and nobody is signed out', function () {
    // Silently carrying on is what produced #144. Signing the user out would
    // turn a provider having a bad minute into a logout storm, so the failure
    // is written down and the session keeps working.
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'ageing-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    $unreachable = ['body' => false, 'status' => 0, 'error' => 'could not connect'];

    $output = refresh_with_provider([$unreachable],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";' . "\n"
        . 'echo "error=" . $verdict["error"] . "\n";');

    assert_contains('action=failed', $output, 'the failure is reported as one (' . $output . ')');
    assert_contains('error=transport_error', $output, 'and says what kind it was');

    assert_same(1000200, (int) refresh_session_column($db, 'refresh_failed_at', 'php-session-1'),
        'the session is marked as no longer remotely revocable');
    assert_same('transport_error', refresh_session_column($db, 'refresh_error', 'php-session-1'),
        'with the reason beside it');
    assert_same('stored-refresh-token', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'a credential that may still work is kept');
    assert_true(wallos_oidc_session_is_active($db, 'php-session-1'),
        'and the user is still signed in — a failed refresh is not a logout');
    $db->close();
});

wallos_test('a credential the provider calls dead is not kept', function () {
    // invalid_grant is the one answer that will never change. Keeping a secret
    // that can never be spent again is only a liability.
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'ageing-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    $refused = [
        'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Token is not valid']),
        'status' => 400,
        'error' => null,
    ];

    $output = refresh_with_provider([$refused],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";' . "\n"
        . 'echo "error=" . $verdict["error"] . "\n";');

    assert_contains('action=failed', $output, 'reported as a failure (' . $output . ')');
    assert_contains('error=invalid_grant', $output, 'in the provider\'s own words');

    assert_same('', refresh_session_column($db, 'refresh_token', 'php-session-1'),
        'the dead credential is gone');
    assert_same('invalid_grant', refresh_session_column($db, 'refresh_error', 'php-session-1'),
        'and why is on the row');
    assert_true(wallos_oidc_session_is_active($db, 'php-session-1'),
        'still not a reason to sign anybody out');
    $db->close();
});

wallos_test('an unwell provider is asked again after one token lifetime, not per request', function () {
    // Every page load waiting on a provider timeout is its own outage.
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');
    wallos_oidc_record_access_token($db, 'php-session-1', [
        'access_token' => 'ageing-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], 1000000);

    $unreachable = ['body' => false, 'status' => 0, 'error' => 'could not connect'];

    // Three requests: the one that fails, one a minute later, and one after the
    // token's own lifetime has passed. The session cache is cleared between
    // them so the database is what decides, the way it does for a request that
    // arrives on a fresh session.
    $output = refresh_with_provider([$unreachable, $unreachable],
        '$first = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'unset($_SESSION["oidc_refresh_after"]);' . "\n"
        . '$second = wallos_oidc_maintain_access_token($db, "php-session-1", 1000260);' . "\n"
        . 'unset($_SESSION["oidc_refresh_after"]);' . "\n"
        . '$third = wallos_oidc_maintain_access_token($db, "php-session-1", 1000501);' . "\n"
        . 'echo "first=" . $first["action"] . "\n";' . "\n"
        . 'echo "second=" . $second["action"] . "\n";' . "\n"
        . 'echo "third=" . $third["action"] . "\n";' . "\n"
        . 'echo "calls=" . $GLOBALS["calls"] . "\n";');

    assert_contains('first=failed', $output, 'the first attempt fails (' . $output . ')');
    assert_contains('second=backing_off', $output, 'the next request does not ask again');
    assert_contains('third=failed', $output, 'and after a lifetime it tries once more');
    assert_contains('calls=2', $output, 'two attempts across three requests');

    $db->close();
});

wallos_test('a session with nothing to refresh with is left alone', function () {
    // Sessions signed in before #144, and providers that grant no
    // offline_access. They keep working; they simply cannot be kept reachable.
    $db = wallos_test_open_database();
    refresh_fixture($db, 'php-session-1');

    $output = refresh_with_provider([refresh_provider_answer('unused')],
        '$verdict = wallos_oidc_maintain_access_token($db, "php-session-1", 1000200);' . "\n"
        . 'echo "action=" . $verdict["action"] . "\n";' . "\n"
        . 'echo "calls=" . $GLOBALS["calls"] . "\n";');

    assert_contains('action=no_refresh_token', $output, 'nothing to spend (' . $output . ')');
    assert_contains('calls=0', $output, 'and nothing asked');

    $db->close();
});

// -------------------------------------------------------------- the wiring

wallos_test('every authenticated request keeps the token alive, and none is signed out by it',
    function () {
        // The guard is the one place every authenticated request passes
        // through; a second call site is how 112 endpoints once went unguarded.
        assert_true(
            wallos_test_file_calls('includes/oidc/session_guard.php', 'wallos_oidc_maintain_access_token'),
            'the session guard maintains the access token, as a call and not a mention'
        );

        // And the behaviour, through the guard itself: a refresh that fails
        // against a session whose access token has already expired still leaves
        // the session valid.
        $db = wallos_test_open_database();
        refresh_fixture($db, 'guard-session-id');
        wallos_oidc_record_access_token($db, 'guard-session-id', [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'stored-refresh-token',
            'expires_in' => 300,
        ], time() - 600);

        $unreachable = ['body' => false, 'status' => 0, 'error' => 'could not connect'];

        $output = refresh_with_provider([$unreachable],
            'session_id("guard-session-id");' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["from_oidc"] = true;' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
            . '$valid = wallos_oidc_current_session_is_valid($db);' . "\n"
            . 'echo "valid=" . ($valid ? "yes" : "no") . "\n";' . "\n"
            . 'echo "calls=" . $GLOBALS["calls"] . "\n";');

        assert_contains('valid=yes', $output,
            'the provider being unreachable does not end the session (' . $output . ')');
        assert_contains('calls=1', $output, 'and the guard did try to refresh');

        assert_true((int) refresh_session_column($db, 'refresh_failed_at', 'guard-session-id') > 0,
            'the session is marked as no longer remotely revocable');
        $db->close();
    });
