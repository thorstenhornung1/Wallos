<?php
/*
  Moving an existing SQLite installation into PostgreSQL.

  Issue #79. Most of what can go wrong here is quiet: a sequence left at 1 works
  perfectly until the first new subscription, a foreign key SQLite never enforced
  rejects rows PostgreSQL has every right to reject, and a half-finished copy
  looks exactly like a finished one from the outside.

  The cases below are in two groups. The first needs nothing but SQLite and runs
  everywhere, because the ordering, the orphan search and the guards are the
  parts that decide whether the copy is even attempted. The second needs a real
  PostgreSQL server and stands aside without one, since dev/test.sh runs in a
  container with no route to the database.
*/

require_once WALLOS_ROOT . '/dev/migrate-to-pgsql.php';

// ------------------------------------------------------------------- options

wallos_test('the option parser understands exactly the flags it documents', function () {
    $parsed = wallos_migrate_parse_options([]);
    assert_same(null, $parsed['error'], 'no arguments is not an error');
    assert_same(false, $parsed['options']['dry-run'], 'a copy is not a dry run by default');
    assert_same(false, $parsed['options']['allow-non-empty'], 'a non-empty target is refused by default');
    assert_same(false, $parsed['options']['skip-orphans'], 'orphans are refused by default');
    assert_same(null, $parsed['options']['source'], 'the source defaults to the configured path');

    $parsed = wallos_migrate_parse_options(['--dry-run', '--skip-orphans', '--allow-non-empty']);
    assert_same(null, $parsed['error'], 'the three flags parse together');
    assert_true($parsed['options']['dry-run'] && $parsed['options']['skip-orphans']
        && $parsed['options']['allow-non-empty'], 'all three are set');

    $parsed = wallos_migrate_parse_options(['--source', '/tmp/wallos.db', '--schema', 'staging']);
    assert_same('/tmp/wallos.db', $parsed['options']['source'], '--source takes the next argument');
    assert_same('staging', $parsed['options']['schema'], '--schema takes the next argument');

    $parsed = wallos_migrate_parse_options(['--source']);
    assert_contains('needs a value', $parsed['error'], 'a flag missing its argument is an error');

    $parsed = wallos_migrate_parse_options(['--force']);
    assert_contains('Unknown argument', $parsed['error'], 'an unknown flag is refused rather than ignored');

    // The schema name is interpolated into SET search_path, which takes an
    // identifier and not a parameter, so anything that is not one is refused
    // before it reaches the connection.
    $parsed = wallos_migrate_parse_options(['--schema', 'public; DROP TABLE "user"']);
    assert_contains('plain identifier', $parsed['error'], 'a schema name that is not an identifier is refused');
});

// ------------------------------------------------------------------ ordering

wallos_test('the copy order puts every referenced table before the one referencing it', function () {
    $keys = [
        ['name' => 'a', 'child_table' => 'subscriptions', 'child_column' => 'user_id',
         'parent_table' => 'user', 'parent_column' => 'id', 'columns' => 1],
        ['name' => 'b', 'child_table' => 'user', 'child_column' => 'main_currency',
         'parent_table' => 'currencies', 'parent_column' => 'id', 'columns' => 1],
    ];

    $ordering = wallos_migrate_table_order(['subscriptions', 'user', 'currencies', 'cycles'], $keys);

    assert_same(false, $ordering['cycle'], 'this graph has no cycle');
    assert_true(array_search('currencies', $ordering['order'], true) < array_search('user', $ordering['order'], true),
        'currencies is copied before user, which references it');
    assert_true(array_search('user', $ordering['order'], true) < array_search('subscriptions', $ordering['order'], true),
        'user is copied before subscriptions');
    assert_same(4, count($ordering['order']), 'every table is in the order exactly once');
});

wallos_test('a cycle in the foreign keys is reported rather than ordered around', function () {
    // None exists today. If one is ever added, the copy cannot succeed by
    // ordering alone and has to say so instead of producing an order that looks
    // fine and fails at the first row.
    $keys = [
        ['name' => 'a', 'child_table' => 'left', 'child_column' => 'r',
         'parent_table' => 'right', 'parent_column' => 'id', 'columns' => 1],
        ['name' => 'b', 'child_table' => 'right', 'child_column' => 'l',
         'parent_table' => 'left', 'parent_column' => 'id', 'columns' => 1],
    ];

    $ordering = wallos_migrate_table_order(['left', 'right'], $keys);

    assert_same(true, $ordering['cycle'], 'the cycle is detected');
    assert_same(2, count($ordering['order']), 'both tables are still listed, so nothing is silently dropped');
});

