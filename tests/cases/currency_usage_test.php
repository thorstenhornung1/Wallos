<?php
/*
  Wallos's own count of the provider requests it makes.

  The provider's usage figure exists only when apilayer sends its rate-limit
  headers; fixer.io reports nothing, which is how a QA round spent six months
  of a 100-call tier while the usage area stayed empty and read as
  reassurance (#104). Every request passes wallos_provider_http_get(), so the
  installation counts what it sends — per calendar month, recorded with the
  key's holder — for both providers (#106).

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
function usage_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/usage-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * Stores a custom provider key for one user, as the settings page would.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $provider
 */
function usage_store_key($db, $userId, $provider = 0)
{
    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                          VALUES (:key, :provider, 'custom', :userId)");
    $stmt->bindValue(':key', 'key-' . $userId, SQLITE3_TEXT);
    $stmt->bindValue(':provider', $provider, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    wallos_reset_config_cache($db);
}

wallos_test('the count is monthly, and the month turning resets it', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    usage_store_key($db, 1);
    $config = wallos_get_effective_currency_config($db, 1);

    assert_same(0, wallos_currency_local_calls($db, $config, 1), 'nothing counted yet');

    wallos_count_currency_call($db, $config, 1);
    wallos_count_currency_call($db, $config, 1);
    assert_same(2, wallos_currency_local_calls($db, $config, 1), 'two requests counted');

    // The month turns.
    $stmt = $db->prepare("UPDATE fixer SET local_calls_month = '2020-01' WHERE user_id = 1");
    $stmt->execute();
    assert_same(0, wallos_currency_local_calls($db, $config, 1), "last month's figure does not carry over");

    wallos_count_currency_call($db, $config, 1);
    assert_same(1, wallos_currency_local_calls($db, $config, 1), 'the new month starts at one');

    $db->close();
});

wallos_test('the shared key counts with the instance, not with a user', function () {
    // Same rule as the provider-reported quota: a shared credential has
    // shared consumption, and presenting it as one user's own would hide
    // what the others spend.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);
    wallos_reset_config_cache($db);
    $config = wallos_get_effective_currency_config($db, 1);

    wallos_count_currency_call($db, $config, 1);

    $instance = wallos_get_instance_settings($db, 'currency');
    assert_same(1, (int) ($instance['local_calls'] ?? 0), 'counted with the instance');
    assert_same(date('Y-m'), (string) ($instance['local_calls_month'] ?? ''), 'in the current month');
    assert_same(1, wallos_currency_local_calls($db, $config, 1), 'and read back the same way');

    $db->close();
});

wallos_test('a run over accounts sharing the key counts one call, not one per account', function () {
    // The per-run cache answers the second account without a request, and the
    // counter has to agree with what the provider saw — a counter that billed
    // one call per account would raise the same false alarm #104 was.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = usage_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . '$first = wallos_update_exchange_rates_for_user($db, 1);' . "\n"
        . '$second = wallos_update_exchange_rates_for_user($db, 2);' . "\n"
        . 'echo "ok=" . (($first["success"] && $second["success"]) ? "yes" : "no") . "\n";'
    );
    assert_contains('ok=yes', $run['output'], 'both accounts refreshed (got: ' . $run['output'] . ')');

    $instance = wallos_get_instance_settings($db, 'currency');
    assert_same(1, (int) ($instance['local_calls'] ?? 0), 'one request went out, one request is counted');

    $db->close();
});

wallos_test('a refused call still counts — it went to the provider', function () {
    // #104 was hundreds of calls that all failed. A counter that only counted
    // successes would have shown nothing then either.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = usage_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => \'{"message":"You have exceeded your monthly quota"}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 429 Too Many Requests"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . '$update = wallos_update_exchange_rates_for_user($db, 1);' . "\n"
        . 'echo "ok=" . ($update["success"] ? "yes" : "no") . "\n";'
    );
    assert_contains('ok=no', $run['output'], 'the refresh failed as the provider said (got: ' . $run['output'] . ')');

    $instance = wallos_get_instance_settings($db, 'currency');
    assert_same(1, (int) ($instance['local_calls'] ?? 0), 'the refused request is counted');

    $db->close();
});

wallos_test('a fetch answered from the run cache reports no transport', function () {
    // The flag is what call sites count by, so a cached answer must not carry
    // the transport mark of the request it reuses.
    $run = usage_run_php(
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
        . 'echo "first=" . (empty($first["transport"]) ? "n" : "y") . "\n";' . "\n"
        . 'echo "second=" . (empty($second["transport"]) ? "n" : "y") . "\n";'
    );

    assert_contains('calls=1', $run['output'], 'one request over the wire (got: ' . $run['output'] . ')');
    assert_contains('first=y', $run['output'], 'the real request is marked');
    assert_contains('second=n', $run['output'], 'the cached answer is not');
});

wallos_test('the usage endpoint and the page carry the count', function () {
    $endpoint = file_get_contents(WALLOS_ROOT . '/endpoints/settings/fixer_usage.php');
    $page = file_get_contents(WALLOS_ROOT . '/settings.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/settings.js');
    $keySave = file_get_contents(WALLOS_ROOT . '/endpoints/currency/fixer_api_key.php');

    assert_true(strpos($endpoint, 'wallos_currency_local_calls') !== false,
        'the endpoint serves the local count');
    assert_true(strpos($endpoint, 'rates_updated') !== false,
        'and the date of the last successful refresh');
    assert_true(strpos($page, 'fixerUsageUnknown') !== false,
        'the page can say that the provider reports nothing');
    assert_true(strpos($page, 'fixerUsageExhausted') !== false,
        'and that the quota is exhausted');
    assert_true(strpos($script, 'provider_reports') !== false,
        'the renderer distinguishes the providers');
    assert_true(strpos($keySave, 'wallos_count_currency_call') !== false,
        'the key test call is counted too');
});
