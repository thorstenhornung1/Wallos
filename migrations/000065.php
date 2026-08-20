<?php
// Remove the notifications table that migration 000016 could not drop.
//
// 000016 splits the old notifications table into email_notifications and
// notification_settings and then drops it. The drop ran while the migration's
// own `SELECT COUNT(*) FROM notifications` result was still open, and SQLite
// refuses to drop a table a statement is holding: "database table is locked".
// The exec's result was not checked, so the migration was recorded as applied
// and the table survived — on every installation, since the day 000016 was
// written.
//
// 5.8.2 fixed 000016, which only helps databases created after it. Everything
// older still carries the table, and so does any PostgreSQL database installed
// from the 5.8.0 or 5.8.1 baseline, which was generated from a chain that still
// produced it. This is what makes those converge on the same schema.
//
// Empty on every installation anyone has looked at — 000016 copied the rows out
// before failing to drop it — but the copy is repeated here for the case where
// it did not, because dropping a table with data in it is not something to do
// on the assumption that there is none.

if (!$db->tableExists('notifications')) {
    return;
}

$rows = (int) $db->scalar('SELECT COUNT(*) FROM notifications');

if ($rows > 0 && (int) $db->scalar('SELECT COUNT(*) FROM email_notifications') === 0) {
    // Column lists spelled out: the two tables no longer have the same shape,
    // and a SELECT * here would insert whatever order the old table happens to
    // have into whatever order the new one has.
    if ($db->exec('INSERT INTO email_notifications (enabled, smtp_address, smtp_port,
                   smtp_username, smtp_password, from_email, encryption)
                   SELECT enabled, smtp_address, smtp_port, smtp_username, smtp_password,
                   from_email, encryption FROM notifications') === false) {
        error_log('Wallos migration 000065: could not carry the notification settings over; '
            . 'leaving the old table in place: ' . $db->lastErrorMsg());

        // false rather than a bare return, so run_migrations.php does not
        // record a migration that gave up halfway as applied.
        return false;
    }

    if ($db->exec('INSERT INTO notification_settings (days) SELECT days FROM notifications') === false) {
        error_log('Wallos migration 000065: could not carry the notification days over; '
            . 'leaving the old table in place: ' . $db->lastErrorMsg());

        return;
    }
}

// Checked, unlike the drop this exists to finish. A migration that reports
// success while its statement failed is how one line of dead SQL survived
// sixty-odd migrations.
if ($db->exec('DROP TABLE IF EXISTS notifications') === false) {
    error_log('Wallos migration 000065: the notifications table could not be dropped: '
        . $db->lastErrorMsg());
}