wallos_test('a self-reference does not stop a table being ordered', function () {
    $keys = [
        ['name' => 'a', 'child_table' => 'subscriptions', 'child_column' => 'replacement_subscription_id',
         'parent_table' => 'subscriptions', 'parent_column' => 'id', 'columns' => 1],
    ];

    $ordering = wallos_migrate_table_order(['subscriptions'], $keys);

    assert_same(false, $ordering['cycle'], 'a table referencing itself is not a cycle for this purpose');
    assert_same(['subscriptions'], $ordering['order'], 'and it is still copied');
});

// -------------------------------------------------------------- the baseline

wallos_test('the seeded-row counts are read from the baseline schema itself', function () {
    $counts = wallos_migrate_baseline_seed_counts();

    // A fresh PostgreSQL installation is not empty, and the emptiness guard is
    // only as good as its idea of what a fresh one looks like.
    assert_same(['admin', 'categories', 'currencies', 'cycles', 'frequencies', 'migrations',
        'payment_methods', 'settings'], array_keys($counts),
        'exactly the tables schema.sql seeds are counted');

    foreach ($counts as $table => $count) {
        assert_true($count > 0, $table . ' is seeded with at least one row');
    }

    assert_same(1, $counts['admin'], 'one admin row');
    assert_same(1, $counts['settings'], 'one settings row');
    assert_true($counts['migrations'] >= 62, 'every historical migration is recorded as applied');
});

wallos_test('counting seeded rows survives quotes and brackets inside the values', function () {
    // The reference data contains apostrophes — "O''Brien" — and bracketed
    // names, which is why this is a scan and not a regular expression.
    $sql = 'INSERT INTO "x" ("a", "b") VALUES' . "\n"
        . "    (1, 'plain'),\n"
        . "    (2, 'O''Brien (the third)'),\n"
        . "    (3, 'semicolon; inside');\n"
        . 'INSERT INTO "y" ("a") VALUES' . "\n    (1);\n";

    $counts = wallos_migrate_baseline_seed_counts(migrate_test_write_file('seed-counts.sql', $sql));

    assert_same(3, $counts['x'], 'three rows despite the quoting');
    assert_same(1, $counts['y'], 'and the next statement is counted separately');
});

// --------------------------------------------------------------- the source

wallos_test('the source database is opened read-only and is never modified', function () {
    $path = wallos_test_database();
    $before = wallos_migrate_source_stat($path);

    $source = wallos_migrate_open_source($path);

    assert_same('sqlite', $source->driver(), 'the source is always SQLite');
    assert_true((int) $source->scalar('SELECT COUNT(*) FROM cycles') > 0, 'it can still be read');

    // The guarantee is the open flag, not a promise in a comment: a write
    // through this handle has to fail.
    $written = @$source->exec("INSERT INTO cycles (days, name) VALUES (999, 'never written')");
    assert_same(false, $written, 'a write through the source handle is refused');

    $source->close();

    assert_same($before, wallos_migrate_source_stat($path), 'the file is the size and age it was');
});

// ------------------------------------------------------------- orphaned rows

wallos_test('orphaned rows are found by following the foreign keys to a fixpoint', function () {
    // SQLite has never enforced a foreign key in this project, so a real
    // database contains rows PostgreSQL will reject. Finding them takes more
    // than one pass: removing a user because its currency is gone orphans
    // everything that pointed at the user.
    $path = wallos_test_database();
    $db = wallos_database_connect($path);
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    // Rows like these predate enforcement (#92); pausing it is how the case
    // builds the database this migration path exists to clean up.
    $currency = (int) $db->scalar('SELECT main_currency FROM "user" WHERE id = 1');
    $db->setForeignKeyEnforcement(false);
    $db->exec('DELETE FROM currencies WHERE id = ' . $currency);
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (1, 'alice-token')");
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (2, 'bob-token')");
    $db->close();

    $keys = [
        ['name' => 'user_main_currency_fkey', 'child_table' => 'user', 'child_column' => 'main_currency',
         'parent_table' => 'currencies', 'parent_column' => 'id', 'columns' => 1],
        ['name' => 'login_tokens_user_id_fkey', 'child_table' => 'login_tokens', 'child_column' => 'user_id',
         'parent_table' => 'user', 'parent_column' => 'id', 'columns' => 1],
    ];

    $source = wallos_migrate_open_source($path);
    $orphans = wallos_migrate_orphans($source, $keys, wallos_migrate_source_tables($source));
    $source->close();

    assert_same(1, count($orphans['skipped']['user'] ?? []), 'the user whose currency is gone is an orphan');
    assert_same(1, count($orphans['skipped']['login_tokens'] ?? []),
        'and so is the token pointing at that user, which only a second pass finds');
    assert_true(isset($orphans['constraints']['user_main_currency_fkey']),
        'the constraint that rejected it is named');
    assert_true(isset($orphans['constraints']['login_tokens_user_id_fkey']),
        'and so is the one behind the knock-on orphan');
});

