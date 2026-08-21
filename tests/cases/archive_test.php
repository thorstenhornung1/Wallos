<?php
/*
  Backup and restore that do not depend on which database is underneath.

  Backup moved db/wallos.db. On SQLite that is the whole durability story; on
  PostgreSQL db/ holds only setup_token.db, so a PostgreSQL installation had no
  backup through the interface at all — first silently, then refused honestly
  since 5.8.2 (issue #23).

  These cases exercise the rows-not-files archive. Two failures only PostgreSQL
  can have are asserted rather than assumed:

    order      — parents before children, or the foreign keys reject the rows.
                 SQLite declares its foreign keys and never enforces them, so a
                 restore can be wrong there and look right.
    sequences  — inserting explicit ids does not move a serial sequence, so the
                 first write after a restore collides with a row the restore
                 just put in. Hours later, somewhere unrelated.
*/

require_once WALLOS_ROOT . '/includes/db/archive.php';

/**
 * @return string
 */
function archive_test_path()
{
    return WALLOS_TEST_TMP . '/archive-' . uniqid('', true) . '.zip';
}

/**
 * An account with rows in the tables that reference each other, so a restore
 * has something to get the order wrong about.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $name
 */
function archive_test_seed($db, $userId, $name)
{
    wallos_test_create_user($db, $userId, $name);

    $references = wallos_test_user_references($db, $userId);
    $currency = wallos_test_currency_id($userId, 0);

    $statement = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id,
         category_id, payment_method_id, notify, inactive, auto_renew, user_id)
        VALUES (:name, 9.99, :currency, :next, 3, 1, :payer, :category, :method, 0, 0, 0, :user)');
    $statement->bindValue(':name', 'archived-' . $name);
    $statement->bindValue(':currency', (int) $currency);
    $statement->bindValue(':next', '2099-01-01');
    $statement->bindValue(':payer', (int) $references['household']);
    $statement->bindValue(':category', (int) $references['category']);
    $statement->bindValue(':method', (int) $references['payment_method']);
    $statement->bindValue(':user', (int) $userId);
    $statement->execute();
}

/**
 * @param WallosDatabase $db
 * @param string         $sql
 */
function archive_test_run($db, $sql)
{
    $statement = $db->prepare($sql);

    if ($statement !== false) {
        $statement->execute();
    }
}

wallos_test('an archive carries every table and says what it holds', function () {
    $db = wallos_test_open_database();
    archive_test_seed($db, 7001, 'archived');

    $path = archive_test_path();
    $result = wallos_archive_export($db, $path);

    assert_true($result['success'], 'the export succeeded: ' . (string) $result['error']);
    assert_true($result['tables'] > 30, 'every table was written, not a hardcoded few');
    assert_true($result['rows'] > 0, 'and it holds rows');

    $zip = new ZipArchive();
    assert_true($zip->open($path) === true, 'the archive opens');

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    assert_same(WALLOS_ARCHIVE_VERSION, $manifest['format'], 'the format is recorded');
    assert_same($db->driver(), $manifest['driver'], 'and which backend it came from');
    assert_true($manifest['contains_secrets'], 'and that it holds credentials in clear text');
    assert_true(isset($manifest['tables']['subscriptions']), 'the row counts are per table');

    // The rows themselves, not just a file with the right name.
    $rows = json_decode((string) $zip->getFromName('data/subscriptions.json'), true);
    $names = array_column($rows, 'name');
    assert_true(in_array('archived-archived', $names, true), 'the subscription is in the archive');

    $zip->close();
    @unlink($path);
    $db->close();
});

wallos_test('a restore puts back exactly what was there', function () {
    $db = wallos_test_open_database();
    archive_test_seed($db, 7002, 'roundtrip');

    $before = [
        'users' => (int) $db->scalar('SELECT COUNT(*) FROM "user"'),
        'subscriptions' => (int) $db->scalar('SELECT COUNT(*) FROM subscriptions'),
        'currencies' => (int) $db->scalar('SELECT COUNT(*) FROM currencies'),
    ];

    $path = archive_test_path();
    assert_true(wallos_archive_export($db, $path)['success'], 'exported');

    // Something to overwrite, so a restore that did nothing would be visible.
    archive_test_run($db, 'DELETE FROM subscriptions');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM subscriptions'), 'the rows are gone');

    $restored = wallos_archive_import($db, $path);

    assert_true($restored['success'], 'the restore succeeded: ' . (string) $restored['error']);
    assert_same($before['subscriptions'], (int) $db->scalar('SELECT COUNT(*) FROM subscriptions'),
        'the subscriptions are back');
    assert_same($before['users'], (int) $db->scalar('SELECT COUNT(*) FROM "user"'),
        'and the accounts');
    assert_same($before['currencies'], (int) $db->scalar('SELECT COUNT(*) FROM currencies'),
        'and the currencies');

    assert_same('archived-roundtrip',
        (string) $db->scalar('SELECT name FROM subscriptions WHERE user_id = 7002'),
        'with their values, not just their number');

    @unlink($path);
    $db->close();
});

