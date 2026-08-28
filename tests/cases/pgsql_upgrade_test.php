<?php
/*
  The upgrade path on PostgreSQL, which no other case exercises.

  A fresh PostgreSQL installation applies includes/database/pgsql/schema.sql and
  records every migration as already done, so the chain never executes. That is
  the point of the baseline (#21) and it is also a blind spot: every PostgreSQL
  case in this suite, and every version the 2026-08-20 night run installed from
  12 to 18, measured an installation on which no migration had ever run.

  So a migration using syntax PostgreSQL rejects — or one whose SQLite-shaped
  statement means something else there — would pass all of it and fail on the
  first real upgrade, in the one place where failure is expensive: somebody
  else's data.

  This case runs the chain the way an upgrade does. It loads the baseline as
  5.8.0 shipped it, which records migrations up to 000063, and lets
  includes/run_migrations.php do what it does on first start: apply 000064,
  000065 and 000066 against a live PostgreSQL schema.

  The fixture is a copy of a released file rather than something generated. It
  describes a state that existed and does not go stale — a later release
  changing the current baseline does not change what 5.8.0 shipped. When the gap
  grows enough that three migrations become thirty, the fixture should move
  forward to a newer release rather than accumulate.
*/

/**
 * A PostgreSQL schema holding the 5.8.0 baseline, ready to be migrated.
 *
 * Deliberately not wallos_test_open_pgsql_database(): that installs the
 * *current* baseline, which is the state this case exists to avoid starting
 * from.
 *
 * @return WallosDatabase
 */
function pgsql_upgrade_open_old_database()
{
    require_once WALLOS_ROOT . '/includes/database/pgsql/database.php';
    require_once WALLOS_ROOT . '/includes/database/configuration.php';

    // uniqid, so the name is this process's own and no request can reach it.
    $schema = 'wallos_upgrade_' . str_replace('.', '', uniqid('', true));
    wallos_test_pgsql_env($schema);
    wallos_test_pgsql_reachable();

    $db = wallos_database_connect();

    $create = 'CREATE SCHEMA ' . $schema;
    $use = 'SET search_path TO ' . $schema;
    $db->exec($create);
    $db->exec($use);
    $db->exec(file_get_contents(WALLOS_ROOT . '/tests/fixtures/pgsql-baseline-5.8.0.sql'));

    // Recorded only once the schema exists, so the cleanup at the end of the
    // run never tries to drop a name that was never created.
    $GLOBALS['wallos_test_pgsql_schemas'][] = $schema;

    return $db;
}

/**
 * Every column of every base table, as "table.column type", sorted.
 *
 * Columns rather than table names, because the drift worth catching is not
 * only a missing table. A migration that adds a column the baseline spells
 * differently, or declares as a different type, produces two populations that
 * every later migration then has to cope with — and the table list looks
 * identical throughout.
 *
 * Asked with current_schema() rather than with a name pasted into the query, so
 * the answer cannot disagree with the connection's own search path.
 *
 * @param WallosDatabase $db
 * @return string[]
 */
function pgsql_upgrade_columns($db)
{
    $columns = [];
    $result = $db->query("SELECT c.table_name, c.column_name, c.data_type"
        . " FROM information_schema.columns c"
        . " JOIN information_schema.tables t"
        . "   ON t.table_schema = c.table_schema AND t.table_name = c.table_name"
        . " WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'"
        . " ORDER BY c.table_name, c.column_name");

    while ($result !== false && $row = $result->fetchArray()) {
        $columns[] = $row['table_name'] . '.' . $row['column_name'] . ' ' . $row['data_type'];
    }

    return $columns;
}

