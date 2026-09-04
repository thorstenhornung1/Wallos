<?php
/*
  Part B — the identity provider is authoritative for an OIDC session's life.

  An OIDC session exists because the provider authenticated it and stays valid
  only while the provider has not ended it. A Wallos cookie's thirty days are a
  MAXIMUM LOCAL PERSISTENCE, never an independent grant: the relationship is
  "(local max lifetime) AND (the provider still accepts the session)". These
  cases prove that in both directions — that the provider's word ends a session
  a cookie would otherwise keep alive, and, as the mandatory positive controls,
  that a provider being briefly unreachable ends nothing.

  The one network touch of the refresh path is wallos_oidc_token_endpoint_post(),
  guarded by function_exists; the child processes below define their own before
  loading the code, so no case here makes a request.

  This file revises exactly one prior decision (issue #144): a DEFINITIVE refresh
  failure (invalid_grant — the provider says the credential is gone) now ends the
  local session, where #144 kept it signed in. A TRANSIENT failure (timeout, DNS,
  5xx) is unchanged and still signs nobody out. Both are exercised here.
*/

require_once WALLOS_ROOT . '/includes/auth_lifetime.php';
require_once WALLOS_ROOT . '/includes/oidc/refresh.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';
require_once WALLOS_ROOT . '/includes/session_tokens.php';
require_once WALLOS_ROOT . '/includes/remember_me.php';

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment
 * (WALLOS_DB_PATH, set by wallos_test_open_database(), names the same database).
 *
 * Local to this file: the runner loads only the case files a filter matches, so
 * a helper from another file may be absent.
 *
 * @param string $body PHP without the opening tag.
 * @return string
 */
function idp_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/idp-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    $runner = 'php ' . escapeshellarg($script) . ' 2>&1';
    exec($runner, $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/**
 * An account, an OIDC-marked remember-me token, a recorded OIDC session and a
 * provider Wallos can be pointed at. The address is a literal so nothing here
 * resolves a name; the transport is replaced in the child anyway.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param string         $token
 * @param bool           $markOidc  whether the login token is marked OIDC-derived
 * @return void
 */
function idp_fixture($db, $sessionId, $token = 'remember-token', $markOidc = true)
{
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :token, :oidc)');
    if ($stmt === false) {
        wallos_test_fail('the fixture could not prepare the login token insert');

        return;
    }
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':oidc', $markOidc ? 1 : 0);
    if ($stmt->execute() === false) {
        wallos_test_fail('the fixture could not create a login token: ' . $db->lastErrorMsg());
    }

    wallos_oidc_register_session($db, 1, 'sid-1', $sessionId, $token, 'id.token.here');

    $saved = wallos_save_oidc_settings($db, [
        'name' => 'Test provider',
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'token_url' => 'http://93.184.216.34/token',
    ], []);

    assert_true($saved['success'], 'the fixture configured a provider: ' . (string) $saved['error']);
}

/**
 * One column of the session row now, or null when the row is gone.
 *
 * @param WallosDatabase $db
 * @param string         $column
 * @param string         $sessionId
 * @return mixed
 */
function idp_session_column($db, $column, $sessionId)
{
    return $db->scalar('SELECT ' . $column . ' FROM oidc_sessions WHERE session_id = :s',
        [':s' => $sessionId]);
}

/**
 * A child that stands in for the provider and runs code against the fixture
 * database. The mocked transport is defined before the code is loaded, so the
 * function_exists guard in refresh.php leaves it in place. The body may go on to
 * require the session guard, which pulls the same already-loaded refresh code.
 *
 * @param array  $responses queued transport answers, one per attempt
 * @param string $body      PHP that drives the code under test
 * @return string
 */
