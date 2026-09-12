<?php
/*
  A migration is recorded as applied only when it worked.

  The runner used to include the file, insert the row and print "completed
  successfully", none of it conditional on anything. A migration that failed was
  therefore indistinguishable from one that worked, and the next start skipped
  it because it was already recorded.

  Migration 000016 is the proof that this matters rather than a hypothetical:
  it drops the notifications table it has just replaced, and the drop runs while
  its own read of that table is still open. SQLite refuses, nobody reads the
  result, and the migration is recorded as applied with its work undone — in
  every installation ever made.

  No migration returns anything today, so the change is inert. That is the
  point: the runner is ready for the first one that needs to say it failed,
  rather than needing to be fixed at the same time.
*/

/**
 * A throwaway tree holding the runner and whatever migrations a case wants.
 *
 * Deliberately not the real migrations directory: the cases below are about the
 * runner's rules, and a fixture of two files says more about them than
 * eighty-odd real ones.
 *
 * @param array<string, string> $migrations file name => PHP source
 * @return string the sandbox path
 */
function migration_runner_sandbox($migrations)
{
    $sandbox = WALLOS_TEST_TMP . '/migration-runner-' . uniqid('', true);
    mkdir($sandbox . '/migrations', 0700, true);
    mkdir($sandbox . '/includes', 0700, true);
    mkdir($sandbox . '/db', 0700, true);

    copy(WALLOS_ROOT . '/includes/run_migrations.php', $sandbox . '/includes/run_migrations.php');

    foreach ($migrations as $name => $source) {
        file_put_contents($sandbox . '/migrations/' . $name, $source);
    }

    return $sandbox;
}

/**
 * Runs the runner against a database with a migrations table.
 *
 * @param string  $sandbox
 * @param SQLite3 $db
 * @return array{output: string, failure: string|null}
 */
function migration_runner_run($sandbox, $db)
{
    $migrationFailure = null;

    ob_start();
    require $sandbox . '/includes/run_migrations.php';
    $output = ob_get_clean();

    return ['output' => $output, 'failure' => $migrationFailure];
}

/**
 * @param string $path
 * @return SQLite3
 */
function migration_runner_database($path)
{
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    $db->query('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');

    return $db;
}

/**
 * @param SQLite3 $db
 * @return string[]
 */
function migration_runner_recorded($db)
{
    $recorded = [];
    $result = $db->query('SELECT migration FROM migrations ORDER BY id');

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $recorded[] = $row['migration'];
    }

    return $recorded;
}

wallos_test('a migration that works is run and recorded', function () {
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php $db->query("CREATE TABLE runner_one (id INTEGER)");',
        '900002.php' => '<?php $db->query("CREATE TABLE runner_two (id INTEGER)");',
    ]);

    $db = migration_runner_database($sandbox . '/db/wallos.db');
    $run = migration_runner_run($sandbox, $db);

    assert_same(null, $run['failure'], 'a clean run reports no failure');
    assert_same(['migrations/900001.php', 'migrations/900002.php'], migration_runner_recorded($db),
        'both migrations are recorded, in order');
    assert_contains('900002.php completed successfully', $run['output'], 'and both are reported');

    $db->close();
});

wallos_test('a migration that returns false is not recorded', function () {
    // Recording it is what keeps it from running again. A failed migration that
    // is recorded is skipped forever, and whatever it was meant to do never
    // happens on that installation.
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php $db->query("CREATE TABLE runner_one (id INTEGER)");',
        '900002.php' => '<?php return false;',
        '900003.php' => '<?php $db->query("CREATE TABLE runner_three (id INTEGER)");',
    ]);

    $db = migration_runner_database($sandbox . '/db/wallos.db');
    $run = migration_runner_run($sandbox, $db);

    assert_same(['migrations/900001.php'], migration_runner_recorded($db),
        'only the migration that worked is recorded');
    assert_true($run['failure'] !== null, 'and the caller is told the run failed');
    assert_contains('900002.php', (string) $run['failure'], 'by name');

    $db->close();
});

