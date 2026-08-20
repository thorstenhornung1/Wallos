<?php
/*
  A subscription may only point at rows of the account that owns it.

  Six values arrive from a request on every subscription write — category_id,
  payment_method_id, payer_user_id, currency_id, cycle and frequency — and the
  two write paths used to disagree about them (issue #82). The REST endpoint
  checked four ids for existence and ownership and forgot the frequency; the
  form endpoint checked nothing at all, so a request could name another
  account's category and the row was written pointing at it.

  Both paths now hand those values to wallos_validate_subscription_input(), so
  that function is what the behavioural cases below exercise: testing it once is
  testing both paths, and it is the only place where "valid" is defined. What a
  shared helper cannot guarantee on its own is that both paths keep calling it,
  so the last group of cases reads the endpoints themselves and fails if either
  one goes back to binding a request value straight into the statement.
*/

require_once WALLOS_ROOT . '/includes/reference_validation.php';

/**
 * Two accounts, each owning a currency, a category, a payment method and a
 * household member, so "belongs to somebody else" is a case that can be posed.
 *
 * @param WallosDatabase $db
 * @return array{alice: array, bob: array}
 */
function subscription_references_fixture($db)
{
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    return [
        'alice' => wallos_test_user_references($db, 1) + ['currency' => wallos_test_currency_id(1, 0)],
        'bob' => wallos_test_user_references($db, 2) + ['currency' => wallos_test_currency_id(2, 0)],
    ];
}

/**
 * A request body that passes validation, so a case can change one field of it
 * and be sure that field is the reason for the outcome.
 *
 * @param array $owner Reference ids from the fixture.
 * @return array
 */
function subscription_references_input($owner)
{
    return [
        'currency_id' => (string) $owner['currency'],
        'category_id' => (string) $owner['category'],
        'payer_user_id' => (string) $owner['household'],
        'payment_method_id' => (string) $owner['payment_method'],
        'cycle' => '3',
        'frequency' => '1',
    ];
}

wallos_test('a valid subscription is accepted and written', function () {
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    $result = wallos_validate_subscription_input($db, 1, subscription_references_input($ids['alice']));

    assert_true($result['valid'], "the account's own ids are accepted");
    assert_same($ids['alice']['currency'], $result['values']['currency_id'], 'the currency comes back as an integer');
    assert_same($ids['alice']['category'], $result['values']['category_id'], 'the category comes back as an integer');
    assert_same($ids['alice']['household'], $result['values']['payer_user_id'], 'the payer comes back as an integer');
    assert_same($ids['alice']['payment_method'], $result['values']['payment_method_id'], 'the payment method comes back as an integer');
    assert_same(3, $result['values']['cycle'], 'the cycle comes back as an integer');
    assert_same(1, $result['values']['frequency'], 'the frequency comes back as an integer');

    // The assertion people forget: the accepted values must still produce a row.
    // On PostgreSQL this is the only case here that exercises the foreign keys
    // themselves, because every other case stops before the insert.
    $statement = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payment_method_id, payer_user_id, category_id, user_id)
        VALUES (:name, :price, :currency, :next, :cycle, :frequency, :paymentMethod, :payer, :category, :userId)');
    $statement->bindValue(':name', 'Valid subscription');
    $statement->bindValue(':price', 9.99);
    $statement->bindValue(':currency', $result['values']['currency_id']);
    $statement->bindValue(':next', '2026-01-01');
    $statement->bindValue(':cycle', $result['values']['cycle']);
    $statement->bindValue(':frequency', $result['values']['frequency']);
    $statement->bindValue(':paymentMethod', $result['values']['payment_method_id']);
    $statement->bindValue(':payer', $result['values']['payer_user_id']);
    $statement->bindValue(':category', $result['values']['category_id']);
    $statement->bindValue(':userId', 1);

    assert_true($statement->execute() !== false, 'the validated values insert cleanly: ' . $db->lastErrorMsg());
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM subscriptions WHERE name = :name', [':name' => 'Valid subscription']),
        'the subscription is stored');

    $db->close();
});

