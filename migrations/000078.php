<?php
// Pushover: the application token becomes a shared instance credential (#13).
//
// The Pushover application token identifies Wallos as an application, which
// makes it a natural instance credential; the user key identifies the person
// and stays with them. This adds the mode column so a per-user row can inherit
// the instance application token while keeping its own user key, the same shape
// milestone B (000055) used for the currency and AI tables.
//
// Existing rows carry a user-supplied token and are marked "custom" so they
// keep working exactly as before. Through the boundary only.

if (!$db->columnExists('pushover_notifications', 'token_mode')) {
    if ($db->exec("ALTER TABLE pushover_notifications ADD COLUMN token_mode TEXT DEFAULT 'instance'") === false) {
        error_log('Wallos: migration 000078 could not add pushover_notifications.token_mode: '
            . $db->lastErrorMsg());

        return false;
    }

    if ($db->exec("UPDATE pushover_notifications SET token_mode = 'custom'
                   WHERE COALESCE(token, '') != ''") === false) {
        error_log('Wallos: migration 000078 could not mark existing pushover rows custom: '
            . $db->lastErrorMsg());

        return false;
    }
}

if ($db->exec("UPDATE pushover_notifications SET token_mode = 'instance'
               WHERE token_mode IS NULL OR token_mode = ''") === false) {
    error_log('Wallos: migration 000078 could not normalise pushover_notifications.token_mode: '
        . $db->lastErrorMsg());

    return false;
}
