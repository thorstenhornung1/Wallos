<?php
// Where a scheduled job says whether it ran and whether it worked.
//
// Until now nothing recorded that a cron job had run at all. A fatal in
// sendnotifications was found by a person working through a test plan by hand,
// because from outside a dead job and a night with nothing to send produce
// exactly the same evidence: no mail, no error, exit status 0.
//
// One row per job, replaced on every run. Keeping history would need a
// retention job of its own — and another unattended job is the last thing this
// wants — while the question the admin page asks needs only the last answer:
// did what should have run last night run, and did it work.
//
// The shape follows last_update_next_payment_date, which is the same idea for
// one job: a table whose only purpose is to remember when something last
// happened. This one adds the outcome, because "it ran" and "it worked" are
// different questions and only the second one was ever missing.
//
// Text timestamps rather than TIMESTAMP columns, written with gmdate(). The
// admin page compares them against a UTC clock to decide whether a job is
// overdue, and a container with TZ set would otherwise write local time into a
// column read as UTC and report a job as two hours fresher than it is.

$db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    job TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    detail TEXT NOT NULL DEFAULT ''
)");