wallos_test('a database with no violations reports none', function () {
    $path = wallos_test_database();
    $db = wallos_database_connect($path);
    wallos_test_create_user($db, 1, 'alice');
    $db->close();

    $keys = [
        ['name' => 'user_main_currency_fkey', 'child_table' => 'user', 'child_column' => 'main_currency',
         'parent_table' => 'currencies', 'parent_column' => 'id', 'columns' => 1],
    ];

    $source = wallos_migrate_open_source($path);
    $orphans = wallos_migrate_orphans($source, $keys, wallos_migrate_source_tables($source));
    $source->close();

    assert_same([], $orphans['skipped'], 'a consistent database has nothing to skip');
    assert_same([], $orphans['constraints'], 'and nothing to report');
});

// ---------------------------------------------------------------- PostgreSQL

wallos_test('a dry run reports what it would copy and writes nothing', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();
    $before = migrate_test_counts($target);

    $report = migrate_test_run($source, $target, ['dry-run' => true]);

    assert_same(true, $report['result']['ok'], 'the dry run succeeds');
    assert_contains('nothing was written', $report['output'], 'it says so');
    assert_contains('subscriptions', $report['output'], 'and names the tables it would copy');
    assert_same($before, migrate_test_counts($target), 'every row count in the target is unchanged');

    $target->close();
});

wallos_test('it refuses a target that already holds data unless told otherwise', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();

    // A freshly installed database is not empty — schema.sql seeds the
    // reference data — so this has to pass the first time and refuse the second.
    $first = migrate_test_run($source, $target, []);
    assert_same(true, $first['result']['ok'], 'a fresh installation counts as empty and is copied into');

    $second = migrate_test_run($source, $target, []);
    assert_same(false, $second['result']['ok'], 'a second run is refused');
    assert_contains('already holds data', $second['result']['error'], 'and says why');
    assert_contains('--allow-non-empty', $second['result']['error'], 'and how to override it');

    $third = migrate_test_run($source, $target, ['allow-non-empty' => true]);
    assert_same(true, $third['result']['ok'], '--allow-non-empty replaces the contents');
    assert_same(1, (int) $target->scalar('SELECT COUNT(*) FROM "user"'),
        'and the target holds one copy of the source, not two');

    $target->close();
});

wallos_test('every row arrives and the per-table counts are reported, not assumed', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();

    $report = migrate_test_run($source, $target, []);

    assert_same(true, $report['result']['ok'], 'the copy succeeds');
    assert_contains('Verification, per table, after the commit', $report['output'],
        'the comparison is printed rather than summarised as success');

    foreach (migrate_test_source_counts($source) as $table => $rows) {
        if ($table === 'migrations') {
            // Deliberately not copied: the baseline records every migration as
            // applied so the SQLite chain never runs against PostgreSQL.
            continue;
        }

        assert_same($rows, (int) $target->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table)),
            $table . ' has the same number of rows on both sides');
    }

    // The reserved words, which is where an unquoted identifier would have
    // failed: `user` is a table and `order` is a column in two of them.
    assert_same('alice', $target->scalar('SELECT username FROM "user" WHERE id = 1'), 'the user table copied');
    assert_true($target->scalar('SELECT "order" FROM categories WHERE user_id = 1 LIMIT 1') !== null,
        'the order column copied');

    // What a row count cannot see. dev/stress-verify.php hashes all of this
    // across every table; these two are here so a copy that mangles quoting or
    // flattens NULL into '' fails in the suite as well.
    assert_same("O'Brien's \"Streaming\"",
        $target->scalar("SELECT name FROM subscriptions WHERE price = 9.99"),
        'both quote characters survive');
    assert_same(null, $target->scalar("SELECT start_date FROM subscriptions WHERE price = 0.01"),
        'a NULL stays NULL rather than becoming an empty string');
    assert_same('', $target->scalar("SELECT notes FROM subscriptions WHERE price = 0.01"),
        'and an empty string stays an empty string rather than becoming NULL');

    $target->close();
});

