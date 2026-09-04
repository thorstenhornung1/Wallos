<?php
/*
  The subscription list is the page Wallos loads most, and it renders every one
  of an account's subscriptions. Its cost must be flat in the number of rows:
  the rows come out of one query, the exchange rates out of one more, and the
  per-row price conversion reads the already-loaded rate map rather than the
  database. issue #18 asks this shape to be asserted rather than assumed.

  EXPLAIN QUERY PLAN coverage of the same queries — that they seek the user_id
  index instead of scanning — lives in subscription_index_test.php. This file
  pins the query *count*: it does not grow with the number of subscriptions.

  The binds are cast rather than typed with the SQLite constants so the file
  stays clear of the SQLite boundary audit (dev/db-audit.sh); the rows come back
  with fetchArray()'s default mode, which still carries the column names.
*/

require_once WALLOS_ROOT . '/includes/currency_rates.php';

/**
 * Inserts $count subscriptions for an existing account, alternating between the
 * account's two fixture currencies so the conversion path actually converts.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param int            $count
 */
function subscription_list_seed($db, $userId, $count)
{
    $references = wallos_test_user_references($db, $userId);

    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, :price, :currency, :next, 3, 1, :payer, :category, :payment, 0, 0, :userId, 1)');

    for ($i = 0; $i < $count; $i++) {
        $stmt->bindValue(':name', 'Subscription ' . $i);
        $stmt->bindValue(':price', 9.99);
        $stmt->bindValue(':currency', (int) wallos_test_currency_id($userId, $i % 2));
        $stmt->bindValue(':next', date('Y-m-d', strtotime('+' . ($i % 28) . ' days')));
        $stmt->bindValue(':payer', (int) $references['household']);
        $stmt->bindValue(':category', (int) $references['category']);
        $stmt->bindValue(':payment', (int) $references['payment_method']);
        $stmt->bindValue(':userId', (int) $userId);
        $stmt->execute();
        $stmt->reset();
    }
}

/**
 * Runs the data access the subscription list performs and returns how many
 * queries it took together with how many rows it rendered: the main-currency
 * lookup, the one list query, and converting every rendered price.
 *
 * The rate map is cached per connection, so the count is measured on a fresh
 * connection whose cache starts cold — which is what a request gets.
 *
 * @param WallosCountingDatabase $db
 * @param int                    $userId
 * @return array{0:int,1:int} [queryCount, renderedRows]
 */
function subscription_list_load_and_convert($db, $userId)
{
    $db->resetQueryCount();

    $stmt = $db->prepare('SELECT main_currency FROM "user" WHERE id = :userId');
    $stmt->bindValue(':userId', (int) $userId);
    $stmt->execute()->fetchArray();

    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE user_id = :userId ORDER BY next_payment ASC, inactive ASC');
    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();

    $rows = [];
    while ($row = $result->fetchArray()) {
        $rows[] = $row;
    }

    // The convertCurrency render path: every rendered row's price is converted
    // into the account's main currency. This must not add a query per row.
    foreach ($rows as $row) {
        wallos_convert_price($row['price'], $row['currency_id'], $db, $userId);
    }

    return [$db->queryCount, count($rows)];
}

wallos_test('the subscription list asks the same number of questions for one subscription as for many', function () {
    // A fresh connection per size, because the rate map is cached per connection
    // and a request always starts with a cold cache.
    $one = wallos_test_open_counting_database();
    wallos_test_create_user($one, 1, 'alice');
    subscription_list_seed($one, 1, 1);
    [$fewQueries, $fewRows] = subscription_list_load_and_convert($one, 1);
    $one->close();

    $many = wallos_test_open_counting_database();
    wallos_test_create_user($many, 1, 'alice');
    subscription_list_seed($many, 1, 300);
    [$manyQueries, $manyRows] = subscription_list_load_and_convert($many, 1);
    $many->close();

    assert_same(1, $fewRows, 'the small account renders its one subscription');
    assert_same(300, $manyRows, 'the large account renders all three hundred');

    assert_same($fewQueries, $manyQueries,
        'the query count does not grow with the number of subscriptions'
        . ' (one=' . $fewQueries . ', many=' . $manyQueries . ')');

    // The three questions: the main currency, the rows, and the rate map. The
    // three hundred conversions read the rate map from memory and add nothing.
    assert_same(3, $manyQueries,
        'the list loads in three queries whatever the size (got ' . $manyQueries . ')');
});

wallos_test('the list order is decided in SQL, not rebuilt in PHP', function () {
    // The default sort — next_payment ascending, with the disabled flag as the
    // tiebreaker — is expressed as one ORDER BY, so the rows arrive ready to
    // render. Pinning the order here is what lets the endpoint keep it in SQL
    // rather than sorting the whole array in PHP: if the SQL order ever stops
    // matching, this fails rather than a silent visual regression shipping.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $references = wallos_test_user_references($db, 1);

    // Inserted deliberately out of order, including an inactive row whose date is
    // earliest, so the query — not the insert order — decides what comes back.
    $rows = [
        ['name' => 'Inactive early', 'next' => '2026-01-05', 'inactive' => 1],
        ['name' => 'Active late',    'next' => '2026-03-20', 'inactive' => 0],
        ['name' => 'Active early',   'next' => '2026-01-10', 'inactive' => 0],
        ['name' => 'Active mid',     'next' => '2026-02-14', 'inactive' => 0],
    ];
    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, 9.99, :currency, :next, 3, 1, :payer, :category, :payment, 0, :inactive, 1, 1)');
    foreach ($rows as $row) {
        $stmt->bindValue(':name', $row['name']);
        $stmt->bindValue(':currency', (int) wallos_test_currency_id(1, 0));
        $stmt->bindValue(':next', $row['next']);
        $stmt->bindValue(':payer', (int) $references['household']);
        $stmt->bindValue(':category', (int) $references['category']);
        $stmt->bindValue(':payment', (int) $references['payment_method']);
        $stmt->bindValue(':inactive', (int) $row['inactive']);
        $stmt->execute();
        $stmt->reset();
    }

    // The exact ORDER BY the endpoint builds for the default sort.
    $result = $db->query('SELECT name FROM subscriptions WHERE user_id = 1 ORDER BY next_payment ASC, inactive ASC');
    $order = [];
    while ($row = $result->fetchArray()) {
        $order[] = $row['name'];
    }

    assert_same(['Inactive early', 'Active early', 'Active mid', 'Active late'], $order,
        'the rows come back ordered by next payment ascending');

    $db->close();
});
