<?php
// Telegram: the bot token becomes a shared instance credential (issue #12).
//
// The bot token is infrastructure the administrator owns; the chat id is a
// personal destination. This adds an explicit mode column so a per-user row can
// inherit the instance bot token while keeping its own chat id, exactly the way
// milestone B (000055) added provider_mode to the currency and AI tables.
//
// The mode is stored explicitly rather than inferred from an empty column: a
// blank bot token must never be mistaken for "use the instance value". Existing
// rows that carry a user-supplied bot token are therefore marked "custom" so
// installations keep working exactly as before the upgrade.
//
// Through the boundary only, so the same file runs on both backends.

if (!$db->columnExists('telegram_notifications', 'bot_token_mode')) {
    if ($db->exec("ALTER TABLE telegram_notifications ADD COLUMN bot_token_mode TEXT DEFAULT 'instance'") === false) {
        error_log('Wallos: migration 000077 could not add telegram_notifications.bot_token_mode: '
            . $db->lastErrorMsg());

        return false;
    }

    if ($db->exec("UPDATE telegram_notifications SET bot_token_mode = 'custom'
                   WHERE COALESCE(bot_token, '') != ''") === false) {
        error_log('Wallos: migration 000077 could not mark existing telegram rows custom: '
            . $db->lastErrorMsg());

        return false;
    }
}

if ($db->exec("UPDATE telegram_notifications SET bot_token_mode = 'instance'
               WHERE bot_token_mode IS NULL OR bot_token_mode = ''") === false) {
    error_log('Wallos: migration 000077 could not normalise telegram_notifications.bot_token_mode: '
        . $db->lastErrorMsg());

    return false;
}
