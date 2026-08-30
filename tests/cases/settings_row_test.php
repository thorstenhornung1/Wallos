<?php
/*
  Migration 000070: one settings row per user (specification 45.7, issue #17).

  The unique index itself is asserted against the fully migrated schema in
  performance_test.php ("one row per user is enforced where intended"). These
  cases cover the other half of the migration: an installation that already
  holds duplicate rows must have them resolved deliberately before the index
  can exist, and the row that survives must be the one the application was
  already serving — the first row an unordered LIMIT 1 returns, which for a
  table with an id column is the row with the smallest id.

  Like migration_test.php, the pre-upgrade database is built as a plain SQLite
  file: the shape being tested is "rows that predate the constraint", which no
  fixture opened through the migrated template can contain any more.
*/

/**
 * A database holding the seven settings tables without the unique index, the
 * way every installation before 000070 held them, with duplicates in place.
 *
 * @return SQLite3
 */
function settings_row_duplicated_database()
{
    $db = new WallosSqliteDatabase(WALLOS_TEST_TMP . '/settings-row-' . uniqid('', true) . '.db',
        SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    $db->exec("CREATE TABLE notification_settings (days INTEGER DEFAULT 0, user_id INTEGER DEFAULT 1,
        period_summary_at_period_start INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE email_notifications (enabled INTEGER DEFAULT 0, smtp_address TEXT DEFAULT '',
        smtp_port INTEGER DEFAULT 587, smtp_username TEXT DEFAULT '', smtp_password TEXT DEFAULT '',
        from_email TEXT DEFAULT '', encryption TEXT DEFAULT 'tls', user_id INTEGER DEFAULT 1,
        other_emails TEXT DEFAULT '', smtp_mode TEXT DEFAULT 'instance')");
    $db->exec("CREATE TABLE telegram_notifications (enabled INTEGER DEFAULT 0, bot_token TEXT DEFAULT '',
        chat_id TEXT DEFAULT '', user_id INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE pushover_notifications (enabled INTEGER DEFAULT 0, user_key TEXT DEFAULT '',
        token TEXT DEFAULT '', user_id INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE ntfy_notifications (enabled INTEGER DEFAULT 0, host TEXT DEFAULT '',
        topic TEXT DEFAULT '', headers TEXT DEFAULT '', user_id INTEGER, ignore_ssl INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE gotify_notifications (enabled INTEGER DEFAULT 0, url TEXT DEFAULT '',
        token TEXT DEFAULT '', user_id INTEGER DEFAULT 1, ignore_ssl INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE ai_settings (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,
        type TEXT NOT NULL, enabled INTEGER DEFAULT 0 NOT NULL, api_key TEXT, model TEXT NOT NULL,
        url TEXT, run_schedule TEXT DEFAULT 'manual' NOT NULL, provider_mode TEXT DEFAULT 'instance')");

    // User 1 carries duplicates everywhere; user 2 has a single clean row that
    // must come through untouched.
    $db->exec("INSERT INTO notification_settings (days, user_id) VALUES (5, 1)");
    $db->exec("INSERT INTO notification_settings (days, user_id) VALUES (9, 1)");
    $db->exec("INSERT INTO notification_settings (days, user_id) VALUES (3, 2)");

    $db->exec("INSERT INTO email_notifications (enabled, smtp_username, user_id) VALUES (1, 'first', 1)");
    $db->exec("INSERT INTO email_notifications (enabled, smtp_username, user_id) VALUES (0, 'second', 1)");
    $db->exec("INSERT INTO email_notifications (enabled, smtp_username, user_id) VALUES (1, 'only', 2)");

    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token, user_id) VALUES (1, 'kept', 1)");
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token, user_id) VALUES (0, 'dropped', 1)");

    $db->exec("INSERT INTO pushover_notifications (enabled, user_key, user_id) VALUES (1, 'kept', 1)");
    $db->exec("INSERT INTO pushover_notifications (enabled, user_key, user_id) VALUES (0, 'dropped', 1)");

    $db->exec("INSERT INTO ntfy_notifications (enabled, topic, user_id) VALUES (1, 'kept', 1)");
    $db->exec("INSERT INTO ntfy_notifications (enabled, topic, user_id) VALUES (0, 'dropped', 1)");

    $db->exec("INSERT INTO gotify_notifications (enabled, token, user_id) VALUES (1, 'kept', 1)");
    $db->exec("INSERT INTO gotify_notifications (enabled, token, user_id) VALUES (0, 'dropped', 1)");

    $db->exec("INSERT INTO ai_settings (id, user_id, type, model) VALUES (10, 1, 'kept', 'model-a')");
    $db->exec("INSERT INTO ai_settings (id, user_id, type, model) VALUES (11, 1, 'dropped', 'model-b')");
    $db->exec("INSERT INTO ai_settings (id, user_id, type, model) VALUES (12, 2, 'only', 'model-c')");

    return $db;
}

wallos_test('the migration resolves existing duplicates before adding the constraint', function () {
    $db = settings_row_duplicated_database();

    $outcome = require WALLOS_ROOT . '/migrations/000070.php';
    assert_true($outcome !== false, 'the migration succeeds on a database holding duplicates');

    foreach (['notification_settings', 'email_notifications', 'telegram_notifications',
        'pushover_notifications', 'ntfy_notifications', 'gotify_notifications', 'ai_settings'] as $table) {
        assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = 1'),
            $table . ' holds one row for the duplicated user');
    }

    assert_same(3, (int) $db->scalar("SELECT days FROM notification_settings WHERE user_id = 2"),
        'a user without duplicates keeps their row untouched');
    assert_same('only', $db->scalar("SELECT smtp_username FROM email_notifications WHERE user_id = 2"),
        'in every table');

    $db->close();
});

wallos_test('the migration keeps the row an unordered LIMIT 1 was serving', function () {
    $db = settings_row_duplicated_database();

    // What the application reads today, before the migration decides anything:
    // the first row, in each table, exactly as every settings reader asks.
    $servedDays = (int) $db->scalar('SELECT days FROM notification_settings WHERE user_id = 1 LIMIT 1');
    $servedSmtp = $db->scalar('SELECT smtp_username FROM email_notifications WHERE user_id = 1 LIMIT 1');

    $outcome = require WALLOS_ROOT . '/migrations/000070.php';
    assert_true($outcome !== false, 'the migration succeeds');

    assert_same($servedDays, (int) $db->scalar('SELECT days FROM notification_settings WHERE user_id = 1'),
        'the surviving notification_settings row is the one LIMIT 1 delivered');
    assert_same($servedSmtp, $db->scalar('SELECT smtp_username FROM email_notifications WHERE user_id = 1'),
        'the surviving email_notifications row is the one LIMIT 1 delivered');

    foreach (['telegram_notifications' => 'bot_token', 'pushover_notifications' => 'user_key',
        'ntfy_notifications' => 'topic', 'gotify_notifications' => 'token'] as $table => $column) {
        assert_same('kept', $db->scalar('SELECT ' . $column . ' FROM ' . $table . ' WHERE user_id = 1'),
            $table . ' keeps its first row');
    }

    assert_same('kept', $db->scalar('SELECT type FROM ai_settings WHERE user_id = 1'),
        'ai_settings keeps the row with the smallest id');

    $db->close();
});

wallos_test('running the migration twice changes nothing and a new duplicate is refused', function () {
    $db = settings_row_duplicated_database();

    $outcome = require WALLOS_ROOT . '/migrations/000070.php';
    assert_true($outcome !== false, 'the first run succeeds');

    $outcome = require WALLOS_ROOT . '/migrations/000070.php';
    assert_true($outcome !== false, 'the second run succeeds');

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM telegram_notifications WHERE user_id = 1'),
        'the second run removes nothing further');

    $duplicate = @$db->exec("INSERT INTO telegram_notifications (enabled, user_id) VALUES (1, 1)");
    assert_true($duplicate === false, 'after the migration the database refuses a second row');

    $db->close();
});
