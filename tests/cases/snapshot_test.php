<?php
/*
  dev/snapshot.sh keeps a copy of a real installation as a test input, and the
  manifest beside it is the only thing that says what that copy contains. These
  cases cover the part of it that makes a claim: how many rows violate which
  foreign key PostgreSQL will enforce.

  A wrong answer here is worse than no answer. "No orphans" on a database that
  has some is exactly the reassurance that makes an operator run the real
  migration at two in the morning.
*/

require_once WALLOS_ROOT . '/dev/snapshot.php';

wallos_test('the constraint list comes from the PostgreSQL baseline', function () {
    // Read from schema.sql rather than from the source database on purpose: the
    // question a rehearsal asks is not which references SQLite declares — it
    // declares some and enforces none — but which ones the target will enforce
    // when the data arrives.
    $keys = snapshot_baseline_foreign_keys();

    assert_true(count($keys) > 0, 'the baseline declares foreign keys');

    $named = [];
    foreach ($keys as $key) {
        $named[$key['name']] = $key;
        assert_true($key['table'] !== '' && $key['column'] !== '', 'each key names a child column');
    }

    $expected = 'subscriptions_payment_method_id_fkey';
    assert_true(isset($named[$expected]), 'the payment method reference is among them');
    assert_same('subscriptions', $named[$expected]['table'], 'on the subscriptions table');
    assert_same('payment_methods', $named[$expected]['parent'], 'pointing at payment_methods');
    assert_same('id', $named[$expected]['parentColumn'], 'at its id');
});

wallos_test('a row pointing at a parent that is gone is counted', function () {
    // SQLite-only by construction: the case has to create a row PostgreSQL
    // would refuse, which is the whole reason snapshots of real SQLite
    // installations are worth taking.
    if (wallos_test_skip_unless_sqlite('the row this case creates cannot exist in PostgreSQL')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'snapshot');
    $references = wallos_test_user_references($db, 1);

    $insert = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id,
         payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, 1.0, :currency, :next, 3, 1, :payer, :category, :payment, 0, 0, 1, 1)');
    $insert->bindValue(':name', 'orphan');
    $insert->bindValue(':currency', wallos_test_currency_id(1, 0));
    $insert->bindValue(':next', date('Y-m-d'));
    $insert->bindValue(':payer', $references['household']);
    $insert->bindValue(':category', $references['category']);
    // A payment method that was deleted while a subscription still used it.
    // endpoints/payments/delete.php does exactly this and reports success.
    $insert->bindValue(':payment', 987654);
    $insert->execute();

    $key = [
        'name' => 'subscriptions_payment_method_id_fkey',
        'table' => 'subscriptions',
        'column' => 'payment_method_id',
        'parent' => 'payment_methods',
        'parentColumn' => 'id',
    ];

    assert_same(1, snapshot_violations($db, $key), 'the dangling reference is counted');

    // The same query on a reference that resolves must stay at zero, otherwise
    // the count above proves nothing about the query.
    $categoryKey = [
        'name' => 'subscriptions_category_id_fkey',
        'table' => 'subscriptions',
        'column' => 'category_id',
        'parent' => 'categories',
        'parentColumn' => 'id',
    ];

    assert_same(0, snapshot_violations($db, $categoryKey), 'a reference that resolves is not a violation');

    $db->close();
});

wallos_test('an unset optional reference is not a violation', function () {
    if (wallos_test_skip_unless_sqlite('the fixture leans on SQLite accepting a partial row')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'snapshot');
    $references = wallos_test_user_references($db, 1);

    // No payment method at all, which is allowed: most subscriptions have none.
    // Counting NULL as a violation would report an orphan for every one of them
    // and bury the handful that matter.
    $insert = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id,
         notify, inactive, user_id, auto_renew)
        VALUES (:name, 1.0, :currency, :next, 3, 1, :payer, :category, 0, 0, 1, 1)');
    $insert->bindValue(':name', 'no payment method');
    $insert->bindValue(':currency', wallos_test_currency_id(1, 0));
    $insert->bindValue(':next', date('Y-m-d'));
    $insert->bindValue(':payer', $references['household']);
    $insert->bindValue(':category', $references['category']);
    $insert->execute();

    $key = [
        'name' => 'subscriptions_payment_method_id_fkey',
        'table' => 'subscriptions',
        'column' => 'payment_method_id',
        'parent' => 'payment_methods',
        'parentColumn' => 'id',
    ];

    assert_same(0, snapshot_violations($db, $key), 'NULL is not a dangling reference');

    $db->close();
});

wallos_test('the manifest reports the rows a snapshot holds', function () {
    if (wallos_test_skip_unless_sqlite('a snapshot is a SQLite file')) {
        return;
    }

    // The fixture database is a SQLite file with the real schema, which is what
    // a snapshot is.
    $path = wallos_test_database();
    $db = wallos_database_connect($path);
    wallos_test_create_user($db, 1, 'snapshot');
    $db->close();

    $manifest = snapshot_inventory($path, 'fixture');

    assert_contains('snapshot      fixture', $manifest, 'the manifest names the snapshot');
    assert_contains('rows per table', $manifest, 'and lists the tables');
    assert_contains('user', $manifest, 'including the account it holds');
    assert_contains('none — every reference resolves', $manifest,
        'a fixture built through the schema has no dangling references');

    @unlink($path);
});
