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
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, :price, :currency, :next, 3, 1, :payer, :category, :payment, 0, 0, :userId, 1)');

    // Real ids from the fixture rather than a hardcoded 1. PostgreSQL enforces
    // the foreign keys SQLite never has, and payer_user_id references
    // household(id) — not user(id), whatever its name suggests.
    $references = wallos_test_user_references($db, $userId);

    for ($i = 0; $i < $count; $i++) {
        $stmt->bindValue(':name', 'Subscription ' . $i, SQLITE3_TEXT);
        $stmt->bindValue(':price', 9.99, SQLITE3_FLOAT);
        // Alternate between the user's two currencies so conversion is required.
        $stmt->bindValue(':currency', wallos_test_currency_id($userId, $i % 2), SQLITE3_INTEGER);
        $stmt->bindValue(':next', date('Y-m-d', strtotime('+' . ($i % 28) . ' days')), SQLITE3_TEXT);
        $stmt->bindValue(':payer', $references['household'], SQLITE3_INTEGER);
        $stmt->bindValue(':category', $references['category'], SQLITE3_INTEGER);
        $stmt->bindValue(':payment', $references['payment_method'], SQLITE3_INTEGER);
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

wallos_test('notification settings load in one query per provider', function () {
    require_once WALLOS_ROOT . '/includes/notification_settings.php';

    $db = wallos_test_open_counting_database();
    for ($userId = 1; $userId <= 20; $userId++) {
        wallos_test_create_user($db, $userId, 'user' . $userId);

        $stmt = $db->prepare('INSERT INTO telegram_notifications (enabled, bot_token, chat_id, user_id) VALUES (1, :token, :chat, :userId)');
        $stmt->bindValue(':token', 'token-' . $userId, SQLITE3_TEXT);
        $stmt->bindValue(':chat', 'chat-' . $userId, SQLITE3_TEXT);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $db->resetQueryCount();
    $settings = wallos_load_notification_settings($db);

    assert_same(count(WALLOS_NOTIFICATION_TABLES), $db->queryCount,
        'one query per provider table regardless of user count (got ' . $db->queryCount . ')');
    assert_same('token-7', $settings['telegram'][7]['bot_token'], 'rows are indexed by user id');
    assert_same(20, count($settings['telegram']), 'every user is loaded');

    $db->close();
});

wallos_test('users without any notification are identified up front', function () {
    require_once WALLOS_ROOT . '/includes/notification_settings.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'enabled-telegram');
    wallos_test_create_user($db, 2, 'enabled-email');
    wallos_test_create_user($db, 3, 'nothing');
    wallos_test_create_user($db, 4, 'disabled-telegram');

    $stmt = $db->prepare("INSERT INTO telegram_notifications (enabled, bot_token, chat_id, user_id) VALUES (1, 't', 'c', 1)");
    $stmt->execute();
    $stmt = $db->prepare("INSERT INTO telegram_notifications (enabled, bot_token, chat_id, user_id) VALUES (0, 't', 'c', 4)");
    $stmt->execute();
    $stmt = $db->prepare("INSERT INTO email_notifications (enabled, smtp_mode, user_id) VALUES (1, 'instance', 2)");
    $stmt->execute();

    $users = wallos_users_with_notifications(wallos_load_notification_settings($db), $db);

    assert_true(isset($users[1]), 'a user with telegram enabled is included');
    assert_true(isset($users[2]), 'a user with email enabled is included');
    assert_true(!isset($users[3]), 'a user with nothing configured is skipped');
    assert_true(!isset($users[4]), 'a user with a disabled provider is skipped');

    $db->close();
});

wallos_test('the notification cron no longer queries per user per provider', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');
    $perProvider = preg_match_all('/SELECT \* FROM \w+_notifications WHERE user_id/', $source);
    assert_same(0, $perProvider, 'settings are loaded in bulk');

    $perMember = preg_match_all('/FROM household WHERE id = :userId/', $source);
    assert_same(0, $perMember, 'household members come from the already loaded map');

    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendcancellationnotifications.php');
    assert_same(0, preg_match_all('/SELECT \* FROM \w+_notifications WHERE user_id/', $source),
        'the cancellation cron loads in bulk too');
});

wallos_test('the notification cron asks the same number of questions for one account as for many', function () {
    // The assertion issue #18 asks for, on the job issue #99 measured. Counting
    // queries rather than milliseconds because the two disagree by a factor of
    // four between deployments and agree exactly on this number: the same code
    // took 2.5 ms per account over loopback and 10 ms over an overlay network,
    // and both were six round trips per account.
    //
    // Six questions per account inside the loop is invisible on SQLite — the
    // engine is in the same process — and is the whole cost on PostgreSQL.
    $db = wallos_test_open_counting_database();

    foreach ([1, 2, 3, 4, 5] as $userId) {
        wallos_test_create_user($db, $userId, 'counted' . $userId);

        $enable = $db->prepare('INSERT INTO email_notifications (enabled, smtp_mode, user_id)
                                VALUES (1, :mode, :user)');
        $enable->bindValue(':mode', 'instance');
        $enable->bindValue(':user', $userId);
        $enable->execute();
    }

    require_once WALLOS_ROOT . '/includes/notification_settings.php';

    $settings = wallos_load_notification_settings($db);
    $users = array_keys(wallos_users_with_notifications($settings, $db));
    assert_same(5, count($users), 'all five accounts are due to be processed');

    $before = $db->queryCount;

    // What the cron does before its loop: the six per-account questions, asked
    // once for everybody.
    wallos_load_rows_by_user($db, 'currencies', $users);
    wallos_load_rows_by_user($db, 'household', $users);
    wallos_load_rows_by_user($db, 'categories', $users);
    wallos_load_rows_by_user($db, 'subscriptions', $users);
    wallos_load_rows_by_user($db, 'user', $users, '*', 'id');

    $forFive = $db->queryCount - $before;
    $before = $db->queryCount;

    wallos_load_rows_by_user($db, 'currencies', [1]);
    wallos_load_rows_by_user($db, 'household', [1]);
    wallos_load_rows_by_user($db, 'categories', [1]);
    wallos_load_rows_by_user($db, 'subscriptions', [1]);
    wallos_load_rows_by_user($db, 'user', [1], '*', 'id');

    $forOne = $db->queryCount - $before;

    assert_same($forOne, $forFive,
        'five accounts cost the same number of queries as one (' . $forFive . ' against ' . $forOne . ')');
    assert_same(5, $forFive, 'and that number is one per question, not one per account');

    // The rows are still there — a loader that returned nothing would also
    // keep the query count flat.
    $currencies = wallos_load_rows_by_user($db, 'currencies', $users);
    assert_same(5, count($currencies), 'every account got its own rows back');

    $db->close();
});

wallos_test('the cron reads the per-account rows from memory, not from the database', function () {
    // The gate on the loop itself. The loader above can be perfect and the loop
    // can still ask its own questions; what matters is that the statements are
    // gone from inside it.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');

    foreach ([
        'SELECT \* FROM currencies WHERE user_id',
        'SELECT \* FROM household WHERE user_id',
        'SELECT \* FROM categories WHERE user_id',
        'FROM subscriptions WHERE user_id = :userId',
    ] as $pattern) {
        assert_same(0, preg_match_all('/' . $pattern . '/', $source),
            'no per-account query for: ' . str_replace('\\', '', $pattern));
    }

    assert_contains('wallos_load_rows_by_user', $source, 'the rows are loaded in bulk instead');
});

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
