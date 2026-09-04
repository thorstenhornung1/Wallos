<?php
// ntfy: the server and its shared auth headers become instance configuration
// (issue #14).
//
// The ntfy server is usually shared infrastructure; the topic is a personal
// destination. This adds the mode column so a per-user row can inherit the
// instance server (host plus optional shared auth headers) while keeping its
// own topic, and it leaves a per-user header override available. The same shape
// milestone B (000055) used for the currency and AI tables.
//
// Existing rows carry a user-supplied host and are marked "custom" so they keep
// working exactly as before. Through the boundary only.

if (!$db->columnExists('ntfy_notifications', 'server_mode')) {
    if ($db->exec("ALTER TABLE ntfy_notifications ADD COLUMN server_mode TEXT DEFAULT 'instance'") === false) {
        error_log('Wallos: migration 000079 could not add ntfy_notifications.server_mode: '
            . $db->lastErrorMsg());

        return false;
    }

    if ($db->exec("UPDATE ntfy_notifications SET server_mode = 'custom'
                   WHERE COALESCE(host, '') != ''") === false) {
        error_log('Wallos: migration 000079 could not mark existing ntfy rows custom: '
            . $db->lastErrorMsg());

        return false;
    }
}

if ($db->exec("UPDATE ntfy_notifications SET server_mode = 'instance'
               WHERE server_mode IS NULL OR server_mode = ''") === false) {
    error_log('Wallos: migration 000079 could not normalise ntfy_notifications.server_mode: '
        . $db->lastErrorMsg());

    return false;
}