wallos_test('writing after a restore does not collide with what was restored', function () {
    // The PostgreSQL-only failure: an explicit id does not advance a serial
    // sequence, so the next insert reuses an id the restore just wrote. It
    // surfaces hours later on an unrelated page, which is why it is asserted
    // here rather than left to be discovered.
    $db = wallos_test_open_database();
    archive_test_seed($db, 7003, 'sequence');

    $path = archive_test_path();
    assert_true(wallos_archive_export($db, $path)['success'], 'exported');
    $restored = wallos_archive_import($db, $path);
    assert_true($restored['success'], 'restored: ' . (string) $restored['error']);

    $statement = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, 1, :user)');
    $statement->bindValue(':name', 'after-restore');
    $statement->bindValue(':user', 7003);

    assert_true($statement->execute() !== false,
        'a write after the restore succeeds: ' . $db->lastErrorMsg());

    @unlink($path);
    $db->close();
});

wallos_test('a restore that cannot finish leaves the database as it was', function () {
    // The worst outcome for a restore is neither the old data nor the new: one
    // transaction, so a failure halfway is a failure with nothing changed.
    $db = wallos_test_open_database();
    archive_test_seed($db, 7004, 'atomic');

    $path = archive_test_path();
    assert_true(wallos_archive_export($db, $path)['success'], 'exported');

    $before = (int) $db->scalar('SELECT COUNT(*) FROM subscriptions');

    // An archive whose manifest is fine and whose rows are not.
    $zip = new ZipArchive();
    $zip->open($path);
    $zip->addFromString('data/subscriptions.json', 'not json at all');
    $zip->close();

    $result = wallos_archive_import($db, $path);

    assert_true(!$result['success'], 'the restore refused');
    assert_contains('subscriptions', (string) $result['error'], 'and said which table');
    assert_same($before, (int) $db->scalar('SELECT COUNT(*) FROM subscriptions'),
        'the rows that were there are still there');

    @unlink($path);
    $db->close();
});

wallos_test('an archive from a newer Wallos is refused rather than half-read', function () {
    $db = wallos_test_open_database();
    $path = archive_test_path();
    assert_true(wallos_archive_export($db, $path)['success'], 'exported');

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->addFromString('manifest.json', json_encode([
        'format' => WALLOS_ARCHIVE_VERSION + 1,
        'wallos_version' => 'v9.9.9',
    ]));
    $zip->close();

    $result = wallos_archive_import($db, $path);

    assert_true(!$result['success'], 'a newer format is refused');
    assert_contains('newer version', (string) $result['error'], 'and says so');

    @unlink($path);
    $db->close();
});

wallos_test('uploaded files survive the round trip, and only files that should', function () {
    $db = wallos_test_open_database();
    $uploads = WALLOS_TEST_TMP . '/uploads-' . uniqid('', true);
    mkdir($uploads . '/logos', 0700, true);
    file_put_contents($uploads . '/logos/keep.png', 'PNG-CONTENT');

    $path = archive_test_path();
    assert_true(wallos_archive_export($db, $path, $uploads)['success'], 'exported with uploads');

    // Entries that must never be written anywhere: the restore endpoint refuses
    // PHP in archives, and this half has to as well.
    $zip = new ZipArchive();
    $zip->open($path);
    $zip->addFromString('uploads/logos/evil.php', '<?php echo 1;');
    $zip->addFromString('uploads/../escape.png', 'ESCAPED');
    $zip->close();

    $target = WALLOS_TEST_TMP . '/restored-' . uniqid('', true);
    mkdir($target, 0700, true);

    $imported = wallos_archive_import($db, $path, $target);
    assert_true($imported['success'], 'the restore succeeded: ' . (string) $imported['error']);

    assert_true(is_file($target . '/logos/keep.png'), 'the image came back');
    assert_same('PNG-CONTENT', file_get_contents($target . '/logos/keep.png'), 'with its contents');
    assert_true(!is_file($target . '/logos/evil.php'), 'the PHP file was not written');
    assert_true(!is_file(dirname($target) . '/escape.png'), 'and nothing escaped the target');

    @unlink($path);
    $db->close();
});