function idp_provider_child($responses, $body)
{
    return idp_run_php(
        '$GLOBALS["calls"] = 0;' . "\n"
        . '$GLOBALS["responses"] = ' . var_export($responses, true) . ';' . "\n"
        . 'function wallos_oidc_token_endpoint_post($url, $fields, $resolve = null) {' . "\n"
        . '    $GLOBALS["calls"]++;' . "\n"
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
 * A child that only needs the fixture database — no provider, for the restore
 * and back-channel paths that never touch the token endpoint.
 *
 * @param string $body
 * @return string
 */
function idp_db_child($body)
{
    return idp_run_php(
        'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . $body
    );
}

/** A provider answer that returns a new access token. */
function idp_success_answer($refreshToken, $expiresIn = 300)
{
    return [
        'body' => json_encode([
            'access_token' => 'fresh-access-token',
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]),
        'status' => 200,
        'error' => null,
    ];
}

/** A provider answer that definitively rejects the credential. */
function idp_invalid_grant_answer()
{
    return [
        'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Session gone']),
        'status' => 400,
        'error' => null,
    ];
}

// ------------------------------------------------- Req 1: one local maximum

wallos_test('the local maximum session lifetime is one definition, thirty days', function () {
    assert_same(30 * 24 * 60 * 60, wallos_auth_max_session_lifetime(),
        'the default is thirty days, expressed once');
    assert_same(2592000, wallos_auth_max_session_lifetime(), 'which is 2592000 seconds');
});

wallos_test('no auth file still carries its own copy of the thirty days', function () {
    // The security significance of the scattered constant is gone: the number
    // lives in one function and these files call it. Reintroducing a raw
    // 30 * 24 * 60 * 60 or 86400 * 30 in any of them fails this.
    $callers = [
        'login.php',
        'logout.php',
        'includes/checksession.php',
        'includes/connect_endpoint.php',
        'includes/oidc/oidc_login.php',
    ];

    foreach ($callers as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        assert_true(strpos($source, '30 * 24 * 60 * 60') === false,
            $path . ' no longer spells the thirty days out as 30 * 24 * 60 * 60');
        assert_true(strpos($source, '86400 * 30') === false,
            $path . ' no longer spells it out as 86400 * 30');
        assert_true(wallos_test_file_calls($path, 'wallos_auth_max_session_lifetime'),
            $path . ' takes the lifetime from the shared definition, as a call and not a mention');
    }
});

// ------------------------------- Req 2: back-channel logout always wins

wallos_test('a valid back-channel logout ends a running session whatever the cookie has left',
    function () {
        // The cookie is set to expire twenty-nine days out. It must not matter:
        // the provider ended the session, and the next request has to be
        // refused. The positive control is the same guard on the same session
        // BEFORE the logout — it admits, so this is not "refuse everybody".
        $db = wallos_test_open_database();
        idp_fixture($db, 'bc-session');

        $before = idp_db_child(
            'session_id("bc-session");' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["from_oidc"] = true;' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
            . 'echo "valid=" . (wallos_oidc_current_session_is_valid($db) ? "yes" : "no");');
        assert_contains('valid=yes', $before,
            'an un-revoked OIDC session is admitted (' . $before . ')');

        // The provider posts a logout for this subject: the row and its token go.
        $revoked = wallos_oidc_revoke_sessions($db, null, 'sid-1');
        assert_same(1, $revoked, 'the back-channel logout revoked the session');

        $after = idp_db_child(
            'session_id("bc-session");' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["from_oidc"] = true;' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
            . 'echo "valid=" . (wallos_oidc_current_session_is_valid($db) ? "yes" : "no");');
        assert_contains('valid=no', $after,
            'and a revoked one is refused on its next request (' . $after . ')');

        assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t',
            [':t' => 'remember-token']), 'the remember-me token went with the session');
        $db->close();
    });

