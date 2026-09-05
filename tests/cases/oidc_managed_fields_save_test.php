<?php
/*
  save_user.php refuses to change an IdP-managed profile field (#156).

  #154 made firstname/lastname/email/language provider-governed for an
  OIDC-linked account: profile.php shows them read-only and the login re-asserts
  the IdP claims. But endpoints/user/save_user.php still wrote whatever those
  fields were POSTed as, so a crafted request that bypassed the client-side
  readonly changed a governed field until the next login re-asserted it. The fix
  makes the endpoint itself refuse the change: for a linked user on an
  OIDC-effective instance, for every field in the SAME managed set profile.php
  locks (wallos_oidc_managed_profile_fields), the stored database value wins and
  the submitted value is ignored -- silently, without failing the save.

  These cases drive the real endpoint the way a request does: its own PHP
  process, a logged-in session with a matching CSRF token, a POST body, and the
  effective OIDC configuration in the environment. They then read the database
  back. That is the only faithful way to test "a crafted POST cannot change a
  managed field", and it fails the moment the enforcement is removed from
  save_user.php -- verified by hand by deleting the guard and watching case one
  turn red.
*/

/**
 * Runs endpoints/user/save_user.php in its own process, as a logged-in POST.
 *
 * The child inherits the fixture's database environment (WALLOS_DB_PATH on
 * SQLite, the PGOPTIONS search_path on PostgreSQL), so it writes to the very
 * database this case seeded and reads back. OIDC is configured through the
 * environment -- the same inputs a deployment uses -- so the endpoint's
 * effective configuration matches what the case intends without touching any
 * settings table.
 *
 * A fresh session directory and session id per run means no cached $_SESSION
 * from an earlier run can preload state and mask the behaviour (issue #148).
 *
 * @param int         $userId       the logged-in account
 * @param array       $post         the POST body (csrf_token is added)
 * @param bool        $oidcEffective whether to configure an effective OIDC instance
 * @param string      $scopes       OIDC scopes, deciding the managed set
 * @return string the merged stdout/stderr of the run
 */
