<?php
/*
  Central logout, cell by cell.

  "What happens on logout" is not one question. An account can hold several
  sessions — a phone and a laptop — and a back-channel logout token may name
  exactly one of them (sid) or the person as a whole (sub). Each session may or
  may not carry a remember-me token, which outlives the PHP session by thirty
  days, and the account may hold a personal API key, which outlives everything.

  The cells that matter are where partial revocation meets a credential that
  survives it. The rule the whole grid is measured against:

      ending one session must not end the others,
      and must not leave the ended one usable.

  oidc_backchannel_test.php already pins the sid/sub split at the row level and
  the single-session cases. What is here is the rest of the grid: the tokens,
  the sessions that are not OIDC sessions, the number the endpoint hands back to
  the identity provider, and the API key nothing in this path touches.

  Cases registered with wallos_test_pending() are defects: the behaviour is what
  the rule above asks for, and the reason names what the code does instead. They
  run, they report, and they do not fail the suite until somebody fixes them.
*/

require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/session_tokens.php';
require_once WALLOS_ROOT . '/includes/user_roles.php';
require_once WALLOS_ROOT . '/includes/api_admin.php';

/**
 * An OIDC-backed account with an API key of its own.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $sub
 * @param string         $apiKey
 * @return void
 */
function logout_matrix_user($db, $userId, $sub, $apiKey = '')
{
    wallos_test_create_user($db, $userId, 'user' . $userId);

    $stmt = $db->prepare('UPDATE "user" SET oidc_sub = :sub, api_key = :key WHERE id = :id');
    $stmt->bindValue(':sub', $sub);
    $stmt->bindValue(':key', $apiKey !== '' ? $apiKey : ('api-key-' . $userId));
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
}

/**
 * A remember-me row, the credential that outlives the PHP session.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $token
 * @return void
 */
function logout_matrix_remember_me($db, $userId, $token)
{
    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)');
    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':token', $token);
    $stmt->execute();
}

/**
 * @param WallosDatabase $db
 * @param string         $token
 * @return bool
 */
function logout_matrix_token_exists($db, $token)
{
    return ((int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t', [':t' => $token])) > 0;
}

/**
 * The signing key the fixture provider uses, made once per run.
 *
 * @return array{private: mixed, jwks: array}
 */
function logout_matrix_key()
{
    static $key = null;

    if ($key === null) {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($resource);
        $encode = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $key = [
            'private' => $resource,
            'jwks' => ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'wallos-matrix',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']),
                'e' => $encode($details['rsa']['e']),
            ]]],
        ];
    }

    return $key;
}

/**
 * A logout token signed the way the provider signs one.
 *
 * @param array $claims claims to add to the well-formed base
 * @return string
 */
function logout_matrix_token($claims)
{
    $encode = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    // iat is now, because the endpoint refuses a token older than five minutes.
    $payload = array_merge([
        'iss' => 'https://auth.matrix.example.com',
        'aud' => 'wallos-matrix-client',
        'iat' => time(),
        'jti' => uniqid('', true),
        'events' => [WALLOS_BACKCHANNEL_LOGOUT_EVENT => new stdClass()],
    ], $claims);

    $input = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'wallos-matrix']))
        . '.' . $encode(json_encode($payload));
    openssl_sign($input, $signature, logout_matrix_key()['private'], OPENSSL_ALGO_SHA256);

    return $input . '.' . $encode($signature);
}

/**
 * Makes the installation look like one configured against the fixture provider.
 *
 * The discovery document is written straight into the cache table with a fresh
 * timestamp, and the JWKS is primed into its own cache against the same jwks_uri
 * the document names, both with fresh timestamps — so the endpoint reads its
 * keys from the cache and nothing is fetched over the network (the JWKS fetch is
 * now cached and routed through the SSRF allowlist, so a file:// URL it once
 * accepted is refused). That is what makes the endpoint itself — rather than the
 * function underneath it — something a test can drive.
 *
 * @param WallosDatabase $db
 * @return void
 */
