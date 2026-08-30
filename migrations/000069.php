<?php
// Adds Wallos's own request counter to the fixer table.
//
// The provider's usage figure exists only when apilayer sends its rate-limit
// headers; fixer.io reports nothing, which is how #104 spent six months of a
// 100-call tier while the usage area stayed empty and read as reassurance.
// Wallos knows every request it makes, so it counts them itself, per calendar
// month (#106). The instance-shared key counts into instance settings; these
// columns carry the count for users with their own key.

// Through the boundary, not a pragma: the 5.8.0 PostgreSQL baseline records
// the chain up to 000063, so everything after it runs on both backends when
// an installation upgrades.
if (!$db->columnExists('fixer', 'local_calls')) {
    $db->exec("ALTER TABLE fixer ADD COLUMN local_calls INTEGER DEFAULT 0");
}

if (!$db->columnExists('fixer', 'local_calls_month')) {
    $db->exec("ALTER TABLE fixer ADD COLUMN local_calls_month TEXT DEFAULT ''");
}

// SQLite does not physically store ALTER TABLE defaults in existing rows, so
// PHP's SQLite3 extension may return NULL for them. Backfill explicitly.
$db->exec("UPDATE fixer SET local_calls = 0 WHERE local_calls IS NULL");
$db->exec("UPDATE fixer SET local_calls_month = '' WHERE local_calls_month IS NULL");
