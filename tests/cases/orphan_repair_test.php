<?php
/*
  Rows left behind by an account that no longer exists.

  user.id is an INTEGER PRIMARY KEY that does not ask SQLite to keep counting,
  so a deleted id is handed out again: delete the newest account, create
  another, and it receives the same number. Rows the old account left behind then belong to the
  new one — another person's subscriptions, spending history and household
  members, shown as the new account's own, with nothing saying they were
  inherited (issue #92).

  Deletion has been complete and atomic since #81, so nothing new is being
  created. Migration 000067 removes what is already there.

  The distinction that makes this delicate: user_id 0 and NULL are not orphans.
  Older installations carry system payment methods belonging to nobody, and the
  application has always accepted them. A repair that took those as well would
  do more damage than the defect.
*/

require_once WALLOS_ROOT . '/includes/user_deletion.php';

/**
 * Runs migration 000067 against the open database.
 *
 * @param WallosDatabase $db
 * @return string what it printed
 */
function orphan_repair_run($db)
{
    ob_start();
    $outcome = require WALLOS_ROOT . '/migrations/000067.php';
    $printed = ob_get_clean();

    return $outcome === false ? 'FAILED: ' . $printed : $printed;
}

/**
 * @param WallosDatabase $db
 * @param string         $table
 * @param int            $userId
 * @param string         $name
 */
function orphan_repair_insert($db, $table, $userId, $name)
{
    $column = $table === 'categories' ? '(name, "order", user_id) VALUES (:name, 1, :user)'
        : '(name, email, user_id) VALUES (:name, :email, :user)';

    $statement = $db->prepare('INSERT INTO ' . $table . ' ' . $column);
    $statement->bindValue(':name', $name);

    if ($table !== 'categories') {
        $statement->bindValue(':email', $name . '@example.com');
    }

    $statement->bindValue(':user', $userId);
    $statement->execute();
}

wallos_test('rows of an account that no longer exists are removed', function () {
    $db = wallos_test_open_database();

    // 8100 is never created: these rows name an account that is not there,
    // which is exactly what an incomplete deletion leaves behind.
    orphan_repair_insert($db, 'categories', 8100, 'orphaned-category');
    orphan_repair_insert($db, 'household', 8100, 'orphaned-member');

    wallos_test_create_user($db, 8101, 'living');
    orphan_repair_insert($db, 'categories', 8101, 'living-category');

    $output = orphan_repair_run($db);

    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM categories WHERE user_id = 8100'),
        "the vanished account's category is gone");
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM household WHERE user_id = 8100'),
        'and its household member');

    // The half that matters as much: a repair that emptied the tables would
    // pass both assertions above.
    assert_same(1, (int) $db->scalar("SELECT COUNT(*) FROM categories
                                      WHERE user_id = 8101 AND name = 'living-category'"),
        "the living account's rows are untouched");

    assert_contains('categories', $output, 'the migration says which tables it touched');
    assert_contains('issue #92', $output, 'and why');

    $db->close();
});

wallos_test('rows belonging to the instance are left alone', function () {
    // user_id 0 is the convention older installations use for payment methods
    // that belong to nobody. Treating those as orphans would remove data every
    // account still references — a repair worse than the defect.
    $db = wallos_test_open_database();
    // An account has to exist, or the migration correctly declines to run at
    // all — see the fresh-installation case below.
    wallos_test_create_user($db, 8201, 'present');

    $statement = $db->prepare('INSERT INTO payment_methods (id, name, icon, enabled, "order", user_id)
                               VALUES (8200, :name, :icon, 1, 1, 0)');
    $statement->bindValue(':name', 'System card');
    $statement->bindValue(':icon', '');
    $statement->execute();

    $output = orphan_repair_run($db);

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM payment_methods WHERE id = 8200'),
        'the shared row is still there');
    assert_contains('user_id 0 or NULL', $output, 'and the migration says it left them alone');

    $db->close();
});

wallos_test('the repair covers every table with a user_id, not a list', function () {
    // The failure this shape has had twice — dev/seed.php and dev/bench.php
    // both carried lists that fell behind the schema. The migration asks the
    // database instead, so a table added later is covered.
    $source = file_get_contents(WALLOS_ROOT . '/migrations/000067.php');

    assert_contains("tablesWithColumn('user_id')", $source,
        'the tables come from the schema');
    assert_not_contains("'subscriptions', 'categories'", $source,
        'and not from a list written out here');
});

wallos_test('a repair that cannot finish is not recorded as done', function () {
    // A migration marked applied is never retried, so a repair that stopped
    // halfway would leave the rest of the orphans in place permanently — the
    // 000016 shape, in a migration whose whole job is cleaning up.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 8301, 'present');
    orphan_repair_insert($db, 'categories', 8300, 'blocked-orphan');
    wallos_test_block_writes($db, 'categories', 'DELETE');

    $outcome = orphan_repair_run($db);

    assert_contains('FAILED', $outcome, 'the migration reports failure');

    wallos_test_unblock_writes($db, 'categories');

    // And succeeds once the write can happen, so the false above is the write
    // failing rather than the migration refusing always.
    $again = orphan_repair_run($db);
    assert_true(strpos($again, 'FAILED') === false, 'it succeeds when the delete can run');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM categories WHERE user_id = 8300'),
        'and the orphan is gone');

    $db->close();
});

wallos_test('an installation with no accounts is left completely alone', function () {
    // The near miss this case exists for. createdatabase.php seeds the default
    // currencies, categories and payment methods against user_id 1 before
    // anyone has registered, so on a fresh database every one of those rows
    // names an account that does not exist — and a repair that trusted that
    // reading would empty the installation before its first user arrived.
    //
    // Found by running the migration against the schema generator's reference
    // database, which is exactly that state: 83 rows removed from a database
    // whose only fault was being new.
    $db = wallos_test_open_database();

    $delete = $db->prepare('DELETE FROM "user"');
    $delete->execute();

    $before = [
        'currencies' => (int) $db->scalar('SELECT COUNT(*) FROM currencies'),
        'categories' => (int) $db->scalar('SELECT COUNT(*) FROM categories'),
    ];

    assert_true($before['currencies'] > 0, 'the fixture has seeded rows to lose');

    orphan_repair_run($db);

    assert_same($before['currencies'], (int) $db->scalar('SELECT COUNT(*) FROM currencies'),
        'the seeded currencies are still there');
    assert_same($before['categories'], (int) $db->scalar('SELECT COUNT(*) FROM categories'),
        'and the categories');

    $db->close();
});