wallos_test('the migration chain runs against a PostgreSQL database from 5.8.0', function () {
    if (wallos_test_skip_unless_pgsql('the baseline installs on PostgreSQL only')) {
        return;
    }

    $db = pgsql_upgrade_open_old_database();

    // Where 5.8.0 left off: no cron reporting yet, and the notifications table
    // migration 000016 could never drop still present.
    $before = (int) $db->scalar('SELECT COUNT(*) FROM migrations');
    assert_true($db->tableExists('notifications'), 'the 5.8.0 database carries the leftover table');
    assert_true(!$db->tableExists('cron_runs'), 'and has no cron reporting yet');

    ob_start();
    require WALLOS_ROOT . '/includes/run_migrations.php';
    $output = ob_get_clean();

    assert_same(null, $migrationFailure, 'every migration reported success: ' . trim($output));

    // What the migrations between 5.8.0 and here are supposed to have done,
    // checked one at a time rather than by counting rows.
    assert_true($db->tableExists('cron_runs'), '000064 created the cron reporting table');
    assert_true(!$db->tableExists('notifications'), '000065 removed the leftover table');

    foreach (['last_failure_at', 'last_failure_detail', 'failure_count'] as $column) {
        assert_true($db->columnExists('cron_runs', $column), '000066 added cron_runs.' . $column);
    }

    $after = (int) $db->scalar('SELECT COUNT(*) FROM migrations');
    assert_true($after > $before, 'the chain recorded what it applied (' . $before . ' -> ' . $after . ')');

    $db->close();
});

wallos_test('an upgraded database ends up where a fresh install starts', function () {
    // The property worth holding beyond "no error": upgrading and installing
    // fresh must produce the same schema, or the two populations diverge and
    // every later migration has to cope with both. This is the check that would
    // have caught the leftover table before 000065 — a fresh 5.8.2 install had
    // 42 tables and an upgraded one 43, and the difference was found by a
    // person counting.
    if (wallos_test_skip_unless_pgsql('compares two PostgreSQL schemas')) {
        return;
    }

    $db = pgsql_upgrade_open_old_database();

    ob_start();
    require WALLOS_ROOT . '/includes/run_migrations.php';
    ob_end_clean();

    $upgraded = pgsql_upgrade_columns($db);
    $db->close();

    $fresh = wallos_test_open_database();
    $freshColumns = pgsql_upgrade_columns($fresh);
    $fresh->close();

    assert_true(count($freshColumns) > 200, 'the fresh schema was read');
    assert_same([], array_values(array_diff($freshColumns, $upgraded)),
        'an upgraded database has every column a fresh one has');
    assert_same([], array_values(array_diff($upgraded, $freshColumns)),
        'and no column a fresh one does not');
});

wallos_test('migrations after the 5.8.0 baseline speak both dialects', function () {
    // The baseline records the chain up to 000063 (see the case above), so
    // everything after it runs on PostgreSQL upgrades too. A SQLite-only
    // construct there passes every SQLite run and surfaces as forty
    // downstream failures on the other backend — 000068's first draft used
    // pragma_table_info() and did exactly that. This gate names the file
    // and the word instead. Blunt on purpose: it matches comments too, and
    // a comment spelling a forbidden construct in a migration is worth
    // rewording.
    $forbidden = [
        'pragma_table_info' => 'use $db->columnExists()',
        'sqlite_master' => 'use $db->tableExists()',
        'AUTOINCREMENT' => 'PostgreSQL rejects it; the boundary maps ids',
        'INSERT OR ' => 'use ON CONFLICT, which both backends accept',
    ];

    $offenders = [];
    $scanned = 0;

    foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $path) {
        if ((int) basename($path, '.php') <= 63) {
            continue;
        }

        $scanned++;
        $source = file_get_contents($path);

        foreach ($forbidden as $construct => $fix) {
            if (stripos($source, $construct) !== false) {
                $offenders[] = basename($path) . ': ' . $construct . ' — ' . $fix;
            }
        }
    }

    assert_true($scanned >= 4, 'the scan found the post-baseline migrations (' . $scanned . ')');
    assert_same([], $offenders, 'post-baseline migrations must run on both backends');
});
