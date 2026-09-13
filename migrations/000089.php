<?php
// Web Push: remember which device a subscription belongs to.
//
// A subscription is identified by its endpoint, which is a push service URL —
// nothing a person recognises. So the settings page could only ever offer "this
// device on / off": a household member who had enabled notifications on a phone
// they no longer own had no way to see that, let alone remove it, and no way to
// tell whether the reminders were going somewhere they could still read.
//
// The user agent of the request that subscribed is stored so the list can say
// "Chrome on Android, added 3 September". It is client-supplied and therefore
// never trusted as anything but a label: it is truncated on write, only ever
// read back to the account that stored it, and rendered escaped.
//
// Idempotent through columnExists(), which asks the database boundary rather
// than a backend-specific schema query — the PostgreSQL install applies
// schema.sql, which already carries the column, and then finds every migration
// recorded as applied.

if (!$db->tableExists('push_subscriptions')) {
    // Nothing to widen: migration 000083 creates the table, and a chain that
    // has not reached it yet will create it with this column already present.
    return true;
}

if (!$db->columnExists('push_subscriptions', 'user_agent')) {
    if ($db->exec("ALTER TABLE push_subscriptions ADD COLUMN user_agent TEXT DEFAULT ''") === false) {
        error_log('Wallos: migration 000089 could not add push_subscriptions.user_agent: '
            . $db->lastErrorMsg());

        return false;
    }
}

return true;
