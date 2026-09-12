<?php
/*
  The notifications table migration 000016 removes stays removed.

  000016 splits the old single notifications table into the per-provider tables
  and drops it. Two things have to be true for that to hold, and this fork only
  ever had the first:

    - The migration has to finalise its own read of the table before dropping
      it. An open result set holds a shared read lock, SQLite answers the DROP
      with "database table is locked", and the unchecked exec() lets the
      migration record itself as applied with its work undone. Fixed here long
      ago, with the rest of the #87 work.

    - createdatabase.php must not put the table back. It runs on every container
      start, and its v0.9-to-v1.0 block asked whether the table exists, not
      whether the installation is from v0.9 — so it recreated the table on every
      boot after the migration had removed it. Measured on this tree before the
      fix: gone after the first start, present again after the second.

  Which means the drop was correct and useless: it held for exactly one boot.
  Nothing reads the table, so what it cost was weight in every backup and every
  restore rather than wrong behaviour — but "the migration ran and the thing it
  did came back" is the kind of fact that makes a schema untrustworthy.

  Upstream has both halves broken and carries the table for both reasons;
  upstream-fix/migration-000016 is the same change against its tree.
*/

/**
 * Runs createdatabase.php as its own process against the given database.
 *
 * Its own process because the file resolves its path through the boundary and
 * reports through the cron harness; requiring it into the runner would mix its
 * reporting into this one and leave its globals behind.
 *
 * @param string $databaseFile
 * @return string combined stdout/stderr
 */
function notifications_removal_start($databaseFile)
{
    $script = WALLOS_TEST_TMP . '/notifications-start-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\nrequire 'endpoints/cronjobs/createdatabase.php';\n");

    $environment = getenv();
    foreach (wallos_test_subprocess_env() as $name => $value) {
        $environment[$name] = $value;
    }
    $environment['WALLOS_DB_PATH'] = $databaseFile;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, WALLOS_ROOT, $environment);

    if (!is_resource($process)) {
        @unlink($script);

        return 'could not start php';
    }

    $output = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($script);

    return $output;
}

wallos_test('the migration chain leaves no notifications table behind', function () {
    if (wallos_test_skip_unless_sqlite('createdatabase.php builds the SQLite schema')) {
        return;
    }

    $db = wallos_test_open_database();

    assert_same(0, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'"),
        'the table is gone once the chain has run');

    assert_true((int) $db->querySingle(
        "SELECT COUNT(*) FROM migrations WHERE migration LIKE '%000016.php'") > 0,
        'and the migration that removed it is recorded');

    $db->close();
});

wallos_test('a second container start does not recreate it', function () {
    // The half this fork was missing. Without it the table returns on every
    // boot and the migration that removed it reads as having done nothing.
    if (wallos_test_skip_unless_sqlite('createdatabase.php builds the SQLite schema')) {
        return;
    }

    $sandbox = WALLOS_TEST_TMP . '/notifications-restart-' . uniqid('', true);
    mkdir($sandbox, 0700, true);
    $databaseFile = $sandbox . '/wallos.db';
    copy(wallos_test_database(), $databaseFile);

    $output = notifications_removal_start($databaseFile);

    $db = new SQLite3($databaseFile);
    assert_same(0, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'"),
        'the table is still gone after another start: ' . $output);
    $db->close();
});

wallos_test('an installation that has not run the migration still gets the table', function () {
    // The case the block exists for, and the one the guard must not break: a
    // database from before the migration chain existed has no migrations table
    // at all, and still needs the v0.9 shape to upgrade from.
    if (wallos_test_skip_unless_sqlite('createdatabase.php builds the SQLite schema')) {
        return;
    }

    $sandbox = WALLOS_TEST_TMP . '/notifications-legacy-' . uniqid('', true);
    mkdir($sandbox, 0700, true);
    $databaseFile = $sandbox . '/wallos.db';
    copy(wallos_test_database(), $databaseFile);

    $db = new SQLite3($databaseFile);
    $db->query('DROP TABLE migrations');
    $db->close();

    $output = notifications_removal_start($databaseFile);

    $db = new SQLite3($databaseFile);
    assert_same(1, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'"),
        'a database with no migration history is still given the table: ' . $output);
    $db->close();
});
