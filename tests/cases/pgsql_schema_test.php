<?php
/*
  The PostgreSQL baseline schema, and whether it still describes the database
  the migration chain actually produces.

  includes/database/pgsql/schema.sql is generated rather than written, because a
  hand-maintained copy of a schema that sixty-odd migrations keep changing is
  wrong the first week nobody remembers it exists. This case regenerates it from
  the current migration chain and fails on any difference, so migration 000064
  cannot land while the baseline still describes the database before it.
*/

require_once WALLOS_ROOT . '/dev/generate-pgsql-schema.php';

/**
 * The baseline as it would be generated from the current migration chain.
 *
 * @return string
 */
function pgsql_schema_generated()
{
    static $generated = null;

    if ($generated === null) {
        $generated = wallos_pgsql_schema_generate(wallos_test_database());
    }

    return $generated;
}

/**
 * The baseline as checked in.
 *
 * @return string
 */
function pgsql_schema_checked_in()
{
    $path = wallos_pgsql_schema_path();

    return is_file($path) ? file_get_contents($path) : '';
}

/**
 * Where two versions of the schema first disagree, for a failure message that
 * points at the change instead of printing two thousand lines.
 *
 * @param string $expected
 * @param string $actual
 * @return string
 */
function pgsql_schema_difference($expected, $actual)
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);

    $count = max(count($expectedLines), count($actualLines));
    for ($line = 0; $line < $count; $line++) {
        $left = $expectedLines[$line] ?? '(end of file)';
        $right = $actualLines[$line] ?? '(end of file)';

        if ($left !== $right) {
            return sprintf("first difference on line %d:\n          checked in: %s\n          generated:  %s",
                $line + 1, $left, $right);
        }
    }

    return 'no line differs';
}

wallos_test('the checked-in PostgreSQL baseline matches the current schema', function () {
    $checkedIn = pgsql_schema_checked_in();
    $generated = pgsql_schema_generated();

    assert_true($checkedIn !== '', 'includes/database/pgsql/schema.sql exists');
    assert_true(
        $checkedIn === $generated,
        'the baseline is stale — regenerate it with dev/generate-pgsql-schema.php. '
            . pgsql_schema_difference($checkedIn, $generated)
    );
});

wallos_test('the baseline records every migration as already applied', function () {
    $schema = pgsql_schema_checked_in();
    $migrations = wallos_pgsql_schema_migrations();

    assert_true(count($migrations) > 0, 'there are migrations to record');

    foreach ($migrations as $migration) {
        // run_migrations.php compares against exactly this string, so a
        // different spelling silently replays the whole chain on a fresh
        // PostgreSQL installation — against a schema that already has it.
        assert_contains(", '" . $migration . "')", $schema, 'the baseline marks ' . $migration . ' as applied');
    }
});

wallos_test('boolean columns are integers', function () {
    $schema = pgsql_schema_checked_in();

    // The decision this whole file turns on. Wallos writes 0 and 1 into these
    // columns and reads them back with == 1 in several hundred places; a real
    // BOOLEAN returns true and false and changes every one of those at once.
    assert_true(
        preg_match('/^\s+"[a-z_]+" BOOLEAN/m', $schema) === 0,
        'no column is declared BOOLEAN'
    );
    assert_contains('"notify" INTEGER DEFAULT 0', $schema, 'subscriptions.notify is an integer');
    assert_contains('"dark_theme" INTEGER DEFAULT 0', $schema, 'settings.dark_theme is an integer');
    assert_not_contains('DEFAULT false', $schema, 'no integer column defaults to a boolean literal');
});

