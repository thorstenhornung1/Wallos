<?php
/*
  #147 — the two admin-settings endpoints must answer on PostgreSQL.

  api/admin/get_admin_settings.php and api/admin/set_admin_settings.php read the
  singleton settings row. Both once wrote FROM 'admin' — a single-quoted string
  literal, which SQLite tolerates where a table name is expected but PostgreSQL
  rejects as a syntax error. So each endpoint returned HTTP 500 on any
  PostgreSQL install while passing on SQLite. The fix quotes the identifier
  ("admin"); the fork already spells reserved words that way ("user").

  These cases drive each endpoint end to end as its own process against the
  fixture database, with an administrator's API key, and assert it answers
  success. They run on BOTH backends deliberately: the whole point is the
  PostgreSQL path, which the single-quoted form breaks. Revert either endpoint
  to FROM 'admin' and its case here fails on PostgreSQL (a 500 with no success
  payload) while still passing on SQLite — which is exactly the reported bug.

  The child inherits this process's environment (WALLOS_DB_PATH on SQLite; the
  per-case schema via PGOPTIONS on PostgreSQL), so it reaches the same database
  the case built. No fixed session id is set, so each child gets its own —
  issue #148 does not apply.
*/

/**
 * Runs a PHP snippet as its own process, inheriting this process's environment.
 *
 * @param string $body PHP without the opening tag.
 * @return string combined stdout/stderr
 */
function admin147_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/admin147-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/**
 * An administrator (user 1) whose API key is known, against a freshly opened
 * fixture database. Returns [database, apiKey].
 *
 * @return array{0: WallosDatabase, 1: string}
 */
function admin147_admin_fixture()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    require_once WALLOS_ROOT . '/includes/user_roles.php';
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $key = 'admin-147-key';
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', $key);
    $stmt->execute();

    return [$db, $key];
}

/**
 * Drives one admin endpoint by absolute path as its own process, with the api
 * key and any extra POST fields, and returns what it printed.
 *
 * @param string $endpoint absolute path to the endpoint script
 * @param string $apiKey
 * @param array  $post     extra POST fields
 * @return string
 */
function admin147_drive_endpoint($endpoint, $apiKey, array $post = [])
{
    $assignments = '';
    foreach ($post as $key => $value) {
        $assignments .= '$_POST[' . var_export($key, true) . '] = ' . var_export($value, true) . ';' . "\n";
    }

    // api_key travels in both bags: get_admin_settings.php reads $_REQUEST,
    // set_admin_settings.php reads $_POST.
    return admin147_run_php(
        '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_REQUEST["api_key"] = $_POST["api_key"] = ' . var_export($apiKey, true) . ';' . "\n"
        . $assignments
        . 'chdir(' . var_export(dirname($endpoint), true) . ');' . "\n"
        . 'require ' . var_export($endpoint, true) . ';');
}

wallos_test('get_admin_settings answers on this backend, not FROM \'admin\' (#147)', function () {
    // The reader: SELECT * FROM "admin". Written FROM 'admin' it 500s on
    // PostgreSQL before it can answer at all.
    list($db, $key) = admin147_admin_fixture();

    $answer = admin147_drive_endpoint(WALLOS_ROOT . '/api/admin/get_admin_settings.php', $key);

    assert_contains('"success":true', $answer,
        'get_admin_settings answered success on ' . wallos_test_driver() . ' (' . $answer . ')');
    assert_contains('"admin_settings"', $answer,
        'and returned the settings row (' . $answer . ')');

    $db->close();
});

wallos_test('set_admin_settings answers on this backend, not FROM \'admin\' (#147)', function () {
    // The writer reads SELECT * FROM "admin" WHERE id = 1 before any validation.
    // Written FROM 'admin' it 500s on PostgreSQL before reaching the save. A
    // single accepted field exercises the write too; its value matches the seed
    // so the case asserts only that the endpoint answers, not what it stored.
    list($db, $key) = admin147_admin_fixture();

    $answer = admin147_drive_endpoint(
        WALLOS_ROOT . '/api/admin/set_admin_settings.php', $key,
        ['update_notification' => '1']);

    assert_contains('"success":true', $answer,
        'set_admin_settings answered success on ' . wallos_test_driver() . ' (' . $answer . ')');

    $db->close();
});