function oidc_managed_save_invoke($userId, array $post, $oidcEffective, $scopes = 'openid email profile')
{
    $sessionDir = WALLOS_TEST_TMP . '/oidc-save-sess-' . uniqid('', true);
    mkdir($sessionDir, 0700, true);
    $sessionId = 'oidcsave' . bin2hex(random_bytes(8));

    $post['csrf_token'] = 'test-csrf-token';

    $endpointDir = WALLOS_ROOT . '/endpoints/user';

    $lines = [];
    if ($oidcEffective) {
        // The environment inputs that make wallos_get_effective_oidc_configuration()
        // report enabled + is_configured, with the given scopes. No OIDC_ISSUER,
        // so no discovery network call is made.
        $env = [
            'OIDC_ENABLED' => '1',
            'OIDC_CLIENT_ID' => 'test-client',
            'OIDC_AUTH_URL' => 'https://idp.example/authorize',
            'OIDC_TOKEN_URL' => 'https://idp.example/token',
            'OIDC_USERINFO_URL' => 'https://idp.example/userinfo',
            'OIDC_REDIRECT_URL' => 'https://app.example/login.php',
            'OIDC_USER_IDENTIFIER' => 'sub',
            'OIDC_SCOPES' => $scopes,
        ];
        foreach ($env as $name => $value) {
            $lines[] = 'putenv(' . var_export($name . '=' . $value, true) . ');';
        }
    }

    $lines[] = 'ini_set(' . var_export('session.save_path', true) . ', ' . var_export($sessionDir, true) . ');';
    $lines[] = 'session_id(' . var_export($sessionId, true) . ');';
    $lines[] = 'session_start();';
    $lines[] = '$_SESSION[' . var_export('loggedin', true) . '] = true;';
    $lines[] = '$_SESSION[' . var_export('userId', true) . '] = ' . (int) $userId . ';';
    $lines[] = '$_SESSION[' . var_export('username', true) . '] = ' . var_export('tester', true) . ';';
    $lines[] = '$_SESSION[' . var_export('csrf_token', true) . '] = ' . var_export('test-csrf-token', true) . ';';
    $lines[] = '$_POST = ' . var_export($post, true) . ';';
    $lines[] = '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('POST', true) . ';';
    $lines[] = '$_SERVER[' . var_export('PHP_SELF', true) . '] = ' . var_export('/endpoints/user/save_user.php', true) . ';';
    $lines[] = 'chdir(' . var_export($endpointDir, true) . ');';
    $lines[] = 'require ' . var_export('save_user.php', true) . ';';

    $script = WALLOS_TEST_TMP . '/oidc-save-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . implode("\n", $lines) . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);

    unlink($script);
    foreach (glob($sessionDir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($sessionDir);

    return implode("\n", $output);
}

/**
 * Sets the governed profile columns and the link state of a seeded user.
 */
function oidc_managed_set_profile($db, $userId, $firstname, $lastname, $email, $language, $oidcSub)
{
    // No backend-specific type constants: the untyped bindValue the database
    // boundary exposes lets PDO and SQLite each auto-detect, so this fixture
    // stays inside the portable API on both backends (dev/db-audit.sh).
    $stmt = $db->prepare('UPDATE "user" SET firstname = :firstname, lastname = :lastname, email = :email, language = :language, oidc_sub = :oidc_sub WHERE id = :id');
    $stmt->bindValue(':firstname', $firstname);
    $stmt->bindValue(':lastname', $lastname);
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':language', $language);
    $stmt->bindValue(':oidc_sub', $oidcSub);
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
}

/**
 * The governed columns and main_currency of a user, read fresh.
 */
function oidc_managed_read_profile($db, $userId)
{
    $row = [];
    foreach (['firstname', 'lastname', 'email', 'language', 'main_currency'] as $column) {
        $row[$column] = $db->scalar('SELECT ' . $column . ' FROM "user" WHERE id = :id', [':id' => $userId]);
    }

    return $row;
}

wallos_test('a linked user cannot change an IdP-managed field, but can change a non-managed one', function () {
    $db = wallos_test_open_database();
    $userId = 5;
    wallos_test_create_user($db, $userId, 'linked');
    oidc_managed_set_profile($db, $userId, 'Stored', 'Surname', 'stored@example.com', 'en', 'idp-subject-abc');

    $eur = wallos_test_currency_id($userId, 0);
    $usd = wallos_test_currency_id($userId, 1);

    // Every managed field is tampered, and the non-managed main_currency is
    // switched from EUR to USD in the same request.
    $output = oidc_managed_save_invoke($userId, [
        'firstname' => 'Hacked',
        'lastname' => 'Tampered',
        'email' => 'hacked@example.com',
        'avatar' => 'images/uploads/logos/avatars/x.png',
        'main_currency' => (string) $usd,
        'language' => 'de',
    ], true);

    $row = oidc_managed_read_profile($db, $userId);

    assert_same('Stored', $row['firstname'], 'firstname is the stored value, not the POSTed one (out: ' . $output . ')');
    assert_same('Surname', $row['lastname'], 'lastname is the stored value, not the POSTed one');
    assert_same('stored@example.com', $row['email'], 'email is the stored value, not the POSTed one');
    assert_same('en', $row['language'], 'language is the stored value, not the POSTed one');
    assert_same($usd, (int) $row['main_currency'], 'the non-managed main_currency did change');
});

wallos_test('a legitimate submit that re-sends the unchanged managed values succeeds', function () {
    $db = wallos_test_open_database();
    $userId = 6;
    wallos_test_create_user($db, $userId, 'legit');
    oidc_managed_set_profile($db, $userId, 'Alice', 'Anders', 'alice@example.com', 'en', 'idp-subject-def');

    $eur = wallos_test_currency_id($userId, 0);

    // What the read-only form actually posts: the managed fields unchanged, the
    // hidden language mirror carrying the current value. It must not error.
    $output = oidc_managed_save_invoke($userId, [
        'firstname' => 'Alice',
        'lastname' => 'Anders',
        'email' => 'alice@example.com',
        'avatar' => 'images/uploads/logos/avatars/alice.png',
        'main_currency' => (string) $eur,
        'language' => 'en',
    ], true);

    assert_contains('"success":true', $output, 'the save reports success (out: ' . $output . ')');

    $row = oidc_managed_read_profile($db, $userId);
    assert_same('Alice', $row['firstname'], 'firstname is unchanged');
    assert_same('Anders', $row['lastname'], 'lastname is unchanged');
    assert_same('alice@example.com', $row['email'], 'email is unchanged');
    assert_same('en', $row['language'], 'language is unchanged');
});

wallos_test('a local (non-OIDC) user can change every profile field', function () {
    $db = wallos_test_open_database();
    $userId = 7;
    wallos_test_create_user($db, $userId, 'local');
    // No oidc_sub: a local account. OIDC is effective on the instance, which
    // proves the guard keys on the account being linked, not on OIDC being on.
    oidc_managed_set_profile($db, $userId, 'Old', 'Name', 'old@example.com', 'en', null);

    $usd = wallos_test_currency_id($userId, 1);

    $output = oidc_managed_save_invoke($userId, [
        'firstname' => 'New',
        'lastname' => 'Person',
        'email' => 'new@example.com',
        'avatar' => 'images/uploads/logos/avatars/local.png',
        'main_currency' => (string) $usd,
        'language' => 'de',
    ], true);

    $row = oidc_managed_read_profile($db, $userId);
    assert_same('New', $row['firstname'], 'a local user changes firstname (out: ' . $output . ')');
    assert_same('Person', $row['lastname'], 'a local user changes lastname');
    assert_same('new@example.com', $row['email'], 'a local user changes email');
    assert_same('de', $row['language'], 'a local user changes language');
});

wallos_test('a linked user whose scopes omit email and profile keeps those fields editable', function () {
    $db = wallos_test_open_database();
    $userId = 8;
    wallos_test_create_user($db, $userId, 'scoped');
    oidc_managed_set_profile($db, $userId, 'Keep', 'Editable', 'keep@example.com', 'en', 'idp-subject-ghi');

    // The provider releases neither the email nor the profile scope, so the
    // managed set is empty for this linked user -- exactly the read-time rule
    // wallos_oidc_managed_profile_fields() applies, and the same set profile.php
    // would leave editable. The server must not lock what the UI would not.
    $output = oidc_managed_save_invoke($userId, [
        'firstname' => 'Changed',
        'lastname' => 'Freely',
        'email' => 'changed@example.com',
        'avatar' => 'images/uploads/logos/avatars/scoped.png',
        'main_currency' => (string) wallos_test_currency_id($userId, 0),
        'language' => 'de',
    ], true, 'openid');

    $row = oidc_managed_read_profile($db, $userId);
    assert_same('Changed', $row['firstname'], 'firstname is editable when profile scope is absent (out: ' . $output . ')');
    assert_same('Freely', $row['lastname'], 'lastname is editable when profile scope is absent');
    assert_same('changed@example.com', $row['email'], 'email is editable when email scope is absent');
    assert_same('de', $row['language'], 'language is editable when profile scope is absent');
});
