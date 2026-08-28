<?php
/*
  What a run of the exchange job costs the provider.

  The job runs on every container start as well as daily, and it used to
  fetch unconditionally, one call per account — so deploy frequency alone
  could exhaust a free tier, and a key the provider had just rejected was
  asked again for every further account in the same run. Both were observed
  on 2026-08-28: the shared instance's quota was already gone, and the deploy
  itself spent two more calls learning that twice (#117).

  No test in this suite makes a request. The provider client's one network
  touch is wallos_provider_http_get(), guarded by function_exists, so the
  child processes below define their own transport before loading the client
  and count what would have gone over the wire.
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
function quota_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/quota-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('freshness is one row, read and compared as a date', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(!wallos_exchange_rates_fresh($db, 1), 'no row means not fresh — the wrong default would refuse to refresh');

    $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, 1)');
    $stmt->bindValue(':date', (new DateTime())->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();
    assert_true(wallos_exchange_rates_fresh($db, 1), 'refreshed today is fresh');

    $stmt = $db->prepare('UPDATE last_exchange_update SET date = :date WHERE user_id = 1');
    $stmt->bindValue(':date', (new DateTime('-1 day'))->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();
    assert_true(!wallos_exchange_rates_fresh($db, 1), 'yesterday is stale');

    $db->close();
});

wallos_test('the exchange job does not fetch for an account refreshed today', function () {
    // The real job, as the real process startup.sh and cron run — a fixture
    // with one fresh account and one stale, unconfigured one. Neither can
    // reach a network: the fresh one is skipped before anything is resolved,
    // and the stale one has no provider to ask.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'fresh');
    wallos_test_create_user($db, 2, 'stale');

    $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, 1)');
    $stmt->bindValue(':date', (new DateTime())->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();

    $run = quota_run_php('require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';');

    // Ordering is part of the contract: fresh is decided before configured.
    assert_contains('Rates are already current today.', $run['output'],
        'the fresh account is skipped without resolving anything');
    assert_contains('No currency provider configured', $run['output'],
        'the stale account falls through to the next answer');
    assert_true(strpos($run['output'], 'Exchange rates update failed') === false,
        'nothing attempted a fetch (got: ' . $run['output'] . ')');
    assert_same(0, $run['status'], 'the job finishes cleanly');

    $db->close();
});

wallos_test('a rejected key is rejected once per run, not once per account', function () {
    $run = quota_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"message":"You have exceeded your monthly quota"}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 429 Too Many Requests"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "shared", "provider" => 1], "notes" => []];' . "\n"
        . '$first = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . '$second = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . '$other = wallos_fetch_exchange_rates($config, "EUR,USD,GBP");' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "same=" . (($first["message"] !== "" && $first["message"] === $second["message"]) ? "yes" : "no") . "\n";'
    );

    // Two accounts sharing key and currency list cost one call; a different
    // list is a different request and rightly costs its own.
    assert_contains('calls=2', $run['output'],
        'three asks, two distinct requests (got: ' . $run['output'] . ')');
    assert_contains('same=yes', $run['output'], 'both accounts get the provider\'s own words');
});

wallos_test('an unreachable provider is unreachable once per run', function () {
    $run = quota_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => false, "headers" => null];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "shared", "provider" => 1], "notes" => []];' . "\n"
        . '$first = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . '$second = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "same=" . (($first["message"] !== "" && $first["message"] === $second["message"]) ? "yes" : "no") . "\n";'
    );

    // Retrying an outage per account multiplies timeouts, not information.
    assert_contains('calls=1', $run['output'], 'one timeout, not one per account');
    assert_contains('same=yes', $run['output'], 'the second account gets the same answer');
});

wallos_test('a successful response is still shared within a run', function () {
    $run = quota_run_php(
        '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "shared", "provider" => 1], "notes" => []];' . "\n"
        . '$first = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . '$second = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "ok=" . (($first["success"] && $second["success"]) ? "yes" : "no") . "\n";'
    );

    assert_contains('calls=1', $run['output'], 'the pre-existing success cache still holds');
    assert_contains('ok=yes', $run['output'], 'and both callers get the rates');
});