wallos_test('after a back-channel revocation the old cookie cannot restore the session', function () {
    // The cookie a browser still holds must not bring the session back. The
    // positive control restores the very same cookie before the revocation.
    $db = wallos_test_open_database();
    idp_fixture($db, 'bc-session');

    $cookie = 'alice|remember-token|1';

    $before = idp_db_child(
        '$_COOKIE["wallos_login"] = ' . var_export($cookie, true) . ';' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no") . " from_oidc="' . "\n"
        . '    . (isset($_SESSION["from_oidc"]) && $_SESSION["from_oidc"] === true ? "yes" : "no");');
    assert_contains('restored=yes', $before, 'the genuine cookie restores before revocation (' . $before . ')');
    assert_contains('from_oidc=yes', $before, 'and it restores as an OIDC session, not a local one');

    wallos_oidc_revoke_sessions($db, null, 'sid-1');

    $after = idp_db_child(
        '$_COOKIE["wallos_login"] = ' . var_export($cookie, true) . ';' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no");');
    assert_contains('restored=no', $after,
        'and the same cookie is refused once the provider has ended the session (' . $after . ')');
    $db->close();
});

// ------------------ Req 3: a restored OIDC session stays an OIDC session

wallos_test('a restored OIDC session keeps its origin and moves its row onto the new id', function () {
    // The restore rebuilds a session after PHP's own state was collected. It has
    // to stay an OIDC session: from_oidc set (or back-channel logout stops
    // reaching it) and the row moved onto the regenerated id (or a revocation
    // deletes a row that no longer names any live session).
    $db = wallos_test_open_database();
    idp_fixture($db, 'old-php-session');

    $out = idp_db_child(
        '$_COOKIE["wallos_login"] = "alice|remember-token|1";' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no") . "\n";' . "\n"
        . 'echo "from_oidc=" . (isset($_SESSION["from_oidc"]) && $_SESSION["from_oidc"] === true ? "yes" : "no") . "\n";' . "\n"
        . 'echo "moved=" . $db->scalar("SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s", [":s" => session_id()]) . "\n";' . "\n"
        . 'echo "old_gone=" . $db->scalar("SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s", [":s" => "old-php-session"]);');

    assert_contains('restored=yes', $out, 'the OIDC cookie restores (' . $out . ')');
    assert_contains('from_oidc=yes', $out, 'and the rebuilt session remembers it is an OIDC session');
    assert_contains('moved=1', $out, 'the oidc_sessions row now names the regenerated session id');
    assert_contains('old_gone=0', $out, 'and no longer the collected one');
    $db->close();
});

wallos_test('an OIDC-marked token whose row is gone is refused, not made a local session', function () {
    // The direct Req 3 both-direction test. A token minted for an OIDC login is
    // marked; when its row is gone the restore must refuse rather than hand back
    // an independent local session the provider can no longer reach. The
    // positive control is an UNMARKED token with no row, which is a genuine
    // local session and does restore.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // A marked OIDC token whose oidc_sessions row never exists here (revoked).
    $marked = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :t, 1)');
    $marked->bindValue(':t', 'oidc-orphan-token');
    $marked->execute();

    // An ordinary local token: no mark, no row.
    $local = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :t, 0)');
    $local->bindValue(':t', 'plain-local-token');
    $local->execute();

    $refused = idp_db_child(
        '$_COOKIE["wallos_login"] = "alice|oidc-orphan-token|1";' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no");');
    assert_contains('restored=no', $refused,
        'a revoked OIDC token is refused rather than resurrected as a local session (' . $refused . ')');

    $localRestore = idp_db_child(
        '$_COOKIE["wallos_login"] = "alice|plain-local-token|1";' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no") . " from_oidc="' . "\n"
        . '    . (isset($_SESSION["from_oidc"]) ? "set" : "unset");');
    assert_contains('restored=yes', $localRestore,
        'a genuine local token still restores — the fix is not "refuse everybody" (' . $localRestore . ')');
    assert_contains('from_oidc=unset', $localRestore, 'and it is a local session, not an OIDC one');
    $db->close();
});

wallos_test('the OIDC login path marks its remember-me token as OIDC-derived', function () {
    // As a call, because deleting the mark is exactly the change that would let
    // a revoked OIDC session come back as a local one with the suite green.
    assert_true(wallos_test_file_calls('includes/oidc/oidc_login.php', 'columnExists'),
        'the login path guards the marker on the column');
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_login.php');
    assert_contains('from_oidc = 1', $source, 'and marks the token it mints');
});