function logout_matrix_configure($db)
{
    $jwksUri = 'https://auth.matrix.example.com/jwks';

    $document = json_encode([
        'issuer' => 'https://auth.matrix.example.com',
        'jwks_uri' => $jwksUri,
        'authorization_endpoint' => 'https://auth.matrix.example.com/auth',
        'token_endpoint' => 'https://auth.matrix.example.com/token',
        'userinfo_endpoint' => 'https://auth.matrix.example.com/userinfo',
    ]);

    $stmt = $db->prepare('INSERT INTO oidc_discovery_cache (issuer, document, fetched_at)
                          VALUES (:issuer, :document, :fetchedAt)');
    $stmt->bindValue(':issuer', 'https://auth.matrix.example.com');
    $stmt->bindValue(':document', $document);
    $stmt->bindValue(':fetchedAt', time());
    $stmt->execute();

    $jwks = $db->prepare('INSERT INTO oidc_jwks_cache (jwks_uri, document, fetched_at)
                          VALUES (:uri, :document, :fetchedAt)');
    $jwks->bindValue(':uri', $jwksUri);
    $jwks->bindValue(':document', json_encode(logout_matrix_key()['jwks']));
    $jwks->bindValue(':fetchedAt', time());
    $jwks->execute();

    // The same values an operator would put in the environment. The subprocess
    // below inherits them, which is also how it inherits WALLOS_DB_PATH (or the
    // PostgreSQL settings and PGOPTIONS) and so lands on the case's database.
    putenv('OIDC_ENABLED=1');
    putenv('OIDC_ISSUER=https://auth.matrix.example.com');
    putenv('OIDC_CLIENT_ID=wallos-matrix-client');
    putenv('OIDC_AUTH_URL=https://auth.matrix.example.com/auth');
    putenv('OIDC_TOKEN_URL=https://auth.matrix.example.com/token');
    putenv('OIDC_USERINFO_URL=https://auth.matrix.example.com/userinfo');
    putenv('OIDC_REDIRECT_URL=https://wallos.example.com/login.php');
    putenv('OIDC_USER_IDENTIFIER=email');
}

/**
 * POSTs a logout token to backchannel-logout.php and returns what it answered.
 *
 * In a subprocess because the endpoint exits, and as the endpoint rather than as
 * the function it calls because the number reported to the identity provider is
 * the thing under test: a provider does not retry a success, so a response that
 * claims more than was deleted is a session that stays alive forever.
 *
 * @param string $logoutToken
 * @return string the response body, with any log lines stripped
 */
function logout_matrix_post($logoutToken)
{
    $script = WALLOS_TEST_TMP . '/backchannel-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n"
        . 'chdir(' . var_export(WALLOS_ROOT, true) . ');' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_POST["logout_token"] = ' . var_export($logoutToken, true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/backchannel-logout.php', true) . ';' . "\n");

    $output = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output);
    unlink($script);

    $text = implode("\n", $output);

    // error_log goes to stderr in the CLI, so the body is the JSON object in it.
    return preg_match('/\{.*\}/s', $text, $matches) === 1 ? $matches[0] : $text;
}

// ------------------------------------- one session ends, the others carry on

wallos_test('revoking one session leaves the other session\'s remember-me token alone', function () {
    // The cell the owner cares about most. oidc_backchannel_test.php already
    // checks that the laptop's *row* survives; this is the credential that
    // outlives the row by thirty days, and revoking it would sign the laptop out
    // the next time its PHP session is garbage-collected.
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', 'tok-laptop');
    logout_matrix_remember_me($db, 1, 'tok-phone');
    logout_matrix_remember_me($db, 1, 'tok-laptop');

    $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-phone');

    assert_same(1, $revoked, 'one session, and the count says one');
    assert_true(!wallos_oidc_session_is_active($db, 'php-phone'), 'the phone is signed out');
    assert_true(wallos_oidc_session_is_active($db, 'php-laptop'), 'the laptop is not');
    assert_true(!logout_matrix_token_exists($db, 'tok-phone'),
        'the ended session cannot sign itself back in');
    assert_true(logout_matrix_token_exists($db, 'tok-laptop'),
        'and the laptop keeps the credential that survives its PHP session');

    $db->close();
});

