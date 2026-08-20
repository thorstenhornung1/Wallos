<?php
/*
  What happens to a migration that does not work.

  Nothing did. includes/run_migrations.php required the file, inserted the row
  and printed "completed successfully", none of it conditional on anything —
  the sharpest case in issue #87, and not a hypothetical one.

  Migration 000016 splits the notifications table into two and then drops it.
  The drop ran while the migration's own `SELECT COUNT(*)` on that table was
  still open, SQLite refused it, and the exec result was not checked. So the
  migration recorded itself as applied with the table still in place — on every
  installation ever made, until 000065 removed it years later. A migration that
  is marked done is never retried, which is what makes that failure permanent
  rather than transient.

  Three rules now, one case each: a migration returning false is not recorded,
  a successful one still is, and a failure stops the migrations that follow.

  The fixture migrations set a marker instead of touching the schema. What is
  under test is the runner's bookkeeping — did this file run, was it recorded —
  and a marker answers that on either backend without either one's opinion
  about DDL getting involved.
*/

/**
 * A directory holding a copy of the runner and the migrations given.
 *
 * @param array<string, string> $migrations filename => PHP body without the tag
 * @return string the sandbox path
 */
function migration_runner_sandbox(array $migrations)
{
    $sandbox = WALLOS_TEST_TMP . '/runner-' . uniqid('', true);

    mkdir($sandbox . '/includes', 0700, true);
    mkdir($sandbox . '/migrations', 0700, true);
    copy(WALLOS_ROOT . '/includes/run_migrations.php', $sandbox . '/includes/run_migrations.php');

    foreach ($migrations as $name => $body) {
        file_put_contents($sandbox . '/migrations/' . $name, "<?php\n" . $body);
    }

    return $sandbox;
}

/**
 * @param string $sandbox
 */
function migration_runner_cleanup($sandbox)
{
    foreach (glob($sandbox . '/migrations/*.php') as $file) {
        unlink($file);
    }

    @unlink($sandbox . '/includes/run_migrations.php');
    @rmdir($sandbox . '/migrations');
    @rmdir($sandbox . '/includes');
    @rmdir($sandbox);
}

/**
 * @param WallosDatabase $db
 * @param string         $migration
 * @return bool
 */
function migration_runner_recorded($db, $migration)
{
    return (int) $db->scalar('SELECT COUNT(*) FROM migrations WHERE migration = :migration',
        [':migration' => $migration]) > 0;
}

wallos_test('a migration that reports failure is not recorded as applied', function () {
    $db = wallos_test_open_database();
    $GLOBALS['migration_runner_ran'] = [];

    $sandbox = migration_runner_sandbox([
        '900001.php' => '$GLOBALS["migration_runner_ran"][] = "first"; return false;',
        '900002.php' => '$GLOBALS["migration_runner_ran"][] = "second";',
    ]);

    ob_start();
    require $sandbox . '/includes/run_migrations.php';
    $output = ob_get_clean();

    assert_true(!migration_runner_recorded($db, 'migrations/900001.php'),
        'the failed migration is not marked done, so the next start retries it');
    assert_contains('failed', $output, 'and the run says so');

    // The migration after it did not run. Migrations build on each other, and
    // continuing past a failure is how one broken statement becomes a schema
    // nobody can reason about.
    assert_same(['first'], $GLOBALS['migration_runner_ran'], 'the next migration was skipped');
    assert_true(!migration_runner_recorded($db, 'migrations/900002.php'),
        'and was not recorded either');

    // The failing migration itself did run — it set its marker before returning
    // false — so "not recorded" is about the bookkeeping and not about the file
    // having been skipped.
    assert_same('900001.php', basename((string) $migrationFailure), 'the caller is told which one failed');

    migration_runner_cleanup($sandbox);
    unset($GLOBALS['migration_runner_ran']);
    $db->close();
});

wallos_test('a migration that works is recorded and lets the next one run', function () {
    // Without this the case above proves only that something was refused. A
    // runner that recorded nothing at all would pass every assertion there.
    $db = wallos_test_open_database();
    $GLOBALS['migration_runner_ran'] = [];

    $sandbox = migration_runner_sandbox([
        '900011.php' => '$GLOBALS["migration_runner_ran"][] = "third";',
        '900012.php' => '$GLOBALS["migration_runner_ran"][] = "fourth"; return;',
    ]);

    ob_start();
    require $sandbox . '/includes/run_migrations.php';
    $output = ob_get_clean();

    assert_true(migration_runner_recorded($db, 'migrations/900011.php'), 'the first is recorded');
    assert_true(migration_runner_recorded($db, 'migrations/900012.php'), 'and so is the second');
    assert_same(['third', 'fourth'], $GLOBALS['migration_runner_ran'], 'both ran');
    assert_contains('completed successfully', $output, 'and the run says so');
    assert_same(null, $migrationFailure, 'a clean run reports no failure');

    // A bare `return;` yields null, which several migrations use to mean "there
    // is nothing to do here". Only an explicit false is a failure — 900012
    // above is that case.
    migration_runner_cleanup($sandbox);
    unset($GLOBALS['migration_runner_ran']);
    $db->close();
});

wallos_test('a migration that gives up after logging says so with false', function () {
    // A bare `return;` after an error_log reads as "nothing to do" and gets the
    // migration recorded as applied — the 000016 shape again, one file further
    // on. Checked across the chain rather than in the two that have it today.
    foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $path) {
        $source = file_get_contents($path);

        if (strpos($source, 'error_log') === false) {
            continue;
        }

        preg_match_all('/error_log\((?:[^;]|\n)*?;\s*\n(?:\s*(?:\/\/[^\n]*)?\n)*\s*return\s*([^;]*);/',
            $source, $matches);

        foreach ($matches[1] as $returned) {
            assert_same('false', trim($returned),
                basename($path) . ' returns false after logging a failure');
        }
    }
});