// ---------------------- Req 4/5: the long-idle gap, both directions

wallos_test('a long-idle session refreshes before it is granted access, and stays valid on success',
    function () {
        // The first request after the PHP session was collected past the point
        // its access token should have refreshed. Through the guard — the one
        // gate every request passes — the refresh happens before access is
        // granted, and a success keeps the session valid and records the new
        // timing. This is the positive control for the definitive case below.
        // A session id unique to this case: the child processes share PHP's
        // session store keyed by id, and maintain() caches its next-due moment in
        // the session — a reused id would let one case's "not due yet" mask the
        // next case's refresh.
        $db = wallos_test_open_database();
        idp_fixture($db, 'idle-success');
        wallos_oidc_record_access_token($db, 'idle-success', [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'stored-refresh-token',
            'expires_in' => 300,
        ], time() - 600); // issued ten minutes ago, a five-minute token: long past due

        $out = idp_provider_child([idp_success_answer('rotated-refresh-token')],
            'session_id("idle-success");' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["from_oidc"] = true;' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
            . '$valid = wallos_oidc_current_session_is_valid($db);' . "\n"
            . 'echo "valid=" . ($valid ? "yes" : "no") . "\n";' . "\n"
            . 'echo "calls=" . $GLOBALS["calls"];');

        assert_contains('valid=yes', $out, 'the session stays valid when the refresh succeeds (' . $out . ')');
        assert_contains('calls=1', $out, 'and the refresh happened, once');

        assert_same('rotated-refresh-token', idp_session_column($db, 'refresh_token', 'idle-success'),
            'the rotated credential replaced the spent one');
        assert_true((int) idp_session_column($db, 'access_token_expires_at', 'idle-success') > time(),
            'and the new access token expiry moved into the future');
        $db->close();
    });

wallos_test('a long-idle session the provider definitively rejects is ended before the request proceeds',
    function () {
        // Same first request, but the provider answers invalid_grant: the
        // credential is gone. The IdP gets the final word even though no
        // back-channel message ever arrived — the request is refused before it
        // reaches anything it protects, the row and its token are removed, and a
        // fresh OIDC sign-in is the only way back in.
        $db = wallos_test_open_database();
        idp_fixture($db, 'idle-definitive');
        wallos_oidc_record_access_token($db, 'idle-definitive', [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'stored-refresh-token',
            'expires_in' => 300,
        ], time() - 600);

        // Driven through wallos_oidc_require_valid_session(): it is what an
        // endpoint calls, and it must end the request rather than return.
        $out = idp_provider_child([idp_invalid_grant_answer()],
            'session_id("idle-definitive");' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["from_oidc"] = true;' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
            . 'wallos_oidc_require_valid_session($db);' . "\n"
            . 'echo "REACHED-PROTECTED-LOGIC";');

        assert_not_contains('REACHED-PROTECTED-LOGIC', $out,
            'the request does not reach anything past the guard (' . $out . ')');
        assert_contains('identity provider', $out,
            'the caller is told the identity provider ended the session');

        assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s',
            [':s' => 'idle-definitive']), 'the oidc_sessions row was removed');
        assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t',
            [':t' => 'remember-token']), 'the login_tokens token was removed, so it must re-auth via OIDC');
        $db->close();
    });

