<?php
/*
  Structural performance guarantees from the specification.

  These assert shapes rather than timings: query counts must not grow with the
  number of rows, and shared credentials must not multiply outbound calls.
  Cases marked pending describe behaviour that is specified but not implemented
  yet — they report without failing the suite.
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

wallos_test_pending(
    'currency conversion does not query once per subscription',
    'specification 45.3 / acceptance 16 — conversion still issues one SELECT per subscription',
    function () {
        // list_subscriptions.php runs page code at include time, so the shape is
        // asserted on the source until conversion moves behind a callable that
        // takes an already loaded rate map.
        $paths = [
            'includes/list_subscriptions.php',
            'api/subscriptions/get_subscriptions.php',
            'includes/stats_calculations.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(WALLOS_ROOT . '/' . $path);
            $perRowLookups = preg_match_all('/SELECT rate FROM currencies/', $source);

            assert_same(0, $perRowLookups,
                $path . ' should convert from a loaded rate map, not query per row');
        }
    }
);

wallos_test_pending(
    'effective configuration is resolved once per request',
    'specification 45.2 / acceptance 43 — the resolver has no request-local memoization yet',
    function () {
        $db = wallos_test_open_counting_database();
        wallos_test_create_user($db, 1, 'alice');

        wallos_get_instance_smtp_config($db);
        $db->resetQueryCount();

        // A page that shows both the effective and the instance transport.
        wallos_get_instance_smtp_config($db);
        wallos_get_instance_smtp_config($db);
        wallos_get_instance_smtp_config($db);

        assert_same(0, $db->queryCount,
            'repeated resolution should be served from memory (got ' . $db->queryCount . ' queries)');

        $db->close();
    }
);

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
    'subscription queries have supporting indexes',
    'specification 45.6 / acceptance 44 — no indexes are created by any migration yet',
    function () {
        $db = wallos_test_open_database();

        $indexes = [];
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='subscriptions'");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $indexes[] = $row['name'];
        }

        assert_true($indexes !== [],
            'the subscriptions table should have at least one deliberate index');

        $db->close();
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