wallos_test('every sequence is set past the ids that were copied', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    // The case the whole issue is about. Rows copied with explicit ids leave
    // the sequences at 1, and the failure surfaces at the first new
    // subscription as a duplicate key error naming a constraint.
    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();

    $report = migrate_test_run($source, $target, []);
    assert_same(true, $report['result']['ok'], 'the copy succeeds');
    assert_contains('Sequences', $report['output'], 'the sequences are reported');

    assert_true(count($report['result']['sequences']) > 0, 'there are sequences to fix');

    foreach ($report['result']['sequences'] as $sequence) {
        assert_same(true, $sequence['ok'], $sequence['column'] . ' is set');
        assert_same($sequence['max'] + 1, $sequence['next'],
            $sequence['column'] . ' hands out the id after the highest one copied');
    }

    // Reporting it is not proving it. Every sequence-backed table gets a real
    // insert, which is the step that catches the failure.
    foreach (migrate_test_insert_one_row_everywhere($target) as $inserted) {
        assert_same(true, $inserted['ok'], sprintf(
            'a new row in %s got id %s, past the highest copied id %d%s',
            $inserted['table'], var_export($inserted['id'], true), $inserted['before'],
            $inserted['error'] === null ? '' : ' — ' . $inserted['error']
        ));
    }

    $target->close();
});

wallos_test('an unfixed sequence really does collide, which is what the fix prevents', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    // Without this the case above proves only that inserting works, not that
    // the sequence fix is what made it work.
    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();

    migrate_test_run($source, $target, []);

    $sequence = $target->scalar("SELECT pg_get_serial_sequence('subscriptions', 'id')");
    $target->scalar('SELECT setval(:s, 1, false)', [':s' => $sequence]);

    $references = wallos_test_user_references($target, 1);
    $statement = $target->prepare('INSERT INTO subscriptions (name, price, currency_id, cycle, frequency,
                                   payer_user_id, category_id, payment_method_id, user_id)
                                   VALUES (:name, 1, :currency, 1, 1, :payer, :category, :payment, 1)');
    $statement->bindValue(':name', 'after the import');
    $statement->bindValue(':currency', (int) $target->scalar('SELECT main_currency FROM "user" WHERE id = 1'));
    $statement->bindValue(':payer', $references['household']);
    $statement->bindValue(':category', $references['category']);
    $statement->bindValue(':payment', $references['payment_method']);

    assert_same(false, $statement->execute(), 'with the sequence back at 1 the insert is refused');
    assert_contains('duplicate key', $target->lastErrorMsg(),
        'and the error names a constraint rather than the import that caused it');

    $target->close();
});

wallos_test('a failure part-way through leaves nothing behind', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $target = wallos_test_open_pgsql_database();
    $before = migrate_test_counts($target);

    // A byte SQLite stores without comment and PostgreSQL will not accept. It
    // goes into a table copied late, so the transaction is well under way when
    // it fails.
    $db = wallos_database_connect($source);
    $statement = $db->prepare('UPDATE subscriptions SET name = :name');
    $statement->bindValue(':name', "latin-1 caf\xE9", SQLITE3_TEXT);
    $statement->execute();
    $db->close();

    $report = migrate_test_run($source, $target, []);

    assert_same(false, $report['result']['ok'], 'the copy fails');
    assert_contains('nothing was written', $report['result']['error'], 'and says nothing was written');
    assert_contains('subscriptions row', $report['result']['error'], 'the failing row is named');
    assert_contains('not valid UTF-8: name', $report['result']['error'], 'and so is the column');
    assert_same($before, migrate_test_counts($target),
        'the target is exactly as it was, not half-migrated');

    $target->close();
});

wallos_test('orphans are refused by default and counted when skipped', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $db = wallos_database_connect($source);
    // A row that predates enforcement (#92); pausing it is how the case
    // builds the source database this flag exists for.
    $db->setForeignKeyEnforcement(false);
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (4242, 'no such user')");
    $db->close();

    $target = wallos_test_open_pgsql_database();

    $refused = migrate_test_run($source, $target, []);
    assert_same(false, $refused['result']['ok'], 'a row PostgreSQL will reject stops the migration');
    assert_contains('never dropped silently', $refused['result']['error'], 'and nothing is discarded quietly');
    assert_contains('login_tokens_user_id_fkey', $refused['output'], 'the constraint is named');

    $skipped = migrate_test_run($source, $target, ['skip-orphans' => true]);
    assert_same(true, $skipped['result']['ok'], '--skip-orphans copies the rest');
    assert_same(1, $skipped['result']['tables']['login_tokens']['skipped'], 'the skipped row is counted');
    assert_contains('left behind', $skipped['output'],
        'and the verification says so rather than reporting a clean run');

    $target->close();
});

