<?php
/*
  Several cases seed rows in the parent and then run the real code in an
  exec()ed child that opens its own connection (the currency jobs, the session
  cleanup). On SQLite the child opens the same file; on PostgreSQL each case has
  its own schema, and a child that inherited only the driver — not the schema —
  would connect to the shared default schema and read another run's rows. That
  is what made three currency cases pass for the wrong reason until a date
  rollover exposed them (#148).

  This case holds the guarantee the whole pattern rests on: the child reads
  exactly what the parent wrote in this case, and nothing left in a shared
  schema. It runs on both backends, because the isolation must hold on both. The
  second case pins the other half of #148 — that the harness, not the operator,
  owns the PHP session store.
*/

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment —
 * the same shape the currency cases use, kept local for the same reason.
 *
 * @param string $body PHP code, without the opening tag.
 * @return array{output: string, status: int}
 */
function subprocess_iso_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/subiso-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('a subprocess reads the row the parent seeded in this case, not shared state', function () {
    $db = wallos_test_open_database();

    // A marker the child will read back, created through the boundary so it
    // lands in this case's own isolation — the file on SQLite, the schema on
    // PostgreSQL.
    $db->exec('CREATE TABLE harness_subprocess_marker (note TEXT)');
    $db->exec("INSERT INTO harness_subprocess_marker (note) VALUES ('parent-seeded')");

    if (wallos_test_driver() === 'pgsql') {
        // Plant a DIFFERENT value in the shared default schema — exactly what a
        // child that ignored the parent's search_path would read instead. The
        // table is qualified with public. so it lands there whatever PGOPTIONS
        // currently names.
        $settings = wallos_test_pgsql_settings();
        $shared = new WallosPgsqlDatabase(
            wallos_database_pgsql_dsn($settings), $settings['user'], $settings['password']);
        $shared->exec('CREATE TABLE IF NOT EXISTS public.harness_subprocess_marker (note TEXT)');
        $shared->exec('DELETE FROM public.harness_subprocess_marker');
        $shared->exec("INSERT INTO public.harness_subprocess_marker (note) VALUES ('shared-stale')");
        $shared->close();
    }

    // The child opens its own connection through the front door, resolving the
    // backend and — on PostgreSQL — the schema from the environment, exactly as
    // a real subprocess job does.
    $run = subprocess_iso_run_php(
        'require ' . var_export(WALLOS_ROOT . '/tests/bootstrap.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'echo "note=" . $db->scalar("SELECT note FROM harness_subprocess_marker");'
    );

    assert_contains('note=parent-seeded', $run['output'],
        'the child read the row this case seeded (' . $run['output'] . ')');
    assert_not_contains('shared-stale', $run['output'],
        'and not a row left in the shared schema by another run');

    if (wallos_test_driver() === 'pgsql') {
        // Never leave the shared-schema marker to become the stale row it warns
        // about.
        $settings = wallos_test_pgsql_settings();
        $shared = new WallosPgsqlDatabase(
            wallos_database_pgsql_dsn($settings), $settings['user'], $settings['password']);
        $shared->exec('DROP TABLE IF EXISTS public.harness_subprocess_marker');
        $shared->close();
    }

    $db->close();
});

wallos_test('the harness owns the PHP session store', function () {
    // The other half of #148: a subprocess case that writes sess_<id> under a
    // fixed id leaves it in a store shared across runs, and read back next time a
    // stale value masks the behaviour a later case checks. The runner now creates
    // and clears the store before any case, so a run neither depends on
    // startup.sh having made it nor inherits a prior run's files.
    $store = wallos_test_session_store();

    assert_true(is_dir($store),
        'the session store exists — a run does not depend on startup.sh creating it');
    assert_true(is_writable($store),
        'and it is writable, so a subprocess case can use it');
});