wallos_test('the run stops rather than carrying on past a failure', function () {
    // Migrations build on each other. Running one against a database where its
    // predecessor failed is how one broken statement becomes a schema nobody
    // can reason about.
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php return false;',
        '900002.php' => '<?php $db->query("CREATE TABLE runner_two (id INTEGER)");',
    ]);

    $db = migration_runner_database($sandbox . '/db/wallos.db');
    migration_runner_run($sandbox, $db);

    assert_same([], migration_runner_recorded($db), 'nothing is recorded');
    assert_same(0, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='runner_two'"),
        'and the migration after the failure did not run');

    $db->close();
});

wallos_test('the next start retries from the same point', function () {
    // The consequence of not recording it: a failure is a pause, not a loss.
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php return file_exists(__DIR__ . "/allow") ? true : false;',
        '900002.php' => '<?php $db->query("CREATE TABLE runner_two (id INTEGER)");',
    ]);

    $db = migration_runner_database($sandbox . '/db/wallos.db');
    migration_runner_run($sandbox, $db);
    assert_same([], migration_runner_recorded($db), 'the first run applied nothing');

    // Whatever was wrong is put right, and the installation restarts.
    file_put_contents($sandbox . '/migrations/allow', '');
    $run = migration_runner_run($sandbox, $db);

    assert_same(null, $run['failure'], 'the second run is clean');
    assert_same(['migrations/900001.php', 'migrations/900002.php'], migration_runner_recorded($db),
        'and both migrations are applied');

    $db->close();
});

wallos_test('a migration whose record cannot be written is reported, not assumed', function () {
    // The other half. A migration that ran and was not recorded runs again on
    // the next start, and one that is not idempotent does its work twice.
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php $db->query("CREATE TABLE runner_one (id INTEGER)");',
    ]);

    $db = new SQLite3($sandbox . '/db/wallos.db');
    $db->busyTimeout(5000);
    $db->query('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');

    // A trigger is the cheapest way to make one write fail for a reason the
    // runner cannot see coming, which is what a full disk or a locked database
    // looks like from here.
    $db->query("CREATE TRIGGER runner_refuse BEFORE INSERT ON migrations
                BEGIN SELECT RAISE(ABORT, 'refused'); END");

    $run = migration_runner_run($sandbox, $db);

    assert_true($run['failure'] !== null, 'the run reports a failure');
    assert_contains('could not be recorded', (string) $run['failure'],
        'and says that the record, not the migration, is what failed');

    $db->close();
});

wallos_test('the runner does not hold a read open across the migrations', function () {
    // The same shape as the defect in 000016, in the file that runs it: the
    // check for the migrations table calls fetchArray() once and never again,
    // so the statement is never stepped past its last row and keeps a shared
    // read lock on sqlite_master. The first migration to create or drop a table
    // then meets "database table is locked".
    $sandbox = migration_runner_sandbox([
        '900001.php' => '<?php $db->query("DROP TABLE IF EXISTS migrations_probe");',
    ]);

    $db = migration_runner_database($sandbox . '/db/wallos.db');
    $db->query('CREATE TABLE migrations_probe (id INTEGER)');
    $db->query("INSERT INTO migrations (migration) VALUES ('migrations/000001.php')");

    migration_runner_run($sandbox, $db);

    assert_same(0, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='migrations_probe'"),
        'a migration can drop a table while the runner has read the migrations table');

    $db->close();
});

wallos_test('the migration file is included for its value, not once', function () {
    // require_once answers true on a repeat include instead of the file's own
    // value, so a runner using it can never see a migration report failure.
    $source = file_get_contents(WALLOS_ROOT . '/includes/run_migrations.php');

    assert_contains('$applied = require $migrationsDir', $source,
        'the return value of the migration is what is read');
    assert_true(strpos($source, 'require_once $migrationsDir') === false,
        'and require_once, which would discard it, is gone');
});
