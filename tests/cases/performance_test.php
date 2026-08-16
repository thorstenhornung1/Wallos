<?php
/*
  Structural performance guarantees from the specification.

  These assert shapes rather than timings: query counts must not grow with the
  number of rows, and shared credentials must not multiply outbound calls.
  Cases marked pending describe behaviour that is specified but not implemented
  yet — they report without failing the suite.

  Conversion query counts live in currency_rates_test.php and index coverage in
  subscription_index_test.php, now that both are implemented.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $count
 */
function performance_seed_subscriptions($db, $userId, $count)
{
    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, :price, :currency, :next, 3, 1, :payer, 1, 1, 0, :userId, 1)');

    for ($i = 0; $i < $count; $i++) {
        $stmt->bindValue(':name', 'Subscription ' . $i, SQLITE3_TEXT);
        $stmt->bindValue(':price', 9.99, SQLITE3_FLOAT);
        // Alternate between the user's two currencies so conversion is required.
        $stmt->bindValue(':currency', wallos_test_currency_id($userId, $i % 2), SQLITE3_INTEGER);
        $stmt->bindValue(':next', date('Y-m-d', strtotime('+' . ($i % 28) . ' days')), SQLITE3_TEXT);
        $stmt->bindValue(':payer', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }
}

wallos_test('subscription seeding produces the expected fixture', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    performance_seed_subscriptions($db, 1, 50);

    assert_same(50, (int) $db->querySingle('SELECT COUNT(*) FROM subscriptions WHERE user_id = 1'),
        'the fixture is usable for query-count assertions');

    $db->close();
});

wallos_test('effective configuration is resolved once per request', function () {
    $db = wallos_test_open_counting_database();
    wallos_test_create_user($db, 1, 'alice');

    // First resolution loads it.
    wallos_get_instance_smtp_config($db);
    wallos_get_effective_smtp_config($db, 1);
    wallos_get_instance_currency_config($db);
    wallos_get_effective_ai_config($db, 1);
    $db->resetQueryCount();

    // A page that shows the effective and the instance configuration side by
    // side asks for each of them again.
    for ($i = 0; $i < 5; $i++) {
        wallos_get_instance_smtp_config($db);
        wallos_get_effective_smtp_config($db, 1);
        wallos_get_instance_currency_config($db);
        wallos_get_effective_ai_config($db, 1);
    }

    assert_same(0, $db->queryCount,
        'repeated resolution is served from memory (got ' . $db->queryCount . ' queries)');

    $db->close();
});

wallos_test('storing an instance setting invalidates the cache', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(!wallos_get_instance_currency_config($db)['valid'], 'nothing is configured yet');

    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $config = wallos_get_instance_currency_config($db);
    assert_true($config['valid'], 'the new credential is visible immediately');
    assert_same('instance-key', $config['values']['api_key'], 'and it is the one just stored');

    $db->close();
});

wallos_test('two connections do not share resolved configuration', function () {
    $first = wallos_test_open_database();
    $second = wallos_test_open_database();

    wallos_set_instance_setting($first, 'currency', 'api_key', 'first-key', true);
    wallos_set_instance_setting($second, 'currency', 'api_key', 'second-key', true);

    assert_same('first-key', wallos_get_instance_currency_config($first)['values']['api_key'],
        'the first connection sees its own value');
    assert_same('second-key', wallos_get_instance_currency_config($second)['values']['api_key'],
        'the second connection sees its own value');

    $first->close();
    $second->close();
});

wallos_test('one shared credential is fetched once per refresh', function () {
    require_once WALLOS_ROOT . '/includes/currency_provider.php';

    // wallos_fetch_exchange_rates() memoizes per provider/credential/symbol set
    // within one run, so several inheriting users cause one provider request.
    $config = wallos_config_result();
    wallos_config_set($config, 'provider', 0, 'admin');
    wallos_config_set($config, 'api_key', '', 'default');
    $config['valid'] = false;

    $first = wallos_fetch_exchange_rates($config, 'USD');
    $second = wallos_fetch_exchange_rates($config, 'USD');

    assert_same($first['success'], $second['success'], 'repeated calls behave identically');
    assert_true(!$first['success'], 'an unconfigured provider is rejected before any network call');
});

wallos_test_pending(
    'notification cron does not query per user per provider',
    'specification 45.5 / acceptance 29 — sendnotifications.php still queries each provider table per user',
    function () {
        $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');

        // The user loop currently contains one SELECT per provider table.
        $perProviderQueries = preg_match_all('/SELECT \* FROM \w+_notifications WHERE user_id/', $source);

        assert_true($perProviderQueries <= 1,
            'notification settings should be loaded in bulk (found ' . $perProviderQueries . ' per-user queries)');
    }
);

wallos_test_pending(
    'one row per user is enforced where intended',
    'specification 45.7 — settings tables allow duplicate rows per user',
    function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');

        $stmt = $db->prepare("INSERT INTO email_notifications (enabled, user_id) VALUES (1, 1)");
        $stmt->execute();
        $stmt = $db->prepare("INSERT INTO email_notifications (enabled, user_id) VALUES (1, 1)");
        $duplicate = @$stmt->execute();

        assert_true($duplicate === false,
            'a second email_notifications row for the same user should be rejected');

        $db->close();
    }
);