wallos_test('a long-idle session whose refresh only times out is kept, not signed out', function () {
    // Req 5 — no logout storm. "Provider unavailable" is not "provider rejected
    // this credential". A transient failure leaves the session valid and the row
    // and token intact, and records the failure for diagnosis. This is the
    // #144 behaviour, preserved exactly.
    $db = wallos_test_open_database();
    idp_fixture($db, 'idle-transient');
    wallos_oidc_record_access_token($db, 'idle-transient', [
        'access_token' => 'expired-access-token',
        'refresh_token' => 'stored-refresh-token',
        'expires_in' => 300,
    ], time() - 600);

    $timeout = ['body' => false, 'status' => 0, 'error' => 'Operation timed out'];

    $out = idp_provider_child([$timeout],
        'session_id("idle-transient");' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["from_oidc"] = true;' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . '$valid = wallos_oidc_current_session_is_valid($db);' . "\n"
        . 'echo "valid=" . ($valid ? "yes" : "no") . "\n";' . "\n"
        . 'echo "calls=" . $GLOBALS["calls"];');

    assert_contains('valid=yes', $out, 'an unreachable provider does not end the session (' . $out . ')');
    assert_contains('calls=1', $out, 'the guard did try to refresh');

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s',
        [':s' => 'idle-transient']), 'the row is kept');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t',
        [':t' => 'remember-token']), 'the token is kept');
    assert_true((int) idp_session_column($db, 'refresh_failed_at', 'idle-transient') > 0,
        'and the failure is recorded, so an operator can see the session is no longer remotely revocable');
    assert_same('stored-refresh-token', idp_session_column($db, 'refresh_token', 'idle-transient'),
        'the credential that may still work is kept, not discarded');
    $db->close();
});

wallos_test('the endpoint and page bootstraps both run the guard, so the idle gap is closed everywhere',
    function () {
        // The refresh and the definitive-rejection above only close the long-idle
        // gap if they run on that first request. Both entry points restore the
        // session and then hand it to the guard; a second call site is how 112
        // endpoints once went unguarded.
        assert_true(wallos_test_file_calls('includes/connect_endpoint.php', 'restoreSessionFromRememberMeCookie'),
            'the endpoint bootstrap restores an idle session');
        assert_true(wallos_test_file_calls('includes/connect_endpoint.php', 'wallos_oidc_require_valid_session'),
            'and then runs the guard on it');
        assert_true(wallos_test_file_calls('includes/checksession.php', 'restoreSessionFromRememberMeCookie'),
            'the page bootstrap restores an idle session');
        assert_true(wallos_test_file_calls('includes/checksession.php', 'wallos_oidc_current_session_is_valid'),
            'and then runs the guard on it');
    });

// ------------------------------------- Req 6: no periodic refresh cron

wallos_test('no scheduled job keeps stored sessions alive behind the user', function () {
    // A cron that refreshed stored sessions would turn abandoned sessions into
    // provider activity and defeat the provider's own inactivity policies. The
    // refresh is demand-driven, from the guard alone. No cron job may call it.
    foreach (glob(WALLOS_ROOT . '/endpoints/cronjobs/*.php') as $job) {
        $source = file_get_contents($job);
        assert_true(strpos($source, 'wallos_oidc_maintain_access_token') === false,
            basename($job) . ' must not refresh OIDC tokens on a schedule');
    }
});

// ------------------------ config safety: the two "disable login" settings

/**
 * Drives api/admin/set_admin_settings.php as its own process against the fixture
 * database, with an admin api key, and returns what it answered.
 *
 * @param string $apiKey
 * @param array  $post   extra POST fields
 * @return string
 */
