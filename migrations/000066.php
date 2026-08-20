<?php
// A failure survives the next success.
//
// 000064 records one row per job, replaced on every run, and says why: keeping
// a run history would need a retention job of its own, and another unattended
// job is the last thing the cron reporting wants. That still holds. What the
// single row cannot answer is the question a test run against 5.8.2 asked of
// it — has this job ever failed? A job that dies every third night and
// succeeded this morning shows a green line and a fresh timestamp, which is
// exactly the shape the reporting exists to make visible.
//
// Three columns rather than a table. They record the most recent failure and
// how many there have been; a success leaves them alone. The row count stays at
// one per job, so nothing grows and nothing needs pruning, and the two
// questions the admin page asks — did the last run work, and is this job
// reliable — are both answerable from the row it already reads.
//
// failure_count is cumulative and never reset. A counter that goes back to zero
// on success answers "is it failing right now", which is what `status` already
// says. The number worth having is the one that does not disappear.

if (!$db->tableExists('cron_runs')) {
    // 000064 creates it. An installation that has not reached that migration
    // yet has nothing to alter, and the table is created with these columns
    // from the baseline.
    return;
}

// Written out rather than built, so that what runs is what is read here.
$statements = [
    'last_failure_at' => "ALTER TABLE cron_runs ADD COLUMN last_failure_at TEXT",
    'last_failure_detail' => "ALTER TABLE cron_runs ADD COLUMN last_failure_detail TEXT NOT NULL DEFAULT ''",
    'failure_count' => "ALTER TABLE cron_runs ADD COLUMN failure_count INTEGER NOT NULL DEFAULT 0",
];

foreach ($statements as $column => $statement) {
    if ($db->columnExists('cron_runs', $column)) {
        continue;
    }

    // Checked, because a migration whose statement failed is still recorded as
    // applied (issue #87) — and a missing column here makes the diagnostics
    // query fail on every admin page load, a long way from the cause.
    if ($db->exec($statement) === false) {
        error_log('Wallos migration 000066: could not add cron_runs.' . $column . ': '
            . $db->lastErrorMsg());

        return;
    }
}
