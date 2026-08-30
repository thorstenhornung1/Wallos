<?php
/*
  One provider request for everyone a shared credential serves (#9).

  The per-run cache answered repeat requests only when the currency list
  matched exactly, so two instance users with different lists still cost two
  provider calls per scheduled refresh. The complete form the issue asks
  for: group the users behind one credential, fetch the union of their
  symbols once, and derive every user's rates from their own main currency —
  which the per-user code already does, since a response carrying more rates
  than a user owns writes only the rows that exist.

  No test here makes a request: child processes define their own transport
  before loading the client, exactly as currency_quota_test.php does.
*/

require_once WALLOS_ROOT . '/includes/currency_provider.php';

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
function union_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/union-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * Gives one user an extra currency beyond the two the fixture seeds, so two
 * users stop sharing a currency list.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $id
 * @param string  $code
 */
function union_add_currency($db, $userId, $id, $code)
{
    $stmt = $db->prepare('INSERT INTO currencies (id, name, symbol, code, rate, user_id)
                          VALUES (:id, :name, :symbol, :code, 1.0, :userId)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $code, SQLITE3_TEXT);
    $stmt->bindValue(':symbol', $code, SQLITE3_TEXT);
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

wallos_test('an answer carrying a superset of the codes serves from the cache', function () {
    // The cache used to match the code list exactly, so the union response
    // this file exists for would not have answered anyone.
    $run = union_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1,"GBP":0.85}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "shared", "provider" => 1], "notes" => []];' . "\n"
        . '$union = wallos_fetch_exchange_rates($config, "EUR,USD,GBP");' . "\n"
        . '$subset = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "ok=" . (($union["success"] && $subset["success"]) ? "yes" : "no") . "\n";' . "\n"
        . 'echo "transport=" . (empty($subset["transport"]) ? "n" : "y") . "\n";'
    );

    assert_contains('calls=1', $run['output'], 'the subset is answered without a request (got: ' . $run['output'] . ')');
    assert_contains('ok=yes', $run['output'], 'and both callers get rates');
    assert_contains('transport=n', $run['output'], 'the cached answer carries no transport mark');
});

wallos_test('a refused union answers its subsets too', function () {
    // A quota exhausted for the union is exhausted for every part of it —
    // asking again with fewer symbols would spend a call to learn the same
    // refusal.
    $run = union_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"message":"You have exceeded your monthly quota"}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 429 Too Many Requests"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "shared", "provider" => 1], "notes" => []];' . "\n"
        . '$union = wallos_fetch_exchange_rates($config, "EUR,USD,GBP");' . "\n"
        . '$subset = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "same=" . (($subset["message"] !== "" && $union["message"] === $subset["message"]) ? "yes" : "no") . "\n";'
    );

    assert_contains('calls=1', $run['output'], 'one refusal, not one per list (got: ' . $run['output'] . ')');
    assert_contains('same=yes', $run['output'], 'the subset hears the provider\'s own words');
});

wallos_test('the scheduled refresh spends one call on users with different lists', function () {
    // The #9 acceptance line itself. Two users inherit the instance
    // credential; one of them tracks an extra currency. The run used to cost
    // two provider calls, because different lists are different cache keys.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    union_add_currency($db, 2, 999002, 'GBP');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = union_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1,"GBP":0.85}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";'
    );

    assert_contains('calls=1', $run['output'],
        'the union is fetched once for both users (got: ' . $run['output'] . ')');
    assert_same(2, substr_count($run['output'], 'Rates updated successfully'),
        'and both users were refreshed from it');

    // Derived per user from their own main currency: bob's extra currency
    // got its rate from the shared response.
    $rate = (float) $db->scalar('SELECT rate FROM currencies WHERE user_id = 2 AND code = :code',
        [':code' => 'GBP']);
    assert_true(abs($rate - 0.85) < 0.0001, 'bob\'s GBP rate came out of the one fetch (got ' . $rate . ')');

    // The local counter agrees with what the provider saw (#106).
    $instance = wallos_get_instance_settings($db, 'currency');
    assert_same(1, (int) ($instance['local_calls'] ?? 0), 'one call went out, one is counted');

    $db->close();
});

wallos_test('a fresh user neither fetches nor widens the union', function () {
    // The #117 rule carried into the union: a user refreshed today is not
    // merely skipped, their currencies must not inflate the shared request
    // either — symbols nobody due needs are quota spent on nothing.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'fresh');
    wallos_test_create_user($db, 2, 'due');
    union_add_currency($db, 1, 999001, 'XXX');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, 1)');
    $stmt->bindValue(':date', (new DateTime())->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();

    $run = union_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . '$GLOBALS["seen_urls"] = [];' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    $GLOBALS["seen_urls"][] = $url;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "xxx=" . (strpos(implode(" ", $GLOBALS["seen_urls"]), "XXX") === false ? "absent" : "requested") . "\n";'
    );

    assert_contains('calls=1', $run['output'], 'one request for the one due user (got: ' . $run['output'] . ')');
    assert_contains('xxx=absent', $run['output'], 'the fresh user\'s currency stayed out of it');

    $db->close();
});