function idp_admin_settings_child($apiKey, $post)
{
    $assignments = '';
    foreach ($post as $key => $value) {
        $assignments .= '$_POST[' . var_export($key, true) . '] = ' . var_export($value, true) . ';' . "\n";
    }

    return idp_run_php(
        '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_POST["api_key"] = ' . var_export($apiKey, true) . ';' . "\n"
        . $assignments
        . 'chdir(' . var_export(WALLOS_ROOT . '/api/admin', true) . ');' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/api/admin/set_admin_settings.php', true) . ';');
}

wallos_test('the single-user no-login mode cannot be enabled while OIDC is enabled', function () {
    // admin.login_disabled (no authentication at all) and OIDC (the provider
    // decides who gets in) have contradictory semantics and must stay separate.
    // Enabling the no-login mode with OIDC on is refused; with OIDC off it is
    // allowed — the positive control, or the guard would just be "refuse always".
    //
    // Driven through the whole api/admin/set_admin_settings.php endpoint. It used
    // to read its settings with `SELECT * FROM 'admin'` — a single-quoted string
    // literal that is a syntax error on PostgreSQL (#147) — so this case had to
    // stand aside there. That is fixed now (the endpoint quotes "admin"), so the
    // case runs on both backends: the guard itself is a comparison of the OIDC
    // configuration and was always backend-agnostic.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    require_once WALLOS_ROOT . '/includes/user_roles.php';
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $key = 'admin-api-key';
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', $key);
    $stmt->execute();

    // Turn OIDC on and configure it so is_configured holds. token_url and
    // user_info_url pass through an SSRF check on save, so they are literal
    // public addresses rather than names — nothing here reaches the network.
    $saved = wallos_save_oidc_settings($db, [
        'name' => 'Test provider',
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'authorization_url' => 'http://93.184.216.34/authorize',
        'token_url' => 'http://93.184.216.34/token',
        'user_info_url' => 'http://93.184.216.34/userinfo',
        'redirect_url' => 'http://93.184.216.34/login.php',
        'user_identifier_field' => 'email',
    ], []);
    assert_true($saved['success'], 'the fixture configured OIDC: ' . (string) $saved['error']);
    $enable = $db->prepare('UPDATE admin SET oidc_oauth_enabled = 1 WHERE id = 1');
    $enable->execute();

    // Guard against a silent misconfiguration: the case only means something if
    // OIDC actually reads as enabled and configured here.
    wallos_reset_config_cache($db);
    $cfg = wallos_get_effective_oidc_configuration($db);
    assert_same(1, (int) $cfg['enabled'], 'OIDC is enabled for this case');
    assert_true((bool) $cfg['is_configured'], 'and configured');

    $refused = idp_admin_settings_child($key, ['login_disabled' => '1', 'registrations_open' => '0']);
    assert_contains('"success":false', $refused,
        'enabling the no-login mode while OIDC is on is refused (' . $refused . ')');
    assert_same(0, (int) $db->scalar('SELECT login_disabled FROM admin WHERE id = 1'),
        'and the setting was not written');

    // Positive control: with OIDC off, the same request succeeds.
    $off = $db->prepare('UPDATE admin SET oidc_oauth_enabled = 0 WHERE id = 1');
    $off->execute();

    $allowed = idp_admin_settings_child($key, ['login_disabled' => '1', 'registrations_open' => '0']);
    assert_contains('"success":true', $allowed,
        'with OIDC off the no-login mode is allowed — the guard is not "refuse always" (' . $allowed . ')');
    assert_same(1, (int) $db->scalar('SELECT login_disabled FROM admin WHERE id = 1'),
        'and the setting was written');
    $db->close();
});

// ------------------ F3: the reverse direction — enabling OIDC while no-login

/**
 * Drives api/admin/set_oidc_settings.php as its own process against the fixture
 * database, with an admin api key, and returns what it answered.
 *
 * @param string $apiKey
 * @param array  $post   extra POST fields
 * @return string
 */
function idp_oidc_settings_child($apiKey, $post)
{
    $assignments = '';
    foreach ($post as $key => $value) {
        $assignments .= '$_POST[' . var_export($key, true) . '] = ' . var_export($value, true) . ';' . "\n";
    }

    return idp_run_php(
        '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_POST["api_key"] = ' . var_export($apiKey, true) . ';' . "\n"
        . $assignments
        . 'chdir(' . var_export(WALLOS_ROOT . '/api/admin', true) . ');' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/api/admin/set_oidc_settings.php', true) . ';');
}

wallos_test('OIDC cannot be enabled through the API while the no-login mode is active', function () {
    // The reverse of set_admin_settings.php's guard (finding F3): the mutual
    // exclusion used to be enforced in one direction only. Driven end to end
    // through api/admin/set_oidc_settings.php. Enabling OIDC while
    // admin.login_disabled is set is refused; with the no-login mode off it
    // succeeds — the positive control, or the guard would just be "refuse always".
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    require_once WALLOS_ROOT . '/includes/user_roles.php';
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $key = 'admin-api-key';
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', $key);
    $stmt->execute();

    // Configure OIDC with literal public IPs and no issuer, so is_configured
    // holds and nothing here reaches the network, but leave the toggle off. Then
    // turn the no-login mode on directly (set_admin_settings.php would refuse it
    // once OIDC were effective, which is the other half of the same exclusion).
    $saved = wallos_save_oidc_settings($db, [
        'name' => 'Test provider',
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'authorization_url' => 'http://93.184.216.34/authorize',
        'token_url' => 'http://93.184.216.34/token',
        'user_info_url' => 'http://93.184.216.34/userinfo',
        'redirect_url' => 'http://93.184.216.34/login.php',
        'user_identifier_field' => 'email',
    ], []);
    assert_true($saved['success'], 'the fixture configured OIDC: ' . (string) $saved['error']);
    $db->exec('UPDATE admin SET login_disabled = 1, oidc_oauth_enabled = 0');

    $refused = idp_oidc_settings_child($key, ['oidc_enabled' => '1']);
    assert_contains('"success":false', $refused,
        'enabling OIDC while the no-login mode is on is refused (' . $refused . ')');
    assert_same(0, (int) $db->scalar('SELECT oidc_oauth_enabled FROM admin WHERE id = 1'),
        'and the toggle was not written');

    // Positive control: with the no-login mode off, the same request succeeds.
    $db->exec('UPDATE admin SET login_disabled = 0');
    $allowed = idp_oidc_settings_child($key, ['oidc_enabled' => '1']);
    assert_contains('"success":true', $allowed,
        'with the no-login mode off, enabling OIDC is allowed (' . $allowed . ')');
    assert_same(1, (int) $db->scalar('SELECT oidc_oauth_enabled FROM admin WHERE id = 1'),
        'and the toggle was written');

    $db->close();
});

wallos_test('the OIDC enable guard blocks only the effective-plus-no-login combination', function () {
    // The predicate itself, both directions, so the config paths (which pass the
    // stored enable flag and true) and the toggle paths (which pass is_configured)
    // rest on tested logic rather than on the two end-to-end drives alone.
    require_once WALLOS_ROOT . '/includes/oidc_settings.php';
    $db = wallos_test_open_database();

    $db->exec('UPDATE admin SET login_disabled = 1');
    assert_true(wallos_oidc_enable_conflicts_with_login_disabled($db, 1, true),
        'enabled and configured while no-login is on is a conflict');
    assert_true(!wallos_oidc_enable_conflicts_with_login_disabled($db, 0, true),
        'disabling OIDC is never a conflict');
    assert_true(!wallos_oidc_enable_conflicts_with_login_disabled($db, 1, false),
        'enabled but not configured is not yet effective, so not a conflict');

    // The positive control: with the no-login mode off there is no conflict.
    $db->exec('UPDATE admin SET login_disabled = 0');
    assert_true(!wallos_oidc_enable_conflicts_with_login_disabled($db, 1, true),
        'with the no-login mode off, enabling a configured OIDC is allowed');

    $db->close();
});

wallos_test('every OIDC-enable path guards against the no-login mode', function () {
    // set_admin_settings.php guards the other direction; these are the paths that
    // enable OIDC — the API endpoint (toggle and config), the interface's config
    // save, and the interface's enable toggle. The interface pair authenticates
    // by session and CSRF, so they are pinned here as calls (the tokeniser tells
    // a call from a comment), with the predicate itself tested above.
    foreach ([
        'api/admin/set_oidc_settings.php',
        'endpoints/admin/saveoidcsettings.php',
        'endpoints/admin/enableoidc.php',
    ] as $path) {
        assert_true(
            wallos_test_file_calls($path, 'wallos_oidc_enable_conflicts_with_login_disabled'),
            $path . ' calls the mutual-exclusion guard');
    }
});
