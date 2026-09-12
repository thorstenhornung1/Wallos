<?php
// Expects $db to be set by the caller.
//
// A migration counts as applied when it says it worked and when that record
// itself was written. Neither used to be true: the file was included, the row
// was inserted, and "completed successfully" was printed, none of it conditional
// on anything.
//
// That is not hypothetical. Migration 000016 drops the notifications table it
// has just replaced, and the drop runs while the migration's own read of that
// table is still open. SQLite refuses with "database table is locked", the
// result of that statement is not read, and this file records the migration as
// applied with its work undone - on every installation ever made.
//
// Three rules follow:
//
//   A migration that returns false has failed. `require` rather than
//   `require_once`, because once returns true on a repeat include instead of the
//   file's own value, and the value is the signal. Each file appears in the list
//   exactly once, so there is nothing to guard against. No migration returns
//   anything today, so this is inert until one needs it.
//
//   A migration whose record cannot be written has not been applied, whatever it
//   did. Recording it is what keeps it from running again, and a migration that
//   is not idempotent would then do its work twice.
//
//   Neither case continues to the next migration. Migrations build on each
//   other, and running one against a database where its predecessor failed is
//   how one broken statement becomes a schema nobody can reason about. The
//   failure is reported and the run stops, so the next start retries from the
//   same point.
//
// $migrationFailure is left set for the caller to read, and null when the run
// was clean.

$migrationsDir = __DIR__ . '/../migrations/';
$migrationFailure = null;

$completedMigrations = [];

// Finalised, and this one is load-bearing rather than tidy. fetchArray() is
// called once and never again, so the statement is never stepped past its last
// row and stays active - holding a shared read lock on sqlite_master for as
// long as it lives. The first migration to create or drop a table then meets
// "database table is locked". It is the same shape as the defect in 000016, in
// the file that runs it.
$migrationTableQuery = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");
$migrationTableExists = $migrationTableQuery !== false
    && $migrationTableQuery->fetchArray(SQLITE3_ASSOC) !== false;

if ($migrationTableQuery !== false) {
    $migrationTableQuery->finalize();
}

if ($migrationTableExists) {
    $migrationQuery = $db->query('SELECT migration FROM migrations');

    while ($migrationQuery !== false && $row = $migrationQuery->fetchArray(SQLITE3_ASSOC)) {
        $completedMigrations[] = str_replace('../../', '', $row['migration']);
    }

    // Defensive rather than load-bearing, and worth saying which: the loop
    // above fetches until it gets false, which steps the statement past its
    // last row and releases the lock on its own. This covers the version of
    // this loop that one day exits early.
    if ($migrationQuery !== false) {
        $migrationQuery->finalize();
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
    $applied = require $migrationsDir . basename($migration);

    if ($applied === false) {
        $migrationFailure = sprintf('Migration %s reported failure; the run stopped there.', $migration);
        echo $migrationFailure . "\n";
        break;
    }

    $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');

    if ($stmt === false || $stmt->bindValue(':migration', $migration, SQLITE3_TEXT) === false
        || $stmt->execute() === false) {
        $migrationFailure = sprintf(
            'Migration %s ran but could not be recorded, so it would run again: %s',
            $migration,
            $db->lastErrorMsg()
        );
        echo $migrationFailure . "\n";
        break;
    }

    echo sprintf("Migration %s completed successfully.\n", $migration);
}
