<?php
// Expects $db to be set by the caller.
//
// A migration is recorded as applied when it says it worked and when that
// record itself is written. Neither used to be true: the file was required, the
// row was inserted, and "completed successfully" was printed, none of it
// conditional on anything (issue #87).
//
// That is not hypothetical here. Migration 000016 splits the notifications
// table and drops it, and the drop ran while its own read of that table was
// still open — SQLite refused, the exec result was not checked, and the
// migration recorded itself as applied with the table still in place on every
// installation ever made. It took 000065, nine years of releases later, to
// remove it.
//
// Three rules follow:
//
//   A migration that returns false has failed. `require` rather than
//   `require_once`, because once returns true on a repeat include instead of
//   the file's own value, and the value is the signal. Each file is in this
//   list exactly once, so there is nothing to guard against.
//
//   A migration whose record cannot be written has not been applied, whatever
//   it did. Recording it is what keeps it from running twice, and a migration
//   that is not idempotent would then do its work again.
//
//   Neither case continues to the next migration. Migrations build on each
//   other; running 000067 against a database where 000066 failed is how one
//   broken statement becomes a schema nobody can reason about. The failure is
//   reported and the run stops, so the next start retries from the same point.
//
// $migrationFailure is left set for the caller to read, and null when the run
// was clean.

$migrationsDir = __DIR__ . '/../migrations/';
$migrationFailure = null;

$completedMigrations = [];

$migrationTableExists = $db->tableExists('migrations');

if ($migrationTableExists) {
    $migrationQuery = $db->query('SELECT migration FROM migrations');
    while ($row = $migrationQuery->fetchArray(SQLITE3_ASSOC)) {
        $completedMigrations[] = str_replace('../../', '', $row['migration']);
    }
}

$allMigrations = array_map(
    fn($path) => 'migrations/' . basename($path),
    glob($migrationsDir . '*.php') ?: []
);

$requiredMigrations = array_diff($allMigrations, $completedMigrations);

if (count($requiredMigrations) === 0) {
    echo "No migrations to run.\n";
}

foreach ($requiredMigrations as $migration) {
    // A migration that finishes without returning anything yields 1, and one
    // that returns early because there is nothing to do yields null. Only an
    // explicit false means it could not do its work.
    $outcome = require $migrationsDir . basename($migration);

    if ($outcome === false) {
        $migrationFailure = $migration;

        echo sprintf("Migration %s failed and was not recorded; it will be retried on the next start."
            . " Later migrations were skipped.\n", $migration);
        error_log('Wallos: migration ' . $migration . ' reported failure; the run stopped there.');

        break;
    }

    $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $recorded = false;

    if ($stmt !== false) {
        $stmt->bindValue(':migration', $migration, SQLITE3_TEXT);
        $recorded = $stmt->execute() !== false;
    }

    if (!$recorded) {
        $migrationFailure = $migration;

        echo sprintf("Migration %s ran but could not be recorded, so it would run again."
            . " Later migrations were skipped.\n", $migration);
        error_log('Wallos: migration ' . $migration . ' could not be recorded as applied: '
            . $db->lastErrorMsg());

        break;
    }

    echo sprintf("Migration %s completed successfully.\n", $migration);
}
