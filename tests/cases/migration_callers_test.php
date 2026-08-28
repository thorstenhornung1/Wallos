<?php
/*
  What the callers do when a migration fails.

  5.8.x made the runner honest: a migration that returns false is not recorded
  as applied, the run stops there, and $migrationFailure names the one that
  broke. The comment above it says the variable is left "for the caller to
  read".

  No caller read it (issue #103). All three included the runner and carried on —
  migrate.php ended, answering 200; import.php answered "success": true after a
  restore that had not finished migrating; registration.php rendered the page.
  Two of them wrapped the runner in ob_start()/ob_end_clean(), so the messages
  it printed about what had failed went into a buffer that was thrown away. What
  survived was one error_log() line in a container log.

  The consequence worth naming is import.php's: the data is in and the schema is
  not, and the person who clicked the button was told it worked.

  These are source-level cases for the reason endpoint_guard_test.php gives —
  the endpoints exit, and a test in the same process cannot survive that. The
  runner's own behaviour is covered functionally in migration_runner_test.php;
  what is checked here is that the answer it produces is looked at.
*/

/**
 * @return array<string, string>
 */
function migration_callers()
{
    // Every production include of the runner. Tests are excluded: they read
    // $migrationFailure by design, and counting them would let a real caller
    // hide behind a passing total.
    return [
        'endpoints/db/migrate.php' => 'the endpoint whose only purpose is migrating',
        'endpoints/db/import.php' => 'the restore path',
        'endpoints/db/restore.php' => 'the legacy SQLite-file restore, migrating since upstream eb0d24b',
        'registration.php' => 'first contact for a new installation',
    ];
}

wallos_test('every caller of the migration runner reads its outcome', function () {
    foreach (migration_callers() as $path => $why) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_contains('run_migrations.php', $source, $path . ' does run migrations');
        assert_contains('migrationFailure', $source,
            $path . ' reads whether they worked — ' . $why);
    }
});

wallos_test('no caller discards what the runner printed', function () {
    // ob_end_clean() drops the buffer. ob_get_clean() returns it, which is the
    // difference between throwing the diagnosis away and having it to log.
    foreach (migration_callers() as $path => $why) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_not_contains('ob_end_clean', $source,
            $path . ' keeps the runner output instead of dropping it');
    }
});

wallos_test('the restore no longer reports success unconditionally', function () {
    // The specific defect: "success" => true sat after the migration call with
    // nothing between them. It must now be reachable only through a check.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/db/import.php');

    $migrated = strpos($source, 'run_migrations.php');
    assert_true($migrated !== false, 'the restore migrates');

    $tail = substr($source, $migrated);
    $success = strpos($tail, '"success" => true');
    $checked = strpos($tail, 'migrationFailure');

    assert_true($success !== false, 'it still has a success answer');
    assert_true($checked !== false && $checked < $success,
        'and the outcome is examined before that answer is given');
});

wallos_test('a failed restore says the schema is the part that is missing', function () {
    // "Restore failed" would be wrong — the data went in. What did not happen
    // is the migration, and an admin who is told the wrong half will restore
    // the same archive again and get the same result.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/db/import.php');

    assert_contains('migrate', strtolower($source), 'the message names migrating as the failure');
});