wallos_test('ending a session that has no remember-me token touches nobody else\'s', function () {
    // A session registered before "stay logged in" was ticked carries an empty
    // login_token. The revocation has to skip the token delete without skipping
    // anything else — and above all without falling through to a delete that is
    // not scoped to this session.
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', null);
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', 'tok-laptop');
    logout_matrix_remember_me($db, 1, 'tok-laptop');

    $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-phone');

    assert_same(1, $revoked, 'the session with no token still counts as revoked');
    assert_true(!wallos_oidc_session_is_active($db, 'php-phone'), 'and is ended');
    assert_true(logout_matrix_token_exists($db, 'tok-laptop'),
        'the other session\'s token is untouched');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'),
        'exactly one token remains, so nothing was deleted by user instead of by token');

    $db->close();
});

wallos_test('the bulk revocation takes every session\'s remember-me token with it', function () {
    // A token carrying only sub is the bulk delete: every OIDC session of that
    // person at once. The existing case checks the rows; leaving the tokens
    // behind would let all of them sign straight back in.
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', 'tok-laptop');
    logout_matrix_remember_me($db, 1, 'tok-phone');
    logout_matrix_remember_me($db, 1, 'tok-laptop');

    $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', null);

    assert_same(2, $revoked, 'both sessions');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions'), 'no session row survives');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'), 'and no token either');

    $db->close();
});

wallos_test('a bulk revocation does not reach another account\'s sessions or tokens', function () {
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice');
    logout_matrix_user($db, 2, 'sub-bob');
    wallos_oidc_register_session($db, 1, 'sid-alice', 'php-alice', 'tok-alice');
    wallos_oidc_register_session($db, 2, 'sid-bob', 'php-bob', 'tok-bob');
    logout_matrix_remember_me($db, 1, 'tok-alice');
    logout_matrix_remember_me($db, 2, 'tok-bob');

    $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', null);

    assert_same(1, $revoked, 'only alice');
    assert_true(wallos_oidc_session_is_active($db, 'php-bob'), 'bob is still signed in');
    assert_true(logout_matrix_token_exists($db, 'tok-bob'), 'and keeps his remember-me token');

    $db->close();
});

wallos_test('a session that is not an OIDC session survives either shape of logout token', function () {
    // Two sessions, only one of them from the provider: the other was a password
    // login, which leaves a login_tokens row and no oidc_sessions row at all.
    // Measured rather than argued — the revocation reaches sessions through
    // oidc_sessions, so a password session is outside every query it runs, under
    // sid and under sub alike.
    foreach ([['sid' => 'sid-phone', 'label' => 'by session id'], ['sid' => null, 'label' => 'by subject']] as $shape) {
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice');
        wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
        logout_matrix_remember_me($db, 1, 'tok-phone');
        logout_matrix_remember_me($db, 1, 'tok-password-laptop');

        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', $shape['sid']);

        assert_same(1, $revoked, $shape['label'] . ': the OIDC session is the only one counted');
        assert_true(!logout_matrix_token_exists($db, 'tok-phone'),
            $shape['label'] . ': the OIDC session\'s token is revoked');
        assert_true(logout_matrix_token_exists($db, 'tok-password-laptop'),
            $shape['label'] . ': the password session keeps its own');

        $db->close();
    }
});

// ------------------------------------ what the provider is told, end to end

wallos_test('the endpoint reports the number of sessions it actually deleted', function () {
    // Driven as the endpoint, because the number in the response is what the
    // identity provider acts on and a provider does not retry a success.
    $db = wallos_test_open_database();
    logout_matrix_configure($db);
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', 'tok-laptop');
    logout_matrix_remember_me($db, 1, 'tok-phone');
    logout_matrix_remember_me($db, 1, 'tok-laptop');
    $db->close();

    $body = logout_matrix_post(logout_matrix_token(['sub' => 'sub-alice', 'sid' => 'sid-phone']));

    assert_same('{"revoked":1}', $body, 'one session revoked, one reported');

    $db = wallos_database_connect();
    assert_true(!wallos_oidc_session_is_active($db, 'php-phone'), 'the phone really is ended');
    assert_true(wallos_oidc_session_is_active($db, 'php-laptop'), 'and the laptop really is not');
    assert_true(logout_matrix_token_exists($db, 'tok-laptop'), 'nor is its token');
    $db->close();
});

