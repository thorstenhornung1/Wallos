<?php
// Gotify: the server host becomes shared instance configuration (issue #15).
//
// The Gotify server address is shared infrastructure. The application token is
// not: it identifies a message source, and sharing one across unrelated users
// would make every user's messages appear through the same Gotify application.
// So this adds a mode column that governs the host only — the app token stays
// per user in both modes — the same shape milestone B (000055) used elsewhere.
//
// Existing rows carry a user-supplied host and are marked "custom" so they keep
// working exactly as before. Through the boundary only.

if (!$db->columnExists('gotify_notifications', 'url_mode')) {
    if ($db->exec("ALTER TABLE gotify_notifications ADD COLUMN url_mode TEXT DEFAULT 'instance'") === false) {
        error_log('Wallos: migration 000080 could not add gotify_notifications.url_mode: '
            . $db->lastErrorMsg());

        return false;
    }

    if ($db->exec("UPDATE gotify_notifications SET url_mode = 'custom'
                   WHERE COALESCE(url, '') != ''") === false) {
        error_log('Wallos: migration 000080 could not mark existing gotify rows custom: '
            . $db->lastErrorMsg());

        return false;
    }
}

if ($db->exec("UPDATE gotify_notifications SET url_mode = 'instance'
               WHERE url_mode IS NULL OR url_mode = ''") === false) {
    error_log('Wallos: migration 000080 could not normalise gotify_notifications.url_mode: '
        . $db->lastErrorMsg());

    return false;
}