wallos_test('an id belonging to another account is rejected', function () {
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    $foreign = [
        'currency_id' => $ids['bob']['currency'],
        'category_id' => $ids['bob']['category'],
        'payer_user_id' => $ids['bob']['household'],
        'payment_method_id' => $ids['bob']['payment_method'],
    ];

    foreach ($foreign as $field => $bobsId) {
        $input = subscription_references_input($ids['alice']);
        $input[$field] = (string) $bobsId;

        $result = wallos_validate_subscription_input($db, 1, $input);

        assert_true(!$result['valid'], $field . " pointing at bob's row is rejected for alice");
        assert_same($field, $result['field'], 'the rejection names ' . $field);
        assert_true($result['message'] !== '', 'the rejection of ' . $field . ' says what is wrong');

        // The row exists, so ownership is what does the work here, not existence.
        assert_true($bobsId > 0, $field . ' names a row that really exists');
    }

    $db->close();
});

wallos_test('a missing required id is rejected, and a missing optional one is null', function () {
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    foreach (['currency_id', 'cycle', 'frequency'] as $field) {
        $input = subscription_references_input($ids['alice']);
        unset($input[$field]);

        $result = wallos_validate_subscription_input($db, 1, $input);

        assert_true(!$result['valid'], 'a missing ' . $field . ' is rejected');
        assert_same($field, $result['field'], 'the rejection names the missing ' . $field);
    }

    // An empty string is what a form sends for "nothing selected" and what the
    // PostgreSQL statement layer used to turn into the literal 0 — an id no
    // parent table has, and one SQLite stored without complaint.
    foreach (['currency_id', 'cycle', 'frequency'] as $field) {
        $input = subscription_references_input($ids['alice']);
        $input[$field] = '';

        $result = wallos_validate_subscription_input($db, 1, $input);

        assert_true(!$result['valid'], 'an empty ' . $field . ' is rejected rather than becoming 0');
        assert_same($field, $result['field'], 'the rejection names the empty ' . $field);
    }

    // The other three are legitimately empty: an account can have no categories,
    // no payment methods and no household members yet.
    foreach (['category_id', 'payer_user_id', 'payment_method_id'] as $field) {
        $input = subscription_references_input($ids['alice']);
        $input[$field] = '';

        $result = wallos_validate_subscription_input($db, 1, $input);

        assert_true($result['valid'], 'an empty ' . $field . ' is accepted');
        assert_same(null, $result['values'][$field], 'an empty ' . $field . ' is stored as NULL');
    }

    $db->close();
});

wallos_test('a non-numeric id is rejected instead of becoming zero', function () {
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    $fields = ['currency_id', 'category_id', 'payer_user_id', 'payment_method_id', 'cycle', 'frequency'];

    foreach ($fields as $field) {
        foreach (['abc', '3 monkeys', '1.5'] as $garbage) {
            $input = subscription_references_input($ids['alice']);
            $input[$field] = $garbage;

            $result = wallos_validate_subscription_input($db, 1, $input);

            assert_true(!$result['valid'], $field . ' = "' . $garbage . '" is rejected');
            assert_same($field, $result['field'], 'the rejection names ' . $field . ' for "' . $garbage . '"');
        }
    }

    // intval() would have read this as 1, which is a real id in every one of
    // those tables and belongs to somebody.
    $input = subscription_references_input($ids['alice']);
    $input['category_id'] = '1 or 1=1';
    assert_true(!wallos_validate_subscription_input($db, 1, $input)['valid'],
        'a numeric prefix does not smuggle an id through');

    $db->close();
});

wallos_test('a frequency outside the range the form offers is rejected', function () {
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    $maximum = wallos_subscription_frequency_max();

    foreach (['0', '-1', (string) ($maximum + 1), '10000'] as $outOfRange) {
        $input = subscription_references_input($ids['alice']);
        $input['frequency'] = $outOfRange;

        $result = wallos_validate_subscription_input($db, 1, $input);

        assert_true(!$result['valid'], 'frequency ' . $outOfRange . ' is rejected');
        assert_same('frequency', $result['field'], 'the rejection names the frequency for ' . $outOfRange);
    }

    foreach (['1', (string) $maximum] as $inRange) {
        $input = subscription_references_input($ids['alice']);
        $input['frequency'] = $inRange;

        assert_true(wallos_validate_subscription_input($db, 1, $input)['valid'],
            'frequency ' . $inRange . ' is accepted');
    }

    $db->close();
});

