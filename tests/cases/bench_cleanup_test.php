<?php
/*
  What the benchmark leaves behind.

  dev/seed.php seeds in tiers — the benchmark runs 1, then 10, then 100 accounts
  — and removes the previous tier at the start of the next. It did that from a
  list of five tables written out in the file, and email_notifications was not
  on it. So tiers one and two left eleven notification rows pointing at accounts
  that no longer existed, on every run, while the cleanup reported a clean
  removal and was telling the truth about the rows it knew of (issue #98).

  Nothing objected. PostgreSQL enforces seven foreign keys on user_id and
  email_notifications carries none of them, so the rows were legal. Had they
  been in login_tokens or totp the account delete itself would have failed
  loudly — worth stating, because "PostgreSQL would have caught it" is the
  assumption that let a hand-written list stand in the first place.

  Both scripts now take the tables from wallos_user_deletion_plan(), which
  derives them from the live schema: every base table with a user_id column
  holds rows for an account. The cases below are about that plan covering what a
  list missed, and about the report the cleanup makes on what it could not
  remove — because "what I deleted" and "what is left" are different questions,
  and only the second one would have shown this.
*/

require_once WALLOS_ROOT . '/includes/user_deletion.php';
require_once WALLOS_ROOT . '/dev/bench.php';

/**
 * Gives an account a notification row, the way dev/bench.php does before it
 * measures the cron.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 */
function bench_cleanup_notify($db, $userId)
{
    $statement = $db->prepare('INSERT INTO email_notifications (enabled, smtp_mode, user_id)
                               VALUES (1, :mode, :userId)');
    $statement->bindValue(':mode', 'instance');
    $statement->bindValue(':userId', (int) $userId);
    $statement->execute();
}

wallos_test('the deletion plan covers the table the hand-written list missed', function () {
    $db = wallos_test_open_database();

    $tables = [];
    foreach (wallos_user_deletion_plan($db) as $step) {
        $tables[] = $step['table'];
    }

    assert_true(in_array('email_notifications', $tables, true),
        'email_notifications is in the plan');

    // Not an assertion about one table. The plan is derived from the schema, so
    // the property worth holding is that it covers everything carrying a
    // user_id — which is what makes the next table added safe without anyone
    // editing dev/seed.php.
    foreach ($db->tablesWithColumn('user_id') as $owned) {
        assert_true(in_array($owned, $tables, true), $owned . ' is in the plan');
    }

    assert_true(count($tables) > 10, 'the plan was actually built');

    $db->close();
});

wallos_test('removing a seeded account leaves nothing pointing at it', function () {
    $db = wallos_test_open_database();

    wallos_test_create_user($db, 4001, 'seed-one');
    wallos_test_create_user($db, 4002, 'seed-two');
    bench_cleanup_notify($db, 4001);
    bench_cleanup_notify($db, 4002);

    assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM email_notifications'),
        'both accounts have a notification row');

    $first = wallos_delete_user($db, 4001);
    $second = wallos_delete_user($db, 4002);

    assert_true($first['success'] && $second['success'], 'both accounts were removed');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM "user" WHERE id IN (4001, 4002)'),
        'the accounts are gone');
    // Asked of the table this is about rather than of the whole database: the
    // fixture builds its default categories, currencies and payment methods for
    // account 1 without creating account 1, so a whole-database assertion would
    // be measuring the fixture.
    $orphans = bench_orphans($db);
    assert_same(0, $orphans['email_notifications'] ?? 0,
        'and so are their notification rows — this is the eleven rows per run');

    $db->close();
});

wallos_test('the cleanup reports what it could not remove', function () {
    $db = wallos_test_open_database();

    // An orphan from somewhere else entirely: an account removed by a flow
    // predating includes/user_deletion.php, which is how a real installation
    // acquires these.
    bench_cleanup_notify($db, 999001);

    $orphans = bench_orphans($db);

    assert_true(isset($orphans['email_notifications']), 'the orphan is found');
    assert_same(1, $orphans['email_notifications'], 'and counted');

    $delete = $db->prepare('DELETE FROM email_notifications WHERE user_id = :userId');
    $delete->bindValue(':userId', 999001);
    $delete->execute();

    // The negative control. Without it, a function reporting every table as
    // holding orphans would pass the two assertions above.
    assert_same(0, bench_orphans($db)['email_notifications'] ?? 0,
        'the table reports nothing once the row is gone');

    $db->close();
});

wallos_test('neither script keeps its own list of per-account tables', function () {
    // The gate on the fix rather than on the symptom: what went wrong was a
    // list in a file, and the fix is that there is no list. A new one would
    // pass every behavioural case above and fail the same way in a year.
    foreach (['dev/seed.php', 'dev/bench.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_contains('wallos_user_deletion_plan', $source,
            $path . ' takes the per-account tables from the schema');
    }
});