wallos_test('the endpoint does not report a session whose delete failed', function () {
    // The defect this endpoint was written to close, checked where it does its
    // damage: at the response. A count taken from the SELECT rather than from
    // the DELETE would answer 1 here, the provider would record the session as
    // ended, and the session would go on working.
    $db = wallos_test_open_database();
    logout_matrix_configure($db);
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', '');
    wallos_test_block_writes($db, 'oidc_sessions', 'DELETE');
    $db->close();

    $body = logout_matrix_post(logout_matrix_token(['sub' => 'sub-alice', 'sid' => 'sid-phone']));

    assert_same('{"revoked":0}', $body, 'nothing was deleted, so nothing is claimed');

    $db = wallos_database_connect();
    wallos_test_unblock_writes($db, 'oidc_sessions');
    assert_true(wallos_oidc_session_is_active($db, 'php-phone'),
        'and the session the provider was not told about is indeed still running');
    $db->close();
});

wallos_test('the endpoint ends every session when the token names only the subject', function () {
    $db = wallos_test_open_database();
    logout_matrix_configure($db);
    logout_matrix_user($db, 1, 'sub-alice');
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', '');
    wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', '');
    $db->close();

    $body = logout_matrix_post(logout_matrix_token(['sub' => 'sub-alice']));

    assert_same('{"revoked":2}', $body, 'the bulk delete, counted honestly');

    $db = wallos_database_connect();
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions'), 'and it really emptied');
    $db->close();
});

// -------------------------------------------------------- the API credential

wallos_test('a personal API token is not bound to a session and survives every logout', function () {
    // Measured, not argued: after every session of the account has been revoked,
    // the production resolver still identifies the account from the key alone.
    // api/ authenticates with `SELECT * FROM "user" WHERE api_key = :apiKey` and
    // never looks at oidc_sessions — see api/fixer/set_fixer.php:50 and
    // includes/api_admin.php:30 — so ending sessions has no effect on it.
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice', 'alice-api-key');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
    logout_matrix_remember_me($db, 1, 'tok-phone');

    $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', null);
    assert_same(1, $revoked, 'the session is gone');

    $verdict = wallos_resolve_admin_api_user($db, 'alice-api-key');

    assert_same('ok', $verdict['reason'], 'the API key still authenticates and still administers');
    assert_same(1, (int) $verdict['user']['id'], 'as the same account');

    $db->close();
});

wallos_test('a logout does take the provider-granted admin role off the API key', function () {
    // The one thing central logout does reach through to the API: an
    // administrator whose role came from the provider's group claim loses it,
    // so the key keeps working but stops administering. The account itself,
    // its data and its key are untouched.
    $db = wallos_test_open_database();
    logout_matrix_user($db, 1, 'sub-alice', 'alice-api-key');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
    wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', '');

    wallos_oidc_revoke_sessions($db, 'sub-alice', null);

    $verdict = wallos_resolve_admin_api_user($db, 'alice-api-key');

    assert_same('not_admin', $verdict['reason'], 'no longer an administrator');
    assert_true($verdict['user'] !== null, 'but the key still resolves to the account');

    $db->close();
});

wallos_test('a sub-identified logout drops the provider admin role even with no session rows',
    function () {
        // F1. The de-provisioned admin whose browser session has expired and
        // whose only live reach is a never-expiring API key. No oidc_sessions row
        // remains, so the revocation's affected-users set — built only from the
        // rows the delete loop touched — was empty, and the cached oidc admin
        // role stayed. The key kept administering. Now a logout token naming the
        // subject resolves the account and lets the surviving-session guard drop
        // the role, which with no session left it does.
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice', 'alice-api-key');
        wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
        // Deliberately no wallos_oidc_register_session(): the sessions are gone.

        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', null);

        assert_same(0, $revoked, 'there was no session row to revoke');
        assert_true(!wallos_user_is_admin($db, 1),
            'but the cached provider-granted admin role was dropped');

        $verdict = wallos_resolve_admin_api_user($db, 'alice-api-key');
        assert_same('not_admin', $verdict['reason'], 'so the API key no longer administers');
        assert_true($verdict['user'] !== null, 'though it still resolves to the account');

        $db->close();
    }
);