wallos_test('date and time columns keep the storage the application expects', function () {
    $schema = pgsql_schema_checked_in();

    // Wallos stores and compares '2026-01-01' strings in these.
    assert_contains('"next_payment" TEXT', $schema, 'subscriptions.next_payment stays text');
    assert_contains('"cancellation_date" TEXT', $schema, 'subscriptions.cancellation_date stays text');
    assert_contains('"created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $schema, 'created_at columns are timestamps');
});

wallos_test('reserved words are quoted', function () {
    $schema = pgsql_schema_checked_in();

    // PostgreSQL reserves both. "user" is the dangerous one: unquoted it is not
    // a syntax error but the name of the connected role.
    assert_contains('CREATE TABLE "user" (', $schema, 'the user table is quoted');
    assert_contains('"order" INTEGER DEFAULT 0', $schema, 'the order columns are quoted');
    assert_not_contains('`', $schema, 'no SQLite backtick quoting survives into the PostgreSQL schema');
});

wallos_test('generated ids become sequences', function () {
    $schema = pgsql_schema_checked_in();

    assert_contains('"id" SERIAL PRIMARY KEY', $schema, 'integer primary keys are sequences');
    // Reference data is inserted with its original ids, which leaves the
    // sequence at 1 and the first real insert colliding with it.
    assert_contains("setval(pg_get_serial_sequence('payment_methods', 'id')", $schema,
        'seeded tables have their sequence moved past the seeded ids');
});

wallos_test('the type mapping refuses a type it has not been taught', function () {
    $types = wallos_pgsql_schema_types();

    assert_same('INTEGER', $types['BOOLEAN'], 'BOOLEAN maps to INTEGER');
    assert_same('DOUBLE PRECISION', $types['REAL'], 'REAL maps to DOUBLE PRECISION');
    assert_same('TEXT', $types['DATE'], 'DATE maps to TEXT');
    assert_same('VARCHAR(255)', $types['VARCHAR(255)'], 'VARCHAR(255) is unchanged');

    $failed = false;
    try {
        wallos_pgsql_schema_column_type(['name' => 'invented', 'type' => 'BLOB'], [], 'somewhere');
    } catch (RuntimeException $error) {
        $failed = true;
    }

    // Guessing at an unknown type is how a column silently ends up storing
    // something the application cannot read back.
    assert_true($failed, 'an unmapped type stops the generator instead of being guessed at');
});

wallos_test('the anchor date default does not carry the day it was generated', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $schema = pgsql_schema_checked_in();

    // migrations/000053.php builds its default out of the current date, so a
    // verbatim copy would change every midnight and this file would fail for a
    // reason that has nothing to do with the schema.
    //
    // The expression is to_char rather than a bare CURRENT_DATE because the
    // column is TEXT: CURRENT_DATE renders through the session DateStyle, so
    // with DateStyle=SQL,DMY a new row would get '18/08/2026', which
    // sanitizeBudgetAnchorDate() rejects and silently replaces with today.
    assert_contains('"budget_period_anchor_date" TEXT DEFAULT to_char(CURRENT_DATE', $schema,
        'the anchor date default is an expression, not the day of generation');
    assert_contains("'YYYY-MM-DD'", $schema,
        'and it pins the format the application parses');
    assert_true(
        preg_match('/DEFAULT \'\d{4}-\d{2}-\d{2}\'/', $schema) === 0,
        'no column default freezes a date into the baseline'
    );
});

wallos_test('columns declared INTEGER that hold text are overridden', function () {
    // SQLite's INTEGER is an affinity, not a constraint: a value that is not a
    // well-formed integer is stored as text and nothing complains. Three
    // columns were declared INTEGER and have never held integers. Trusting the
    // declaration produced a schema faithful to the DDL and wrong about the
    // data — every write to them failed with 'invalid input syntax for type
    // integer', which meant no subscription with a start date could be saved
    // and the cost-history cron wrote nothing.
    $schema = pgsql_schema_checked_in();

    assert_contains('"start_date" TEXT', $schema, 'subscriptions.start_date holds a date string');
    assert_contains('"date" TEXT NOT NULL', $schema, 'total_yearly_cost.date does too');
    assert_contains('"budget" DOUBLE PRECISION', $schema, 'user.budget holds money, not a whole number');
});

wallos_test('the frequency foreign key is not carried over', function () {
    // subscriptions.frequency is a multiplier, not a reference — getPricePerMonth()
    // divides by it, and getdbkeys.php builds the list in PHP as 1..366 while the
    // frequencies table holds 1..31. Carrying the key over rejected 'every 60
    // days', which the stock form offers, for every user.
    $schema = pgsql_schema_checked_in();

    assert_true(
        strpos($schema, 'FOREIGN KEY ("frequency")') === false,
        'no foreign key constrains frequency'
    );
});

wallos_test('an empty PostgreSQL database is installable', function () {
    // The baseline is only worth having if something applies it. Nothing did:
    // startup.sh calls createdatabase.php, which was SQLite-only, so a fresh
    // pgsql container reached run_migrations.php against an empty database and
    // died inside migration 000002.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/createdatabase.php');

    assert_true(strpos($source, 'wallos_pgsql_install_if_needed') !== false,
        'createdatabase.php installs the baseline on PostgreSQL');

    $installer = file_get_contents(WALLOS_ROOT . '/includes/database/pgsql/install.php');
    assert_true(strpos($installer, 'beginTransaction') !== false,
        'and applies it in a transaction, because a half-applied schema would look applied');
});
