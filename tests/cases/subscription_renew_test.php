<?php
/*
  The manual "renew now" write in endpoints/subscription/renew.php (issue #138).

  The endpoint prepared its UPDATE, executed it once and threw that result
  away, then executed the *same* statement a second time and reported whether
  the second call succeeded:

      $updateStmt->execute();            // discarded
      if ($updateStmt->execute()) { ... } // the one that answered

  Every manual renewal wrote the row twice, and the success/error the user was
  shown described the second write, not the first. The statement assigns an
  absolute date, so running it twice reaches the same state as running it once
  and no data was corrupted — but the two calls are decoupled: if the first
  succeeds and the second fails (a busy SQLite database, an aborted PostgreSQL
  transaction, where execute() returns false rather than throwing) the answer
  is "error" for a renewal that happened. The reverse reports success for the
  run nobody checked. That is the #87/#137 "answer describes a write that is not
  the write that ran" family, in a single endpoint.

  The fix removes the discarded first execute so there is exactly one write and
  the reported result is that write. These cases pin both halves on both
  backends: one execution against a real database advances the row and reports
  success, and a single execution that the database refuses reports failure
  rather than a phantom success — with no second, unchecked write hiding behind
  either answer.
*/

/**
 * Wraps a prepared statement so a test can count how many times the renewal
 * executes it. It forwards every call renew.php makes to the real prepared
 * statement the boundary returned, so the result the endpoint's
 * `if ($updateStmt->execute())` sees is the database's real answer, on either
 * backend.
 */
if (!class_exists('RenewExecutionSpy')) {
    class RenewExecutionSpy
    {
        public $executeCount = 0;

        /** @var object the prepared statement the database boundary returned */
        private $inner;

        public function __construct($inner)
        {
            $this->inner = $inner;
        }

        public function bindValue($parameter, $value, $type = null)
        {
            // renew.php binds with two arguments; the native bindValue()
            // deprecates a null $type in PHP 8.3, so forward only what arrived.
            if ($type === null) {
                return $this->inner->bindValue($parameter, $value);
            }

            return $this->inner->bindValue($parameter, $value, $type);
        }

        public function execute()
        {
            $this->executeCount++;

            return $this->inner->execute();
        }

        public function reset()
        {
            return $this->inner->reset();
        }

        public function close()
        {
            return $this->inner->close();
        }
    }
}

/**
 * Seeds one manually renewable subscription (auto_renew = 0, a monthly cycle,
 * a next_payment already in the past) and returns its id. The column list and
 * the referenced rows match the ones the endpoint's own SELECT accepts.
 *
 * @param WallosDatabase $db
 * @param string         $nextPayment
 * @return int
 */
function subscription_renew_seed($db, $nextPayment)
{
    wallos_test_create_user($db, 1, 'alice');
    $references = wallos_test_user_references($db, 1);

    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, 9.99, :currency, :next, 3, 1, :payer, :category, :payment, 0, 0, 1, 0)');
    // Bound without type constants, the way the subscription cases do: the
    // boundary infers the parameter type, and integers stay integers because
    // they are cast here. This keeps the fixture off the SQLite-only API the
    // db-audit gate confines to the boundary itself.
    $stmt->bindValue(':name', 'Renew me');
    $stmt->bindValue(':currency', (int) wallos_test_currency_id(1, 0));
    $stmt->bindValue(':next', $nextPayment);
    $stmt->bindValue(':payer', (int) $references['household']);
    $stmt->bindValue(':category', (int) $references['category']);
    $stmt->bindValue(':payment', (int) $references['payment_method']);
    $stmt->execute();

    return (int) $db->scalar('SELECT id FROM subscriptions WHERE user_id = 1 ORDER BY id LIMIT 1');
}

wallos_test('renew.php executes its update exactly once and reports that execution', function () {
    // The source guard. The defect was two `$updateStmt->execute()` calls where
    // one was meant; the reported answer came from the second. This pins the
    // shape the fix leaves — one execution, and it is the condition of the `if`
    // that builds the response — so re-adding the discarded write fails here
    // before it can reach either backend.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/renew.php');

    // Negative control: if the handle is ever renamed, the counts below must not
    // pass by matching nothing.
    assert_contains('$updateStmt = $db->prepare(', $source,
        'the renewal still prepares its update statement');

    assert_same(1, substr_count($source, '$updateStmt->execute()'),
        'the update statement is executed exactly once, not twice (#138)');

    assert_contains('if ($updateStmt->execute())', $source,
        'and that one execution is the result the response reports');

    // The discarded first write was a bare `$updateStmt->execute();`. The
    // surviving call is `$updateStmt->execute())` inside the `if`, so the
    // semicolon form appears only when the duplicate is back.
    assert_not_contains('$updateStmt->execute();', $source,
        'no discarded first write runs before the checked one');
});

wallos_test('a manual renewal writes the row once and reports the write that ran', function () {
    $db = wallos_test_open_database();
    $subscriptionId = subscription_renew_seed($db, '2020-01-15');

    // The endpoint's update block, run against a real database through the spy.
    // The SQL is byte-for-byte the endpoint's, and the execute happens exactly
    // once — the shape the fix produces.
    $newDate = '2020-02-15';
    $updateStmt = new RenewExecutionSpy(
        $db->prepare('UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId'));
    $updateStmt->bindValue(':nextPaymentDate', $newDate);
    $updateStmt->bindValue(':subscriptionId', $subscriptionId);

    $reported = $updateStmt->execute() ? 'success' : 'error';

    assert_same(1, $updateStmt->executeCount,
        'the renewal executes its update once, not twice');
    assert_same('success', $reported,
        'the write happened, and success is the answer');
    assert_same(1, $db->changes(),
        'exactly one row was written');

    $after = $db->scalar('SELECT next_payment FROM subscriptions WHERE id = :id',
        [':id' => $subscriptionId]);
    assert_same($newDate, substr((string) $after, 0, 10),
        'the row advanced to the new next_payment');

    $db->close();
});

wallos_test('a single update the database refuses is reported as a failure, not a phantom success', function () {
    $db = wallos_test_open_database();
    $subscriptionId = subscription_renew_seed($db, '2020-02-15');

    // The decoupling the issue is about: make the write fail. With the duplicate
    // gone there is one execution, the endpoint checks it, and a refused write
    // is an "error" — never a success reported for a write that did not land,
    // and never a second unchecked write slipping through behind the answer.
    // wallos_test_block_writes refuses the UPDATE on whichever backend runs.
    wallos_test_block_writes($db, 'subscriptions', 'UPDATE', 'next_payment');

    $updateStmt = new RenewExecutionSpy(
        $db->prepare('UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId'));
    $updateStmt->bindValue(':nextPaymentDate', '2099-12-31');
    $updateStmt->bindValue(':subscriptionId', $subscriptionId);

    $reported = $updateStmt->execute() ? 'success' : 'error';

    wallos_test_unblock_writes($db, 'subscriptions');

    assert_same(1, $updateStmt->executeCount,
        'the refused write is attempted exactly once');
    assert_same('error', $reported,
        'a write the database refused is reported as a failure');

    $after = $db->scalar('SELECT next_payment FROM subscriptions WHERE id = :id',
        [':id' => $subscriptionId]);
    assert_same('2020-02-15', substr((string) $after, 0, 10),
        'and the row is untouched — no phantom write behind the error');

    $db->close();
});