wallos_test('booleans arrive as the integers Wallos writes and compares', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    $source = migrate_test_source();
    $db = wallos_database_connect($source);
    $db->exec('UPDATE settings SET dark_theme = 1, monthly_price = 0 WHERE user_id = 1');
    $db->close();

    $target = wallos_test_open_pgsql_database();
    migrate_test_run($source, $target, []);

    // Not "is it truthy": the application writes 0 and 1 and compares with
    // == 1, so a real boolean coming back as true would pass a loose check and
    // change the meaning of every one of those comparisons.
    assert_same([], wallos_migrate_boolean_columns(wallos_migrate_target_columns($target)),
        'no column in the target is a real boolean');
    assert_equals(1, $target->scalar('SELECT dark_theme FROM settings WHERE user_id = 1'), 'a set flag is 1');
    assert_equals(0, $target->scalar('SELECT monthly_price FROM settings WHERE user_id = 1'), 'a clear flag is 0');

    $target->close();
});

wallos_test('a source behind the baseline is refused rather than copied incompletely', function () {
    if (wallos_test_skip_unless_pgsql('needs a PostgreSQL server to copy into')) {
        return;
    }

    // A stale SQLite database has fewer columns than the baseline expects, and
    // the copy would succeed while leaving them at their defaults — invisible
    // in every row count.
    $source = migrate_test_source();
    $db = wallos_database_connect($source);
    $db->exec('DELETE FROM migrations WHERE migration = (SELECT MAX(migration) FROM migrations)');
    $db->close();

    $target = wallos_test_open_pgsql_database();
    $report = migrate_test_run($source, $target, ['dry-run' => true]);

    assert_same(false, $report['result']['ok'], 'the migration is refused');
    assert_contains('behind the target schema', $report['result']['error'], 'and says which side is stale');

    $target->close();
});

// ------------------------------------------------------------------ fixtures

/**
 * A SQLite database with enough in it to exercise the copy.
 *
 * @return string path to the database file
 */
function migrate_test_source()
{
    $path = wallos_test_database();
    $db = wallos_database_connect($path);

    wallos_test_create_user($db, 1, 'alice');
    $references = wallos_test_user_references($db, 1);
    $currency = (int) $db->scalar('SELECT main_currency FROM "user" WHERE id = 1');

    $statement = $db->prepare('INSERT INTO subscriptions (name, price, currency_id, cycle, frequency,
                               payer_user_id, category_id, payment_method_id, user_id, notes, url, start_date)
                               VALUES (:name, :price, :currency, 3, 1, :payer, :category, :payment, 1, :notes, :url, :start)');

    // Values chosen the way dev/stress-seed.php chooses them: both quote
    // characters, an emoji outside the BMP, an empty string that is not NULL,
    // and a NULL that is not an empty string.
    foreach ([
        ["O'Brien's \"Streaming\"", 9.99, 'back\\slash and %percent%', 'https://example.com/1', '2025-01-01'],
        ['日本語 🎬 emoji', 0.01, '', '', null],
    ] as $subscription) {
        $statement->bindValue(':name', $subscription[0], SQLITE3_TEXT);
        $statement->bindValue(':price', $subscription[1], SQLITE3_FLOAT);
        $statement->bindValue(':currency', $currency, SQLITE3_INTEGER);
        $statement->bindValue(':payer', $references['household'], SQLITE3_INTEGER);
        $statement->bindValue(':category', $references['category'], SQLITE3_INTEGER);
        $statement->bindValue(':payment', $references['payment_method'], SQLITE3_INTEGER);
        $statement->bindValue(':notes', $subscription[2], SQLITE3_TEXT);
        $statement->bindValue(':url', $subscription[3], SQLITE3_TEXT);
        $statement->bindValue(':start', $subscription[4], $subscription[4] === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $statement->execute();
    }

    $db->exec("INSERT INTO user_roles (user_id, role, source) VALUES (1, 'admin', 'local')");
    $db->close();

    return $path;
}

/**
 * Runs the migration with the output captured, so a case can assert on the
 * report as well as on the result.
 *
 * @param string         $sourcePath
 * @param WallosDatabase $target
 * @param array          $options only the ones that differ from the defaults
 * @return array{result: array, output: string}
 */
function migrate_test_run($sourcePath, $target, $options)
{
    $parsed = wallos_migrate_parse_options([]);

    ob_start();
    $result = wallos_migrate_run($sourcePath, $target, array_merge($parsed['options'], $options));
    $output = ob_get_clean();

    return ['result' => $result, 'output' => $output];
}

/**
 * Every table in the target and how many rows it holds.
 *
 * @param WallosDatabase $target
 * @return array<string, int>
 */
function migrate_test_counts($target)
{
    $counts = [];

    foreach (wallos_migrate_target_tables($target) as $table) {
        $counts[$table] = (int) $target->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table));
    }

    return $counts;
}

