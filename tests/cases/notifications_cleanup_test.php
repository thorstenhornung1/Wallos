<?php
/*
  Migration 000065: removing the notifications table 000016 could not drop.

  000016 splits notifications into email_notifications and notification_settings
  and then drops it — while its own SELECT COUNT(*) on that table is still open.
  SQLite refuses to drop a table a statement holds, the exec result was not
  checked, and the migration was recorded as applied with the table still there.
  Every installation made before 5.8.2 carries it, and so does every PostgreSQL
  database installed from the 5.8.0 or 5.8.1 baseline.

  Measured on the dev instances before writing this: present on both, empty on
  both.

  The fixture has to put the table back, because the current chain no longer
  produces it — which is exactly the difference between a fresh installation and
  an upgraded one, and the reason this migration exists.
*/

/**
 * Recreates the pre-000016 table.
 *
 * @param WallosDatabase $db
 */
function notifications_recreate_legacy_table($db)
{
    // Deliberately portable and minimal: this stands in for a table that two
    // different backends spell differently, and only the column names matter.
    $db->exec('CREATE TABLE notifications (
        id INTEGER,
        enabled INTEGER DEFAULT 0,
        days INTEGER,
        smtp_address VARCHAR(255),
        smtp_port INTEGER,
        smtp_username VARCHAR(255),
        smtp_password VARCHAR(255),
        from_email VARCHAR(255),
        encryption TEXT DEFAULT \'tls\'
    )');
}

wallos_test('the leftover table is removed', function () {
    $db = wallos_test_open_database();
    notifications_recreate_legacy_table($db);

    assert_true($db->tableExists('notifications'), 'the fixture put it back');

    require WALLOS_ROOT . '/migrations/000065.php';

    assert_true(!$db->tableExists('notifications'), 'and the migration removed it');

    $db->close();
});

wallos_test('an installation that never had it is left alone', function () {
    // Fresh installations after 5.8.2, and any second run of the migration.
    $db = wallos_test_open_database();

    assert_true(!$db->tableExists('notifications'), 'not there to begin with');

    require WALLOS_ROOT . '/migrations/000065.php';

    assert_true(!$db->tableExists('notifications'), 'still not there, and nothing failed');

    $db->close();
});

wallos_test('rows that were never carried over are carried over now', function () {
    // The case the drop must not destroy. It should not exist — 000016 copies
    // before it drops — but "should not exist" is not a reason to delete
    // somebody's SMTP configuration.
    $db = wallos_test_open_database();
    notifications_recreate_legacy_table($db);
    $db->exec("DELETE FROM email_notifications");
    $db->exec("INSERT INTO notifications (enabled, days, smtp_address, smtp_port,
               smtp_username, smtp_password, from_email, encryption)
               VALUES (1, 7, 'smtp.example.com', 587, 'wallos', 'secret', 'billing@example.com', 'tls')");

    require WALLOS_ROOT . '/migrations/000065.php';

    assert_true(!$db->tableExists('notifications'), 'the old table is gone');
    assert_same('smtp.example.com', $db->scalar('SELECT smtp_address FROM email_notifications'),
        'and its settings survived in the new one');
    assert_same(587, (int) $db->scalar('SELECT smtp_port FROM email_notifications'), 'with the port');

    $db->close();
});

wallos_test('settings already carried over are not duplicated', function () {
    // The ordinary upgrade: 000016 did copy the rows, and only the drop failed.
    // Copying again would give the instance two SMTP configurations.
    $db = wallos_test_open_database();
    notifications_recreate_legacy_table($db);
    $db->exec("DELETE FROM email_notifications");
    $db->exec("INSERT INTO email_notifications (enabled, smtp_address) VALUES (1, 'already.example.com')");
    $db->exec("INSERT INTO notifications (enabled, smtp_address) VALUES (1, 'stale.example.com')");

    require WALLOS_ROOT . '/migrations/000065.php';

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM email_notifications'), 'still one row');
    assert_same('already.example.com', $db->scalar('SELECT smtp_address FROM email_notifications'),
        'and it is the one that was already there');
    assert_true(!$db->tableExists('notifications'), 'the stale table is gone either way');

    $db->close();
});

wallos_test('the drop result is checked', function () {
    // The defect being cleaned up was an unchecked exec. Repeating it here
    // would leave the same silence one migration further along.
    $source = file_get_contents(WALLOS_ROOT . '/migrations/000065.php');

    assert_true(strpos($source, "\$db->exec('DROP TABLE IF EXISTS notifications') === false") !== false,
        'the drop is checked');
    assert_true(strpos($source, 'error_log') !== false, 'and a failure is reported');
});
