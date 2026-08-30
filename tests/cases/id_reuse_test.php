<?php
/*
  Deleted-account rows, and the mechanism that let them be adopted (#92).

  The repair migration (000067) removed the rows already orphaned, and #81
  made deletion atomic — what stayed open were the two structural halves.
  First: account ids were handed out again. Delete the newest account and
  the next one created receives the same id, at which point any row a failed
  deletion ever left behind becomes the new account's own data — another
  person's subscriptions, spending history and household members, with
  nothing marking them as inherited. Second: the declared foreign keys were
  never enforced. Three tables promise ON DELETE CASCADE and the promise had
  never once been kept, because the enforcement was off and switched on
  nowhere.

  PostgreSQL enforces the declared keys unconditionally and its suite is
  green, which is the standing proof that the application's write paths
  survive enforcement. These cases pin the same behaviour on SQLite.
*/

wallos_test('a deleted account id is never handed out again', function () {
    // The exposure is specific: reuse happens when the deleted id was the
    // highest, so two accounts and the newer one deleted is the exact shape.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 6, 'older');
    wallos_test_create_user($db, 7, 'newest');

    $stmt = $db->prepare('DELETE FROM "user" WHERE id = 7');
    assert_true($stmt !== false && $stmt->execute() !== false, 'the newest account is deleted');

    $stmt = $db->prepare('INSERT INTO "user" (username, email, password, main_currency)
                          VALUES (:name, :mail, :pw, :currency)');
    $stmt->bindValue(':name', 'replacement', SQLITE3_TEXT);
    $stmt->bindValue(':mail', 'replacement@example.com', SQLITE3_TEXT);
    $stmt->bindValue(':pw', 'x', SQLITE3_TEXT);
    $stmt->bindValue(':currency', wallos_test_currency_id(6, 0), SQLITE3_INTEGER);
    assert_true($stmt->execute() !== false, 'a new account is created without naming an id');

    $newId = (int) $db->lastInsertRowID();
    assert_true($newId !== 7, 'the freed id is not recycled (got ' . $newId . ')');

    $db->close();
});

wallos_test('the declared cascades fire when an account goes', function () {
    // login_tokens, user_roles and oidc_sessions have promised ON DELETE
    // CASCADE for years; this is the promise actually being kept.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare("INSERT INTO login_tokens (user_id, token) VALUES (1, 'tok-1')");
    assert_true($stmt !== false && $stmt->execute() !== false, 'a remember-me token exists');

    $stmt = $db->prepare('DELETE FROM "user" WHERE id = 1');
    assert_true($stmt !== false && $stmt->execute() !== false, 'the account is deleted');

    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE user_id = 1'),
        'the token went with it');

    $db->close();
});

wallos_test('a write naming a missing account is refused', function () {
    // The write path that used to create orphans now ends in an honest
    // database error instead of a row nobody owns.
    $db = wallos_test_open_database();

    $stmt = $db->prepare("INSERT INTO login_tokens (user_id, token) VALUES (424242, 'tok-orphan')");
    assert_true($stmt !== false, 'the statement prepares');
    assert_true($stmt->execute() === false, 'and the database refuses it');

    $db->close();
});

wallos_test('the id rebuild keeps rows, columns and constraints', function () {
    if (wallos_test_skip_unless_sqlite('the rebuild is the SQLite half; sequences are monotonic already')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'keep-a');
    wallos_test_create_user($db, 9, 'keep-b');

    // Running it again against an already-rebuilt table must change nothing:
    // an interrupted upgrade retries the migration.
    assert_true($db->rebuildWithMonotonicIds('user'), 'the rebuild reports success');

    assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM "user"'), 'both accounts survive');
    assert_same('keep-b', (string) $db->scalar('SELECT username FROM "user" WHERE id = 9'),
        'ids still name the same rows');
    assert_true($db->columnExists('user', 'main_currency'), 'the columns are all there');

    // The rebuilt table still declares its own foreign key.
    $stmt = $db->prepare('UPDATE "user" SET main_currency = 424242 WHERE id = 4');
    assert_true($stmt !== false, 'the statement prepares');
    assert_true($stmt->execute() === false, 'a currency that does not exist is refused');

    $db->close();
});

wallos_test('violations are visible to the boundary before enforcement bites', function () {
    // The repair migration asks this instead of guessing; on the other
    // backend the answer is empty by construction, because nothing violating
    // could ever have been written.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    if ($db->driver() === 'sqlite') {
        assert_true($db->setForeignKeyEnforcement(false), 'enforcement can pause for repair work');

        $stmt = $db->prepare("INSERT INTO totp (user_id, totp_secret, backup_codes) VALUES (424242, 's', '[]')");
        assert_true($stmt !== false && $stmt->execute() !== false,
            'with enforcement paused the orphan lands, as it did for years');

        $violations = $db->foreignKeyViolations();
        assert_true(count($violations) > 0, 'the check sees it');
        assert_same('totp', (string) $violations[0]['table'], 'and names the table');

        $stmt = $db->prepare('DELETE FROM totp WHERE user_id = 424242');
        $stmt->execute();
        assert_true($db->setForeignKeyEnforcement(true), 'enforcement resumes');
    }

    assert_same([], $db->foreignKeyViolations(), 'a clean database answers with nothing');

    $db->close();
});

wallos_test('the repair migration removes derived orphans and refuses to guess', function () {
    if (wallos_test_skip_unless_sqlite('the other backend never accumulated violations to repair')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // A derived orphan — the kind years of unenforced keys left behind.
    $db->setForeignKeyEnforcement(false);
    $stmt = $db->prepare("INSERT INTO totp (user_id, totp_secret, backup_codes) VALUES (424242, 's', '[]')");
    $stmt->execute();
    $db->setForeignKeyEnforcement(true);

    $outcome = require WALLOS_ROOT . '/migrations/000072.php';
    assert_true($outcome !== false, 'derived orphans are repaired');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM totp WHERE user_id = 424242'),
        'and the orphan is gone');

    // Business data is different: a subscription or an account pointing at
    // nothing is not the migration's call to delete. It refuses instead.
    $db->setForeignKeyEnforcement(false);
    $stmt = $db->prepare('UPDATE "user" SET main_currency = 424242 WHERE id = 1');
    $stmt->execute();
    $db->setForeignKeyEnforcement(true);

    $outcome = require WALLOS_ROOT . '/migrations/000072.php';
    assert_true($outcome === false, 'a violation in business data stops the migration');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM "user" WHERE id = 1'),
        'and nothing was deleted');

    $db->close();
});
