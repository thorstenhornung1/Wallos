<?php
// One settings row per user (issue #17, specification 45.7).
//
// Seven tables are conceptually one row per user, and nothing ever stopped a
// second row from arriving: every save does count-then-insert-or-update, and
// two overlapping requests both count zero. Every reader asks with LIMIT 1
// and no ORDER BY, so a duplicated row makes pages and cron jobs disagree
// silently, depending on which row the storage happens to serve first. A
// unique index on user_id lets the database itself refuse the second row.
//
// Existing duplicates have to be resolved before the index can exist, and the
// choice of survivor is deliberate: the row an unordered LIMIT 1 has been
// serving in practice, so upgrading changes nothing about what the user sees.
// For ai_settings — the only one of the seven with an id column — that is the
// row with the smallest id. The six other tables have no id column at all, so
// the kept row is read with the very SELECT ... LIMIT 1 the application uses,
// the user's rows are deleted, and the kept row is written back — plain SQL
// that runs unchanged on both backends, where a rowid-style address would name
// a different concept on each. How many rows were removed goes to error_log,
// so an operator can see what the upgrade resolved rather than wonder.
//
// Rows whose user_id is NULL (possible in ntfy_notifications alone) are left
// untouched: both backends treat NULLs as distinct in a unique index, and a
// row no user owns is not this migration's question.
//
// Through the boundary only — everything after the 5.8.0 PostgreSQL baseline
// runs on both backends when an installation upgrades.

$tables = [
    'notification_settings',
    'email_notifications',
    'telegram_notifications',
    'pushover_notifications',
    'ntfy_notifications',
    'gotify_notifications',
    'ai_settings',
];

foreach ($tables as $table) {
    $removed = 0;

    if ($db->columnExists($table, 'id')) {
        // The smallest id per user survives; the subquery names the survivors
        // and everything else goes.
        if ($db->exec('DELETE FROM ' . $table
                . ' WHERE id NOT IN (SELECT MIN(id) FROM ' . $table . ' GROUP BY user_id)') === false) {
            error_log('Wallos: migration 000070 could not resolve duplicates in ' . $table
                . ': ' . $db->lastErrorMsg());

            return false;
        }

        $removed = $db->changes();
    } else {
        // No id column, so duplicated users are collected first and each is
        // resolved on its own: read the row LIMIT 1 serves, replace the
        // user's rows with exactly that one.
        $duplicated = $db->query('SELECT user_id FROM ' . $table
            . ' WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1');
        if ($duplicated === false) {
            error_log('Wallos: migration 000070 could not inspect ' . $table
                . ': ' . $db->lastErrorMsg());

            return false;
        }

        $users = [];
        while ($row = $duplicated->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row['user_id'];
        }

        foreach ($users as $userId) {
            $read = $db->prepare('SELECT * FROM ' . $table . ' WHERE user_id = :userId LIMIT 1');
            if ($read === false) {
                return false;
            }
            $read->bindValue(':userId', $userId);
            $result = $read->execute();
            $kept = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);
            if ($kept === false) {
                error_log('Wallos: migration 000070 could not read the surviving ' . $table
                    . ' row for user ' . $userId . ': ' . $db->lastErrorMsg());

                return false;
            }

            // Delete and re-insert as one transaction, so a failure between
            // the two cannot cost the user their settings row.
            if ($db->beginTransaction() === false) {
                return false;
            }

            $delete = $db->prepare('DELETE FROM ' . $table . ' WHERE user_id = :userId');
            if ($delete === false || $delete->bindValue(':userId', $userId) === false
                || $delete->execute() === false) {
                $db->rollBack();
                error_log('Wallos: migration 000070 could not resolve duplicates in ' . $table
                    . ' for user ' . $userId . ': ' . $db->lastErrorMsg());

                return false;
            }
            $deleted = $db->changes();

            $columns = [];
            $placeholders = [];
            foreach (array_keys($kept) as $index => $column) {
                $columns[] = '"' . $column . '"';
                $placeholders[] = ':v' . $index;
            }

            $insert = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns)
                . ') VALUES (' . implode(', ', $placeholders) . ')');
            if ($insert === false) {
                $db->rollBack();

                return false;
            }
            $index = 0;
            foreach ($kept as $value) {
                $insert->bindValue(':v' . $index, $value);
                $index++;
            }
            if ($insert->execute() === false || $db->commit() === false) {
                $db->rollBack();
                error_log('Wallos: migration 000070 could not write back the surviving ' . $table
                    . ' row for user ' . $userId . ': ' . $db->lastErrorMsg());

                return false;
            }

            $removed += $deleted - 1;
        }
    }

    if ($removed > 0) {
        error_log('Wallos: migration 000070 removed ' . $removed . ' duplicate ' . $table
            . ' row(s), keeping the row each user was already being served');
    }

    // Runs on both backends and needs no table rebuild. NULLs stay distinct,
    // which is why the cleanup above could leave them alone.
    if ($db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $table . '_user ON ' . $table
            . ' (user_id)') === false) {
        error_log('Wallos: migration 000070 could not create idx_' . $table . '_user: '
            . $db->lastErrorMsg());

        return false;
    }
}
