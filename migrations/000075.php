<?php
// Adds the daily rate-limit columns to the fixer table.
//
// apilayer sends X-RateLimit-Limit-Day / -Remaining-Day beside the monthly
// pair migration 000051 already stores, but only the month pair was ever read
// (#106). A daily limit reached is a different situation from a monthly quota
// exhausted — the first resolves by tomorrow's cron, the second does not — so
// the two are stored apart and the settings page can tell them apart. Captured
// the same opportunistic way the month pair is: from the headers of a rate
// update that happened anyway, never a request of its own.
//
// These carry the figure for a user with their own key; the instance-shared
// key keeps its count in instance settings, which need no column.
//
// Through the boundary rather than a pragma: the 5.8.0 PostgreSQL baseline
// records the chain up to 000063, so everything after it has to run on both
// backends when an installation upgrades.
if (!$db->columnExists('fixer', 'usage_used_day')) {
    $db->exec("ALTER TABLE fixer ADD COLUMN usage_used_day INTEGER DEFAULT NULL");
}

if (!$db->columnExists('fixer', 'usage_limit_day')) {
    $db->exec("ALTER TABLE fixer ADD COLUMN usage_limit_day INTEGER DEFAULT NULL");
}

// No backfill: NULL is the intended "the provider has not reported a daily
// figure yet" value, and it is exactly what the file-backed backend hands back
// for an unmigrated row — unlike migration 000069's counter, where 0 and ''
// were the meaningful defaults and had to be written out.