wallos_test('a sub-identified logout keeps the role while another session survives',
    function () {
        // The mandatory positive control for F1's added sub-resolution: the
        // surviving-session guard the QA pass added still holds. Signing the phone
        // out must not de-administer the laptop that is still signed in — without
        // the guard, resolving the subject would de-admin every partial logout.
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice', 'alice-api-key');
        wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
        wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', '');
        wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', '');

        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-phone');

        assert_same(1, $revoked, 'one session ended');
        assert_true(wallos_oidc_session_is_active($db, 'php-laptop'), 'the laptop is still signed in');
        assert_true(wallos_user_is_admin($db, 1),
            'and keeps the admin role its surviving session backs');

        $db->close();
    }
);

wallos_test('no API endpoint consults the session state it is not part of', function () {
    // The source half of the finding above, so that "the API key is not bound to
    // a session" is a statement about the code rather than about one fixture.
    $files = glob(WALLOS_ROOT . '/api/*/*.php');
    assert_true(count($files) > 20, 'the API endpoints were found (' . count($files) . ')');

    foreach ($files as $file) {
        $source = file_get_contents($file);
        $name = 'api/' . basename(dirname($file)) . '/' . basename($file);

        assert_true(strpos($source, 'oidc_sessions') === false,
            $name . ' does not look at the session table');
        assert_true(strpos($source, 'wallos_oidc_session_is_active') === false,
            $name . ' does not check whether a provider session is still active');
    }
});

// ------------------------------------------------------------------ defects
//
// These six were pending in the QA logout matrix (qa/logout-coverage): the
// behaviour the rule asks for, failing against the code as it stood after Part
// B. Defects 1-4 are closed here in includes/oidc/backchannel.php; defect 5 was
// already closed by Part A (restoreSessionFromRememberMeCookie always requires
// the token), and this case proves it. Each was watched failing against its
// unfixed subject before being promoted from wallos_test_pending().

wallos_test('ending one session leaves the account\'s other sessions with the rights they had',
    function () {
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice');
        wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
        wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', '');
        wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', '');

        assert_true(wallos_user_is_admin($db, 1), 'an administrator to start with');

        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-phone');

        assert_same(1, $revoked, 'one session ended');
        assert_true(wallos_oidc_session_is_active($db, 'php-laptop'), 'the laptop is still signed in');
        assert_true(wallos_user_is_admin($db, 1),
            'and still administering, because nothing said this person had lost the group');

        $db->close();
    }
);

wallos_test('a logout token falls back to the subject when the session id it names is unknown',
    function () {
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice');
        // Signed in through a provider that sent no sid in the ID token.
        wallos_oidc_register_session($db, 1, null, 'php-phone', 'tok-phone');
        logout_matrix_remember_me($db, 1, 'tok-phone');

        // The logout token names both, as the specification allows.
        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'provider-sid-42');

        assert_same(1, $revoked, 'the subject is right there in the token');
        assert_true(!wallos_oidc_session_is_active($db, 'php-phone'), 'so the session ends');
        assert_true(!logout_matrix_token_exists($db, 'tok-phone'), 'and cannot sign itself back in');

        $db->close();
    }
);

wallos_test('the endpoint does not answer success for a logout that ended nothing it could have ended',
    function () {
        $db = wallos_test_open_database();
        logout_matrix_configure($db);
        logout_matrix_user($db, 1, 'sub-alice');
        wallos_oidc_register_session($db, 1, null, 'php-phone', '');
        $db->close();

        $body = logout_matrix_post(logout_matrix_token(['sub' => 'sub-alice', 'sid' => 'provider-sid-42']));

        assert_same('{"revoked":1}', $body, 'the session named by the subject was ended');

        $db = wallos_database_connect();
        assert_true(!wallos_oidc_session_is_active($db, 'php-phone'), 'and is really gone');
        $db->close();
    }
);

