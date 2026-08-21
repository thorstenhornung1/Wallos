<?php
// Rows whose user_id names no account, removed before they can be adopted.
//
// user.id is an INTEGER PRIMARY KEY that does not ask SQLite to keep
// counting, so it hands out max(rowid) + 1 — delete the newest account,
// create another, and it receives the same id. Rows the old account left behind then belong to the new one, and
// nothing in the interface says they are inherited: another person's
// subscriptions, spending history and household members, including a stored
// email address, shown as the new account's own (issue #92).
//
// Deletion has been atomic and complete since #81, so no new orphans are being
// created. This is about the ones already there — measured at 87 rows across
// eight tables on one development database, from a single incomplete deletion.
//
// **user_id 0 and NULL are not orphans.** Older installations carry system
// payment methods owned by nobody, and the application has always accepted
// them. They are counted and reported rather than skipped in silence, because
// "some rows were left alone" is a thing an operator should be told once rather
// than discover later.
//
// What this cannot repair: where an id was already reused, the rows now belong
// to a live account and no query can tell them from data that account created.
// A migration must not guess. That belongs in the release notes.

// An installation with no accounts has nothing to repair, and everything to
// lose. createdatabase.php seeds the default currencies, categories and payment
// methods against user_id 1 before anyone has registered — so on a fresh
// database every one of those rows names an account that does not exist yet,
// and a repair that trusted that reading would empty the installation before
// its first user arrived. Found by running this against the schema generator's
// reference database, which is exactly that state: 83 rows removed from a
// database whose only fault was being new.
if ((int) $db->scalar('SELECT COUNT(*) FROM "user"') === 0) {
    return;
}

$tables = $db->tablesWithColumn('user_id');

if ($tables === []) {
    return;
}

$removed = [];
$shared = 0;

foreach ($tables as $table) {
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        continue;
    }

    $quoted = '"' . $table . '"';

    // Counted before the delete, so the report is the number actually removed
    // rather than the number the statement was asked to remove.
    $orphans = (int) $db->scalar('SELECT COUNT(*) FROM ' . $quoted . ' t
                                  WHERE t.user_id IS NOT NULL AND t.user_id <> 0
                                    AND NOT EXISTS (SELECT 1 FROM "user" u WHERE u.id = t.user_id)');

    $shared += (int) $db->scalar('SELECT COUNT(*) FROM ' . $quoted . ' t
                                  WHERE t.user_id IS NULL OR t.user_id = 0');

    if ($orphans === 0) {
        continue;
    }

    $statement = 'DELETE FROM ' . $quoted . ' WHERE user_id IS NOT NULL AND user_id <> 0
                  AND user_id NOT IN (SELECT id FROM "user")';

    if ($db->exec($statement) === false) {
        error_log('Wallos migration 000067: could not remove ' . $orphans . ' orphaned row(s) from '
            . $table . ': ' . $db->lastErrorMsg());

        // false, so run_migrations.php does not record a repair that stopped
        // halfway as done and never runs it again.
        return false;
    }

    $removed[$table] = $orphans;
}

if ($removed !== []) {
    $summary = [];

    foreach ($removed as $table => $count) {
        $summary[] = $table . ': ' . $count;
    }

    $line = 'Wallos migration 000067: removed rows belonging to accounts that no longer exist — '
        . implode(', ', $summary) . '. These would have been inherited by the next account '
        . 'created with a reused id (issue #92).';

    error_log($line);
    echo $line . "\n";
}

if ($shared > 0) {
    // Said once, here, rather than left for somebody to wonder about when the
    // numbers do not add up.
    echo 'Wallos migration 000067: ' . $shared . ' row(s) carry user_id 0 or NULL and were left '
        . "alone — those belong to the instance rather than to an account.\n";
}