wallos_test('the frequency bound is the range the form actually offers', function () {
    // The bound is a range rather than a foreign key on purpose: the frequencies
    // table holds 1..31, nothing reads it, and the PostgreSQL baseline drops the
    // key. What decides the bound is what a user can pick, so if the form or
    // getdbkeys.php changes, this fails rather than the application silently
    // rejecting a value the form offers.
    $maximum = wallos_subscription_frequency_max();

    $form = file_get_contents(WALLOS_ROOT . '/subscriptions.php');
    assert_contains('for ($i = 1; $i <= ' . $maximum . '; $i++)', $form,
        'the frequency select offers exactly 1..' . $maximum);

    $keys = file_get_contents(WALLOS_ROOT . '/includes/getdbkeys.php');
    assert_contains('for ($i = 1; $i <= ' . $maximum . '; $i++)', $keys,
        'getdbkeys.php builds exactly 1..' . $maximum);

    $english = file_get_contents(WALLOS_ROOT . '/includes/i18n/en.php');
    preg_match('/"invalid_frequency" => "([^"]+)"/', $english, $match);
    assert_true(isset($match[1]), 'the frequency rejection has an English message');
    assert_contains((string) $maximum, isset($match[1]) ? $match[1] : '',
        'the message tells the user the real bound');
});

wallos_test('every cycle the form offers is accepted', function () {
    // The REST endpoint used to allow 1..4 from a list written in code, which
    // rejected the "One-time" cycle that migration 000046 added and that the
    // form has offered ever since. The cycles table decides now.
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    $cycles = [];
    $result = $db->query('SELECT id FROM cycles ORDER BY id');
    while ($row = $result->fetchArray()) {
        $cycles[] = (int) $row['id'];
    }

    assert_true(in_array(5, $cycles, true), 'the one-time cycle exists in the schema');

    foreach ($cycles as $cycle) {
        $input = subscription_references_input($ids['alice']);
        $input['cycle'] = (string) $cycle;

        assert_true(wallos_validate_subscription_input($db, 1, $input)['valid'],
            'cycle ' . $cycle . ' is accepted');
    }

    $input = subscription_references_input($ids['alice']);
    $input['cycle'] = (string) (max($cycles) + 1);
    $rejected = wallos_validate_subscription_input($db, 1, $input);

    assert_true(!$rejected['valid'], 'a cycle no row has is rejected');
    assert_same('cycle', $rejected['field'], 'the rejection names the cycle');

    $db->close();
});

