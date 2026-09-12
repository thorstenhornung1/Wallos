<?php
/*
  The notifications table that migration 000016 removes actually goes.

  000016 splits the old single notifications table into the per-provider tables
  and drops it. It has never managed the drop, for two independent reasons, and
  the table is present on every installation ever made:

    - The migration reads SELECT COUNT(*) FROM notifications and drops the table
      while that result set is still open. The open cursor holds a shared read
      lock, SQLite answers the DROP with "database table is locked", and the
      exec() result is not read — so the migration records itself as applied
      with its work undone.

    - createdatabase.php runs on every container start and recreates the table
      whenever it is missing. Its v0.9-to-v1.0 block asks whether the table
      exists, not whether the installation is from v0.9, so even a drop that
      worked would be undone by the next restart.

  The second one is why this is one change rather than two: fixing the migration
  alone would have held until the next restart, which is worse than not fixing
  it, because it would have looked fixed.

  Nothing reads the table — searching for "FROM notifications" outside
  migrations/ finds nothing — so it is dead weight in every backup and every
  restore rather than a correctness problem. What it is evidence of is the
  correctness problem: a migration that reports success over a statement nobody
  checked.
*/

/**
 * A throwaway tree with the files a start needs, and nothing else.
 *
 * @return string the sandbox path
 */
function notifications_removal_sandbox()
{
    $sandbox = WALLOS_TEST_TMP . '/notifications-removal-' . uniqid('', true);

    foreach (['db', 'migrations', 'endpoints/cronjobs', 'includes'] as $directory) {
        mkdir($sandbox . '/' . $directory, 0700, true);
    }

    foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $migration) {
        copy($migration, $sandbox . '/migrations/' . basename($migration));
    }

    copy(WALLOS_ROOT . '/endpoints/cronjobs/createdatabase.php',
        $sandbox . '/endpoints/cronjobs/createdatabase.php');
    copy(WALLOS_ROOT . '/includes/run_migrations.php',
        $sandbox . '/includes/run_migrations.php');

    return $sandbox;
}

/**
 * One container start: createdatabase.php, then the migration chain.
 *
 * @param string $sandbox
 * @param bool   $withMigrations
 */
function notifications_removal_start($sandbox, $withMigrations = true)
{
    ob_start();
    require $sandbox . '/endpoints/cronjobs/createdatabase.php';

    if ($withMigrations) {
        $db = new SQLite3($sandbox . '/db/wallos.db');
        $db->busyTimeout(5000);
        require $sandbox . '/includes/run_migrations.php';
        $db->close();
    }

    ob_end_clean();
}

/**
 * @param string $sandbox
 * @return bool
 */
function notifications_table_present($sandbox)
{
    $db = new SQLite3($sandbox . '/db/wallos.db');
    $present = (bool) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'");
    $db->close();

    return $present;
}

wallos_test('a fresh installation does not end up with the table the migration removes', function () {
    $sandbox = notifications_removal_sandbox();
    notifications_removal_start($sandbox);

    assert_true(!notifications_table_present($sandbox),
        'the notifications table is gone after the first start');

    $db = new SQLite3($sandbox . '/db/wallos.db');
    assert_true((int) $db->querySingle("SELECT COUNT(*) FROM migrations WHERE migration LIKE '%000016.php'") > 0,
        'and the migration that removed it is recorded');
    $db->close();
});

wallos_test('the next container start does not put it back', function () {
    // The half that decides whether the first one is worth anything. Without
    // it the table returns on every restart and the fix looks like it worked
    // exactly once.
    $sandbox = notifications_removal_sandbox();
    notifications_removal_start($sandbox);
    notifications_removal_start($sandbox);
    notifications_removal_start($sandbox, false);

    assert_true(!notifications_table_present($sandbox),
        'the table stays gone across further starts');
});

wallos_test('an installation that still has the old table keeps its settings', function () {
    // The drop has never run, so the copy above it has never been followed by
    // anything irreversible. It is now, which makes this worth asserting rather
    // than assuming.
    $sandbox = notifications_removal_sandbox();
    notifications_removal_start($sandbox);

    $db = new SQLite3($sandbox . '/db/wallos.db');
    $db->busyTimeout(5000);

    // The shape an installation from before the split has it in.
    $db->query('CREATE TABLE notifications (
        id INTEGER PRIMARY KEY,
        enabled BOOLEAN DEFAULT false,
        days INTEGER,
        smtp_address VARCHAR(255),
        smtp_port INTEGER,
        smtp_username VARCHAR(255),
        smtp_password VARCHAR(255),
        from_email VARCHAR(255),
        encryption VARCHAR(255)
    )');
    $db->query("INSERT INTO notifications (enabled, days, smtp_address, smtp_port, smtp_username,
                smtp_password, from_email, encryption)
                VALUES (1, 5, 'mail.example.com', 587, 'user', 'secret', 'wallos@example.com', 'tls')");

    $db->query('DELETE FROM email_notifications');
    $db->query('DELETE FROM notification_settings');

    require $sandbox . '/migrations/000016.php';

    $email = $db->querySingle('SELECT smtp_address FROM email_notifications', true);
    $settings = $db->querySingle('SELECT days FROM notification_settings', true);

    assert_same('mail.example.com', $email['smtp_address'] ?? null,
        'the mail server was carried into email_notifications before the drop');
    assert_same(5, (int) ($settings['days'] ?? 0),
        'and the reminder days into notification_settings');

    assert_true((int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'") === 0,
        'and only then was the old table dropped');

    $db->close();
});

wallos_test('the migration finalises its read before it drops what it read', function () {
    // The one-line cause, named where the next person editing this migration
    // will meet it.
    $source = file_get_contents(WALLOS_ROOT . '/migrations/000016.php');

    $read = strpos($source, "SELECT COUNT(*) as count FROM notifications");
    $finalize = strpos($source, '$result->finalize()');
    $drop = strpos($source, 'DROP TABLE IF EXISTS notifications');

    assert_true($read !== false && $finalize !== false && $drop !== false,
        'the read, the finalise and the drop are all there');
    assert_true($read < $finalize && $finalize < $drop,
        'and the finalise sits between them');
});
