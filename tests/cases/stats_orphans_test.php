<?php
/*
  Statistics over subscriptions whose category, payer or payment method is
  gone or disabled (upstream #1179/#1182; the fix mirrors upstream PR #1183,
  taken into the fork ahead of its merge because the fork is affected
  identically).

  The tally arrays are built from the rows that exist — categories, enabled
  payment methods, household members — and the tally loop then indexed them
  with whatever the subscription says. A NULL payer, a deleted category or a
  disabled payment method indexes a key that was never seeded, and since #85
  every such warning lands on the container's own error stream instead of a
  file nobody reads. The numbers were right; the noise was not.
*/

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * Deliberately local to this file: the runner loads only the case files the
 * filter matches, so helpers from other files may not exist. The script path
 * is generated here and quoted; nothing a request could reach.
 *
 * @param string $body PHP code, without the opening tag.
 * @return array{output: string, status: int}
 */
function stats_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/stats-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('orphaned references do not spray warnings over the stats', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // A payment method that exists but is disabled — the seeding query asks
    // for enabled = 1, so the tally array never carries it (#1179's case).
    $stmt = $db->prepare("INSERT INTO payment_methods (id, name, icon, enabled, user_id)
                          VALUES (4242, 'closed card', 'card.png', 0, 1)");
    assert_true($stmt !== false && $stmt->execute() !== false, 'the disabled method exists');

    // A subscription paying with it, owned by nobody in the household and
    // filed under no category: every tally key this row produces is unseeded.
    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id,
         category_id, payment_method_id, notify, inactive, user_id, auto_renew)
        VALUES (:name, 9.99, :currency, :next, 3, 1, NULL, NULL, 4242, 0, 0, 1, 1)');
    $stmt->bindValue(':name', 'orphan sub', SQLITE3_TEXT);
    $stmt->bindValue(':currency', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
    $stmt->bindValue(':next', date('Y-m-d'), SQLITE3_TEXT);
    assert_true($stmt->execute() !== false, 'the subscription exists');

    $run = stats_run_php(
        'error_reporting(E_ALL);' . "\n"
        . '$warnings = [];' . "\n"
        . 'set_error_handler(function ($no, $message) use (&$warnings) {' . "\n"
        . '    $warnings[] = $message;' . "\n"
        . '    return true;' . "\n"
        . '});' . "\n"
        . 'function translate($key, $i18n) { return $key; }' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . '$userId = 1;' . "\n"
        . '$i18n = [];' . "\n"
        . '$stmt = $db->prepare(\'SELECT * FROM "user" WHERE id = 1\');' . "\n"
        . '$userData = $stmt->execute()->fetchArray(SQLITE3_ASSOC);' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/stats_calculations.php', true) . ';' . "\n"
        . 'echo "active=" . $activeSubscriptions . "\n";' . "\n"
        . 'echo "cost=" . round($totalCostPerMonth, 2) . "\n";' . "\n"
        . 'foreach ($warnings as $w) { echo "WARNING: " . $w . "\n"; }'
    );

    assert_true(strpos($run['output'], 'Undefined array key') === false,
        'no tally site indexes a key that was never seeded (got: ' . $run['output'] . ')');
    assert_contains('active=1', $run['output'], 'the subscription still counts as active');
    assert_contains('cost=9.99', $run['output'], 'and its cost still lands in the total');
});