wallos_test('a currency is owned by exactly one account', function () {
    // The primitive behind the main_currency check in save_user.php, which had
    // nothing but "the field is not empty" in front of a foreign key.
    $db = wallos_test_open_database();
    $ids = subscription_references_fixture($db);

    assert_true(wallos_reference_is_owned($db, 'currencies', $ids['alice']['currency'], 1),
        'alice owns her own currency');
    assert_true(!wallos_reference_is_owned($db, 'currencies', $ids['bob']['currency'], 1),
        "alice does not own bob's currency");
    assert_true(!wallos_reference_is_owned($db, 'currencies', 999999, 1),
        'an id no row has is not owned');

    // Payment methods are the one table whose rows can belong to nobody: older
    // installations carry system rows with user_id 0, and the REST endpoint has
    // always accepted those.
    $statement = $db->prepare('INSERT INTO payment_methods (id, name, icon, enabled, "order", user_id)
                               VALUES (4242, :name, :icon, 1, 1, 0)');
    $statement->bindValue(':name', 'System card');
    $statement->bindValue(':icon', '');
    $statement->execute();

    assert_true(wallos_reference_is_owned($db, 'payment_methods', 4242, 1, true),
        "a system payment method counts as the caller's");
    assert_true(!wallos_reference_is_owned($db, 'payment_methods', 4242, 1),
        "nothing else treats an unowned row as the caller's");

    $db->close();
});

wallos_test('both subscription write paths validate through the shared helper', function () {
    // A shared definition of "valid" only holds while both paths ask for it.
    $form = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php');
    $api = file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php');

    assert_contains('wallos_validate_subscription_input', $form,
        'the form endpoint validates its references');
    assert_same(2, substr_count($api, 'wallos_validate_subscription_input($db, $userId'),
        'the REST endpoint validates on both add and edit');

    // The form endpoint must take these six from the validated result and never
    // from the request again.
    preg_match_all('/\$_POST\s*\[\s*[\'"](currency_id|category_id|payment_method_id|payer_user_id|cycle|frequency)[\'"]\s*\]/',
        $form, $matches);
    assert_same([], $matches[0],
        'the form endpoint no longer reads the six validated values from $_POST directly');

    assert_not_contains('in_array($cycle, [1, 2, 3, 4]', $api,
        'the REST endpoint no longer keeps its own copy of the cycles table');
});

wallos_test('the other unchecked foreign keys are checked at their write sites', function () {
    $saveUser = file_get_contents(WALLOS_ROOT . '/endpoints/user/save_user.php');
    assert_contains("wallos_reference_is_owned(\$db, 'currencies'", $saveUser,
        'save_user.php checks that main_currency belongs to the caller');
    preg_match('/\$main_currency\s*=\s*\$_POST/', $saveUser, $rawRead);
    assert_same([], $rawRead,
        'save_user.php no longer takes main_currency straight from $_POST');

    // Both of these read an id off a lookup that can find nothing, which puts
    // NULL into a NOT NULL column with a foreign key on it.
    foreach (['registration.php', 'endpoints/admin/adduser.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        assert_not_contains("bindValue(':main_currency', \$currency['id']", $source,
            $path . ' no longer binds the id of a lookup it did not check');
    }

    // payer_user_id references household(id), not user(id).
    $seed = file_get_contents(WALLOS_ROOT . '/dev/seed.php');
    assert_not_contains("bindValue(':payer', \$userId", $seed,
        'the seed script no longer binds a user id into payer_user_id');
    assert_contains("bindValue(':payer', \$payerId", $seed,
        'the seed script binds a household member id into payer_user_id');
});

/**
 * A subscription belonging to $userId that points at the four reference rows
 * given, so a case can then ask whether deleting one of them is refused.
 *
 * Written straight into the table rather than through
 * wallos_validate_subscription_input(), because one of the cases needs a
 * cross-account reference — a row validation would refuse today, and which
 * every installation predating issue #82 can still be carrying.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param array          $references
 */
function subscription_references_insert($db, $userId, array $references)
{
    $stmt = $db->prepare('INSERT INTO subscriptions
                          (name, price, currency_id, next_payment, cycle, frequency,
                           payer_user_id, category_id, payment_method_id, notify, inactive,
                           auto_renew, user_id)
                          VALUES (:name, 1.0, :currency, :next, 3, 1, :payer, :category,
                                  :method, 0, 0, 0, :user)');
    $stmt->bindValue(':name', 'reference fixture');
    $stmt->bindValue(':currency', (int) $references['currency']);
    $stmt->bindValue(':next', '2099-01-01');
    $stmt->bindValue(':payer', (int) $references['household']);
    $stmt->bindValue(':category', (int) $references['category']);
    $stmt->bindValue(':method', (int) $references['payment_method']);
    $stmt->bindValue(':user', (int) $userId);
    $stmt->execute();
}

wallos_test('a referenced row is counted before it can be deleted', function () {
    $db = wallos_test_open_database();
    $fixture = subscription_references_fixture($db);
    subscription_references_insert($db, 1, $fixture['alice']);

    // All four, because the endpoint that forgot its count (issue #93) was the
    // payment method, and a case covering only that one would not notice the
    // next table added to the write side without the delete side.
    assert_same(1, wallos_subscriptions_referencing($db, 'payment_methods', $fixture['alice']['payment_method'], 1),
        'the payment method the subscription uses is in use');
    assert_same(1, wallos_subscriptions_referencing($db, 'categories', $fixture['alice']['category'], 1),
        'so is its category');
    assert_same(1, wallos_subscriptions_referencing($db, 'currencies', $fixture['alice']['currency'], 1),
        'so is its currency');
    assert_same(1, wallos_subscriptions_referencing($db, 'household', $fixture['alice']['household'], 1),
        'so is its household member');

    // The negative control: without it, a function returning a positive number
    // for everything would pass the four assertions above.
    assert_same(0, wallos_subscriptions_referencing($db, 'payment_methods', $fixture['bob']['payment_method'], 2),
        "a row nothing points at is not in use");

    $db->close();
});

wallos_test('a reference from another account counts too', function () {
    $db = wallos_test_open_database();
    $fixture = subscription_references_fixture($db);

    // Bob's subscription pointing at Alice's payment method. Issue #82 closed
    // the way this gets created; what it left behind is installations where the
    // row already exists. Counting only the owner's own subscriptions — which
    // is what all four endpoints did — reports the method as unused, deletes
    // it, and leaves Bob's subscription pointing at nothing. On PostgreSQL that
    // is what dev/migrate-to-pgsql.php later refuses to migrate.
    $borrowed = $fixture['bob'];
    $borrowed['payment_method'] = $fixture['alice']['payment_method'];
    subscription_references_insert($db, 2, $borrowed);

    assert_same(1, wallos_subscriptions_referencing($db, 'payment_methods', $fixture['alice']['payment_method'], 1),
        "another account's subscription keeps the row in use");
    assert_same(0, wallos_subscriptions_referencing($db, 'categories', $fixture['alice']['category'], 1),
        'and a row that really is unreferenced still reads as unused');

    $db->close();
});

wallos_test('every delete path asks the shared count', function () {
    // The gate that makes the sweep hold: it finds the delete paths by looking
    // for the statement, so a ninth one added later is covered without this
    // list being updated. Issue #93 was one file out of eight carrying no check
    // at all, and nothing in the codebase could have told anyone that.
    $tables = ['categories', 'currencies', 'household', 'payment_methods'];
    $found = [];

    foreach (['endpoints', 'api'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(WALLOS_ROOT . '/' . $directory));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $path = str_replace(WALLOS_ROOT . '/', '', $file->getPathname());

            foreach ($tables as $table) {
                if (preg_match('/DELETE\s+FROM\s+' . $table . '\s+WHERE/i', $source) !== 1) {
                    continue;
                }

                $found[] = $path;
                assert_contains("wallos_subscriptions_referencing(\$db, '" . $table . "'", $source,
                    $path . ' counts what references the ' . $table . ' row before deleting it');
            }
        }
    }

    // Four tables, two paths each. A number rather than a list, because the
    // point is that none of them went missing — a gate that finds nothing
    // passes every assertion above it.
    assert_same(8, count($found), 'all eight delete paths were found and checked');

    // And none of them kept a private copy that could drift from the shared one.
    foreach (array_unique($found) as $path) {
        assert_not_contains('COUNT(*) FROM subscriptions WHERE', file_get_contents(WALLOS_ROOT . '/' . $path),
            $path . ' no longer carries its own count');
    }
});

wallos_test('the payment method endpoint refuses and reports honestly', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/payments/delete.php');

    assert_contains('payment_method_in_use', $source,
        'it names the reason the way its three siblings do');

    // The other half of issue #93: it answered success whatever happened. A
    // DELETE matching no row means the id belongs to somebody else or to the
    // system rows older installations carry with user_id 0, and reporting that
    // as "removed" leaves the method on the list after a reload.
    assert_contains('$db->changes() === 0', $source,
        'a delete that matched nothing is not reported as a deletion');
    assert_contains('$deleteStmt->execute() === false', $source,
        'and a failed delete is not reported as one either');

    require WALLOS_ROOT . '/includes/i18n/en.php';
    assert_true(array_key_exists('payment_method_in_use', $i18n),
        'the message it sends exists in the default language');
});
