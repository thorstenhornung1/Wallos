<?php

// This migration prepares the instance configuration layer.
//
// It adds the "integration_settings" table, which stores instance wide
// integration values (shared SMTP, currency provider and AI provider) that are
// not supplied through environment variables, and it adds an explicit mode
// column to every table that may now inherit an instance default.
//
// The mode is stored explicitly instead of being inferred from empty fields:
// a blank legacy column must never be mistaken for "use the instance value".
// Existing per-user configuration is therefore migrated to "custom" so that
// installations keep working exactly as before the upgrade.

$db->exec("CREATE TABLE IF NOT EXISTS integration_settings (
    integration TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    setting_value TEXT DEFAULT '',
    is_secret INTEGER DEFAULT 0,
    PRIMARY KEY (integration, setting_key)
)");

// The instance SMTP transport keeps living in the "admin" table, so existing
// installations neither move nor duplicate their credentials. Only the optional
// sender name is new.
$fromNameColumn = $db->query("SELECT * FROM pragma_table_info('admin') WHERE name='smtp_from_name'");
if ($fromNameColumn->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE admin ADD COLUMN smtp_from_name TEXT DEFAULT ''");
}

$smtpModeColumn = $db->query("SELECT * FROM pragma_table_info('email_notifications') WHERE name='smtp_mode'");
if ($smtpModeColumn->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE email_notifications ADD COLUMN smtp_mode TEXT DEFAULT 'instance'");
    $db->exec("UPDATE email_notifications
               SET smtp_mode = 'custom'
               WHERE COALESCE(smtp_address, '') != ''
                  OR COALESCE(smtp_username, '') != ''
                  OR COALESCE(smtp_password, '') != ''");
}
$db->exec("UPDATE email_notifications SET smtp_mode = 'instance' WHERE smtp_mode IS NULL OR smtp_mode = ''");

$currencyModeColumn = $db->query("SELECT * FROM pragma_table_info('fixer') WHERE name='provider_mode'");
if ($currencyModeColumn->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE fixer ADD COLUMN provider_mode TEXT DEFAULT 'instance'");
    $db->exec("UPDATE fixer SET provider_mode = 'custom' WHERE COALESCE(api_key, '') != ''");
}
$db->exec("UPDATE fixer SET provider_mode = 'instance' WHERE provider_mode IS NULL OR provider_mode = ''");

$aiTableQuery = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ai_settings'");
if ($aiTableQuery->fetchArray(SQLITE3_ASSOC) !== false) {
    $aiModeColumn = $db->query("SELECT * FROM pragma_table_info('ai_settings') WHERE name='provider_mode'");
    if ($aiModeColumn->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE ai_settings ADD COLUMN provider_mode TEXT DEFAULT 'instance'");
        // A row that names a provider is deliberate configuration and stays in
        // charge of itself. A row that only carries the enable flag and the
        // schedule has nothing to keep and can inherit the instance provider.
        $db->exec("UPDATE ai_settings
                   SET provider_mode = 'custom'
                   WHERE COALESCE(type, '') != ''
                      OR COALESCE(api_key, '') != ''
                      OR COALESCE(url, '') != ''");
    }
    $db->exec("UPDATE ai_settings SET provider_mode = 'instance' WHERE provider_mode IS NULL OR provider_mode = ''");
}

?>