wallos_test('a logout token whose subject and session id are empty strings is refused',
    function () {
        $expectations = ['issuer' => 'https://auth.matrix.example.com', 'audience' => 'wallos-matrix-client'];
        $jwks = logout_matrix_key()['jwks'];

        foreach ([
            'an empty sid and no sub' => ['sid' => ''],
            'an empty sub and no sid' => ['sub' => ''],
            'both empty' => ['sid' => '', 'sub' => ''],
        ] as $label => $claims) {
            $verdict = wallos_oidc_validate_logout_token(
                logout_matrix_token($claims), $jwks, $expectations, time());

            assert_same('no_subject_or_session', $verdict['error'],
                $label . ': a session ended, but whose?');
        }
    }
);

wallos_test('revoking by session id stays inside the subject the token names',
    function () {
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice');
        logout_matrix_user($db, 2, 'sub-bob');
        wallos_oidc_register_session($db, 1, 'sid-shared', 'php-alice', '');
        wallos_oidc_register_session($db, 2, 'sid-shared', 'php-bob', '');

        $revoked = wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-shared');

        assert_same(1, $revoked, 'one subject, one session');
        assert_true(wallos_oidc_session_is_active($db, 'php-bob'), 'bob was never named');

        $db->close();
    }
);

wallos_test('a session rebuilt from a remember-me cookie is never exempt from back-channel logout',
    function () {
        // Defect 5, and why it is closed: Part A made the restore always require
        // the token (includes/remember_me.php looks the cookie up as
        // "WHERE user_id AND token", in every mode). So the phone's revoked token
        // matches no row — even with admin.login_disabled = 1, which used to drop
        // the token condition and accept the cookie on a sibling session's row.
        // The revoked cookie is refused outright; there is no rebuilt session to
        // be exempt.
        $db = wallos_test_open_database();
        logout_matrix_user($db, 1, 'sub-alice');
        wallos_oidc_register_session($db, 1, 'sid-phone', 'php-phone', 'tok-phone');
        wallos_oidc_register_session($db, 1, 'sid-laptop', 'php-laptop', 'tok-laptop');
        logout_matrix_remember_me($db, 1, 'tok-phone');
        logout_matrix_remember_me($db, 1, 'tok-laptop');
        $db->exec('UPDATE admin SET login_disabled = 1');

        wallos_oidc_revoke_sessions($db, 'sub-alice', 'sid-phone');

        assert_true(!logout_matrix_token_exists($db, 'tok-phone'), 'the phone token was revoked');
        $db->close();

        // The restore as the process the cookie actually reaches: a request that
        // arrives after the phone's PHP session has been garbage-collected. The
        // subprocess inherits the environment, so it opens the case's database.
        $script = WALLOS_TEST_TMP . '/restore-' . uniqid('', true) . '.php';
        file_put_contents($script, "<?php\n"
            . '$_COOKIE["wallos_login"] = "user1|tok-phone|1";' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
            . '$db = wallos_database_connect();' . "\n"
            // The container's configured session directory belongs to the web
            // server and need not exist in a test runner; without this the
            // restore runs with no session at all and the measurement is of the
            // warning rather than of the behaviour.
            . 'ini_set("session.save_path", ' . var_export(WALLOS_TEST_TMP, true) . ');' . "\n"
            . 'session_start();' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
            . '$user = restoreSessionFromRememberMeCookie($db);' . "\n"
            . 'echo "restored=" . ($user !== false ? "yes" : "no") . "\n";' . "\n"
            . 'echo "from_oidc=" . (!empty($_SESSION["from_oidc"]) ? "yes" : "no") . "\n";' . "\n");

        $output = [];
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output);
        unlink($script);
        $text = implode("\n", $output);

        $restored = strpos($text, 'restored=yes') !== false;
        $isOidc = strpos($text, 'from_oidc=yes') !== false;

        assert_true(!$restored || $isOidc,
            'a revoked cookie is either refused or restored as the OIDC session it was (got: '
                . str_replace("\n", ' ', $text) . ')');

        // The concrete closure: the revoked cookie does not restore at all. If
        // this ever reads "restored=yes", the token condition has been dropped
        // again and defect 5 is back.
        assert_contains('restored=no', $text,
            'the revoked cookie is refused outright, not rebuilt as a session (got: '
                . str_replace("\n", ' ', $text) . ')');
    }
);
