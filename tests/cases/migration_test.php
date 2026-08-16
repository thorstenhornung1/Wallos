<?php
/*
  Upgrade behaviour: an installation that configured SMTP, a currency key or an
  AI provider per user must behave exactly as before after the upgrade.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/**
 * Builds a database in the pre-upgrade shape and runs the migration on it.
 *
 * @return SQLite3
 */
function migration_legacy_database()
{
    $db = new SQLite3(WALLOS_TEST_TMP . '/legacy-' . uniqid('', true) . '.db',
        SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    $db->exec("CREATE TABLE admin (
        id INTEGER PRIMARY KEY, registrations_open BOOLEAN DEFAULT 0, max_users INTEGER DEFAULT 0,
        require_email_verification BOOLEAN DEFAULT 0, server_url TEXT, smtp_address TEXT,
        smtp_port INTEGER DEFAULT 587, smtp_username TEXT, smtp_password TEXT, from_email TEXT,
        encryption TEXT DEFAULT 'tls')");
    $db->exec("INSERT INTO admin (id) VALUES (1)");
    $db->exec("CREATE TABLE email_notifications (enabled BOOLEAN DEFAULT 0, smtp_address TEXT DEFAULT '',
        smtp_port INTEGER DEFAULT 587, smtp_username TEXT DEFAULT '', smtp_password TEXT DEFAULT '',
        from_email TEXT DEFAULT '', other_emails TEXT DEFAULT '', encryption TEXT DEFAULT 'tls', user_id INTEGER)");
    $db->exec("CREATE TABLE fixer (api_key TEXT, provider INTEGER DEFAULT 0, user_id INTEGER)");
    $db->exec("CREATE TABLE ai_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        type TEXT NOT NULL, enabled BOOLEAN NOT NULL DEFAULT 0, api_key TEXT, model TEXT NOT NULL, url TEXT,
        run_schedule TEXT NOT NULL DEFAULT 'manual')");

    // User 1 configured everything themselves, user 2 configured nothing.
    $db->exec("INSERT INTO email_notifications (enabled, smtp_address, smtp_port, smtp_username, smtp_password, from_email, encryption, user_id)
               VALUES (1, 'smtp.user.example', 1025, 'olduser', 'oldpassword', 'user@example.com', 'tls', 1)");
    $db->exec("INSERT INTO email_notifications (enabled, user_id) VALUES (1, 2)");
    $db->exec("INSERT INTO fixer (api_key, provider, user_id) VALUES ('user-currency-key', 1, 1)");
    $db->exec("INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule)
               VALUES (1, 'chatgpt', 1, 'user-ai-key', 'gpt-user', '', 'weekly')");
    $db->exec("INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule)
               VALUES (2, '', 1, '', '', '', 'weekly')");

    require WALLOS_ROOT . '/migrations/000055.php';

    return $db;
}

wallos_test('the migration adds its schema', function () {
    $db = migration_legacy_database();

    assert_true((bool) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='integration_settings'"),
        'integration_settings exists');
    assert_true((bool) $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('email_notifications') WHERE name='smtp_mode'"),
        'email_notifications has smtp_mode');
    assert_true((bool) $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('fixer') WHERE name='provider_mode'"),
        'fixer has provider_mode');
    assert_true((bool) $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('ai_settings') WHERE name='provider_mode'"),
        'ai_settings has provider_mode');
    assert_true((bool) $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('admin') WHERE name='smtp_from_name'"),
        'admin has smtp_from_name');

    $db->close();
});

wallos_test('existing user configuration survives as a custom override', function () {
    $db = migration_legacy_database();

    assert_same('custom', $db->querySingle("SELECT smtp_mode FROM email_notifications WHERE user_id = 1"),
        'a configured SMTP server stays with its user');
    assert_same('custom', $db->querySingle("SELECT provider_mode FROM fixer WHERE user_id = 1"),
        'a stored currency key stays with its user');
    assert_same('custom', $db->querySingle("SELECT provider_mode FROM ai_settings WHERE user_id = 1"),
        'a configured AI provider stays with its user');

    assert_same('oldpassword', $db->querySingle("SELECT smtp_password FROM email_notifications WHERE user_id = 1"),
        'no credential is deleted');
    assert_same('user-currency-key', $db->querySingle("SELECT api_key FROM fixer WHERE user_id = 1"),
        'no key is deleted');

    $db->close();
});

wallos_test('a user who configured nothing inherits the instance', function () {
    $db = migration_legacy_database();

    assert_same('instance', $db->querySingle("SELECT smtp_mode FROM email_notifications WHERE user_id = 2"),
        'an empty SMTP row inherits');
    assert_same('instance', $db->querySingle("SELECT provider_mode FROM ai_settings WHERE user_id = 2"),
        'an AI row that only carries the schedule inherits');

    $db->close();
});

wallos_test('running the migration twice changes nothing', function () {
    $db = migration_legacy_database();

    require WALLOS_ROOT . '/migrations/000055.php';

    assert_same('custom', $db->querySingle("SELECT smtp_mode FROM email_notifications WHERE user_id = 1"),
        'modes are unchanged on a second run');
    assert_same('instance', $db->querySingle("SELECT smtp_mode FROM email_notifications WHERE user_id = 2"),
        'inheriting users stay inheriting');

    $db->close();
});

wallos_test('a database without the mode column still resolves correctly', function () {
    // Covers the window between deploying new code and running migrations.
    $db = new SQLite3(WALLOS_TEST_TMP . '/premigration-' . uniqid('', true) . '.db',
        SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    $db->exec("CREATE TABLE admin (id INTEGER PRIMARY KEY, smtp_address TEXT, smtp_port INTEGER DEFAULT 587,
        smtp_username TEXT, smtp_password TEXT, from_email TEXT, encryption TEXT DEFAULT 'tls')");
    $db->exec("INSERT INTO admin (id, smtp_address) VALUES (1, 'smtp.instance.example')");
    $db->exec("CREATE TABLE email_notifications (enabled BOOLEAN DEFAULT 0, smtp_address TEXT DEFAULT '',
        smtp_port INTEGER DEFAULT 587, smtp_username TEXT DEFAULT '', smtp_password TEXT DEFAULT '',
        from_email TEXT DEFAULT '', other_emails TEXT DEFAULT '', encryption TEXT DEFAULT 'tls', user_id INTEGER)");
    $db->exec("INSERT INTO email_notifications (enabled, smtp_address, user_id) VALUES (1, 'smtp.user.example', 1)");

    $config = wallos_get_effective_smtp_config($db, 1);
    assert_same('custom', $config['mode'], 'a stored host is treated as a custom transport');
    assert_same('smtp.user.example', $config['values']['host'], 'the user host is still used');

    $db->close();
});