/**
 * @param string $sourcePath
 * @return array<string, int>
 */
function migrate_test_source_counts($sourcePath)
{
    $source = wallos_migrate_open_source($sourcePath);
    $counts = [];

    foreach (wallos_migrate_source_tables($source) as $table) {
        $counts[$table] = (int) $source->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table));
    }

    $source->close();

    return $counts;
}

/**
 * Inserts one row into every table backed by a sequence.
 *
 * The columns are filled in from the schema rather than by hand: a table added
 * later has to be covered by this without anyone remembering to add it, which
 * is the whole reason the sequence problem goes unnoticed in the first place.
 *
 * @param WallosDatabase $target
 * @return array<int, array{table: string, before: int, id: mixed, ok: bool, error: string|null}>
 */
function migrate_test_insert_one_row_everywhere($target)
{
    $columns = wallos_migrate_target_columns($target);
    $parents = [];

    foreach (wallos_migrate_target_foreign_keys($target) as $key) {
        $parents[$key['child_table'] . '.' . $key['child_column']] = [$key['parent_table'], $key['parent_column']];
    }

    $inserted = [];

    foreach (wallos_migrate_target_sequences($target) as $sequence) {
        $table = $sequence['table'];
        $id = $sequence['column'];
        $before = (int) $target->scalar('SELECT COALESCE(MAX(' . wallos_migrate_quote($id) . '), 0) FROM '
            . wallos_migrate_quote($table));

        $names = [];
        $values = [];

        foreach ($columns[$table] as $name => $definition) {
            // Anything nullable or defaulted can be left out; what has to be
            // supplied is the columns that would otherwise refuse the row for a
            // reason that has nothing to do with the sequence.
            if ($name === $id || $definition['nullable'] || $definition['default'] !== null) {
                continue;
            }

            $key = $table . '.' . $name;

            if (isset($parents[$key])) {
                $value = $target->scalar('SELECT MIN(' . wallos_migrate_quote($parents[$key][1]) . ') FROM '
                    . wallos_migrate_quote($parents[$key][0]));
            } elseif (in_array($definition['type'], ['smallint', 'integer', 'bigint'], true)) {
                $value = 1;
            } elseif (in_array($definition['type'], ['real', 'double precision', 'numeric'], true)) {
                $value = 1.5;
            } else {
                $value = 'after the import';
            }

            $names[] = wallos_migrate_quote($name);
            $values[] = $value;
        }

        $placeholders = [];
        foreach ($values as $index => $value) {
            $placeholders[] = ':p' . $index;
        }

        $sql = $names === []
            ? 'INSERT INTO ' . wallos_migrate_quote($table) . ' DEFAULT VALUES RETURNING ' . wallos_migrate_quote($id)
            : 'INSERT INTO ' . wallos_migrate_quote($table) . ' (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', $placeholders) . ') RETURNING ' . wallos_migrate_quote($id);

        $statement = $target->prepare($sql);
        foreach ($values as $index => $value) {
            $statement->bindValue(':p' . $index, $value);
        }

        $result = $statement->execute();
        $row = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);

        $inserted[] = [
            'table' => $table,
            'before' => $before,
            'id' => $row === false ? null : (int) $row[$id],
            'ok' => $row !== false && (int) $row[$id] > $before,
            'error' => $row === false ? $target->lastErrorMsg() : null,
        ];
    }

    return $inserted;
}

/**
 * @param string $name
 * @param string $contents
 * @return string path to the written file
 */
function migrate_test_write_file($name, $contents)
{
    $directory = WALLOS_TEST_TMP . '/migrate';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $path = $directory . '/' . $name;
    file_put_contents($path, $contents);

    return $path;
}
