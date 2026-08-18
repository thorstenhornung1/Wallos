<?php

/**
 * Bringing a fresh PostgreSQL database up to the current schema.
 *
 * SQLite installations are built by createdatabase.php and then walked forward
 * by the 63 migrations. PostgreSQL cannot follow that path: the migrations are
 * written in SQLite's dialect, and replaying a decade of schema history to
 * arrive at a shape we already know is work with no payoff.
 *
 * So a PostgreSQL install starts from includes/database/pgsql/schema.sql, which
 * carries the current schema, the reference data a fresh install needs, and a
 * `migrations` table already recording every historical migration as applied —
 * so run_migrations.php finds nothing to do and the chain stays inert.
 *
 * Without this, a fresh pgsql container reaches run_migrations.php against an
 * empty database, applies migration 000001 (which happens to be portable), and
 * dies inside 000002.
 */

/**
 * Whether this database still needs the baseline.
 *
 * The migrations table is the marker: schema.sql creates it and fills it, so
 * its absence means nothing has been applied.
 *
 * @param WallosDatabase $db
 * @return bool
 */
function wallos_pgsql_needs_baseline($db)
{
    return !$db->tableExists('migrations');
}

/**
 * Apply the baseline.
 *
 * Runs inside a transaction: a half-applied schema is worse than none, because
 * the next start would find the migrations table present and conclude there was
 * nothing to do.
 *
 * @param WallosDatabase $db
 * @return array{applied: bool, error: string|null}
 */
function wallos_pgsql_apply_baseline($db)
{
    $path = __DIR__ . '/schema.sql';

    if (!is_readable($path)) {
        return ['applied' => false, 'error' => 'Baseline schema is missing: ' . $path];
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return ['applied' => false, 'error' => 'Baseline schema could not be read: ' . $path];
    }

    $db->beginTransaction();

    if ($db->exec($sql) === false) {
        $db->rollBack();

        return ['applied' => false, 'error' => 'Baseline schema failed to apply: ' . $db->lastErrorMsg()];
    }

    $db->commit();

    // The planner assumes an empty table until it has statistics, and the
    // reference tables ship with rows. One ANALYZE here costs milliseconds and
    // saves the first few queries from a plan built on nothing.
    $db->exec('ANALYZE');

    return ['applied' => true, 'error' => null];
}

/**
 * Install the baseline if this database has never been set up.
 *
 * @param WallosDatabase $db
 * @return void
 */
function wallos_pgsql_install_if_needed($db)
{
    if ($db->driver() !== 'pgsql' || !wallos_pgsql_needs_baseline($db)) {
        return;
    }

    echo "PostgreSQL database is empty. Applying the baseline schema...\n";
    $result = wallos_pgsql_apply_baseline($db);

    if (!$result['applied']) {
        // Continuing would run the SQLite migration chain against PostgreSQL,
        // which fails at the second migration and leaves a partial schema.
        fwrite(STDERR, $result['error'] . "\n");
        exit(1);
    }

    echo "Baseline schema applied.\n";
}
