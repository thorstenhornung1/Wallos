<?php
// How many upcoming payments the dashboard shows, per user (upstream #1191,
// where it is migration 000057 — that number is taken here, and a migration is
// recorded by its file name, so renumbering is the only way to let an
// installation that already ran this fork's 000057 also run this one).
//
// Written through the boundary rather than as upstream wrote it: the original
// asks SQLite's own table metadata, which the PostgreSQL backend this fork adds
// does not have, so it would have failed there. columnExists() answers the same
// question on both.

if (!$db->columnExists('settings', 'upcoming_payments_limit')) {
    if ($db->exec('ALTER TABLE settings ADD COLUMN upcoming_payments_limit INTEGER DEFAULT 3') === false) {
        error_log('Wallos: migration 000087 could not add settings.upcoming_payments_limit: '
            . $db->lastErrorMsg());

        return false;
    }
}

// A value outside the offered set would reach the dashboard's LIMIT as it
// stands; anything unrecognised goes back to the previous fixed behaviour.
if ($db->exec('UPDATE settings SET upcoming_payments_limit = 3
               WHERE upcoming_payments_limit IS NULL
                  OR upcoming_payments_limit NOT IN (3, 5, 10, 20)') === false) {
    error_log('Wallos: migration 000087 could not normalise settings.upcoming_payments_limit: '
        . $db->lastErrorMsg());

    return false;
}
