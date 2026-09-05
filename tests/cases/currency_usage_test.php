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

wallos_test('a cached answer does not re-stamp usage as freshly checked', function () {
    // The bug #106 names in its own title: wallos_store_currency_usage() was
    // called even for an answer served from the per-run cache (transport ===
    // false), re-stamping "last checked" to now for a figure obtained by an
    // earlier request. Two accounts share the instance key; the first update
    // goes over the wire and stamps, the second is answered from the cache and
    // must not. The stamp is pinned to a sentinel between them, so a re-stamp
    // by the cached second update is the difference the assertion reads.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = usage_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 200 OK",' . "\n"
        . '                          "x-ratelimit-limit-month: 100", "x-ratelimit-remaining-month: 40"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . '$first = wallos_update_exchange_rates_for_user($db, 1);' . "\n"
        . 'wallos_set_instance_setting($db, "currency", "usage_updated_at", "2000-01-01 00:00:00");' . "\n"
        . '$second = wallos_update_exchange_rates_for_user($db, 2);' . "\n"
        . '$instance = wallos_get_instance_settings($db, "currency");' . "\n"
        . 'echo "ok=" . (($first["success"] && $second["success"]) ? "yes" : "no") . "\n";' . "\n"
        . 'echo "stamp=" . $instance["usage_updated_at"] . "\n";'
    );

    assert_contains('ok=yes', $run['output'], 'both accounts refreshed (got: ' . $run['output'] . ')');
    assert_contains('stamp=2000-01-01 00:00:00', $run['output'],
        'the cached second update left the earlier stamp untouched (got: ' . $run['output'] . ')');

    $db->close();
});

wallos_test('the daily rate-limit pair is captured and stored beside the monthly one', function () {
    // X-RateLimit-*-Day rides in on the same response as the monthly pair, so
    // capturing it costs no extra request (#106). A daily limit reached is a
    // different situation from a month exhausted, and the store keeps them
    // apart.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = usage_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 200 OK",' . "\n"
        . '                          "x-ratelimit-limit-month: 100", "x-ratelimit-remaining-month: 40",' . "\n"
        . '                          "x-ratelimit-limit-day: 20", "x-ratelimit-remaining-day: 8"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'wallos_update_exchange_rates_for_user($db, 1);' . "\n"
        . '$r = wallos_fetch_exchange_rates(wallos_get_effective_currency_config($db, 1), "EUR,USD");' . "\n"
        . 'echo "day=" . $r["usage"]["used_day"] . "/" . $r["usage"]["limit_day"] . "\n";'
    );

    assert_contains('day=12/20', $run['output'],
        'the daily pair is read from the same headers as the monthly one (got: ' . $run['output'] . ')');

    $instance = wallos_get_instance_settings($db, 'currency');
    assert_same('12', (string) ($instance['usage_used_day'] ?? ''), 'daily used stored with the instance');
    assert_same('20', (string) ($instance['usage_limit_day'] ?? ''), 'daily limit stored with the instance');

    $db->close();
});

wallos_test('the REST key-save records month and day usage and counts the call', function () {
    // The scheduled refresh has captured APILayer's month-and-day headers
    // through the shared client since #106; set_fixer.php used to parse them
    // itself, month-only, over its own request, and never counted the call
    // (#150). Driving the real endpoint proves it now takes the same path: the
    // row it writes carries both pairs and one counted call. Reverting the
    // endpoint to its own month-only parser fails this case — which is the
    // whole point of asserting on the day figure and the count.
    if (wallos_test_skip_unless_sqlite(
        'drives the REST key-save endpoint through the CLI; PostgreSQL is pending for the integrator (#150)')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // set_fixer.php authenticates the Wallos request by api_key, not a session.
    // Bare bindValue keeps this new line off the SQLite boundary audit (#20).
    $stmt = $db->prepare('UPDATE "user" SET api_key = :key WHERE id = 1');
    $stmt->bindValue(':key', 'rest-user-key');
    $stmt->execute();

    // The child stubs the transport, then loads the endpoint exactly as a POST
    // would — its own requires resolve against its directory, so the run starts
    // there.
    $run = usage_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => \'{"success":true,"base":"EUR","rates":{"EUR":1,"USD":1.1}}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 200 OK",' . "\n"
        . '                          "x-ratelimit-limit-month: 100", "x-ratelimit-remaining-month: 40",' . "\n"
        . '                          "x-ratelimit-limit-day: 20", "x-ratelimit-remaining-day: 8"]];' . "\n"
        . '}' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_POST["api_key"] = "rest-user-key";' . "\n"
        . '$_POST["provider"] = "1";' . "\n"
        . '$_POST["fixer_api_key"] = "the-account-key";' . "\n"
        . 'chdir(' . var_export(WALLOS_ROOT . '/api/fixer', true) . ');' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/api/fixer/set_fixer.php', true) . ';'
    );

    assert_contains('"success":true', $run['output'],
        'the key save reported success (got: ' . $run['output'] . ')');

    // Month: 100 - 40. Day: 20 - 8. Both come from the one response, stored
    // against the account's own row because the key is the account's own.
    assert_same('60', (string) $db->scalar('SELECT usage_used FROM fixer WHERE user_id = 1'),
        'the monthly used figure is stored');
    assert_same('100', (string) $db->scalar('SELECT usage_limit FROM fixer WHERE user_id = 1'),
        'the monthly limit is stored');
    assert_same('12', (string) $db->scalar('SELECT usage_used_day FROM fixer WHERE user_id = 1'),
        'the daily used figure the old month-only parser dropped is stored');
    assert_same('20', (string) $db->scalar('SELECT usage_limit_day FROM fixer WHERE user_id = 1'),
        'the daily limit is stored');

    // provider_mode decides whether the saved key is read at all, and the call
    // went over the wire, so the local counter the settings twin keeps must see
    // it too (#106).
    assert_same('custom', (string) $db->scalar('SELECT provider_mode FROM fixer WHERE user_id = 1'),
        "the saved key is the account's own, so config resolution reads it");
    assert_same('1', (string) $db->scalar('SELECT local_calls FROM fixer WHERE user_id = 1'),
        'the verification request is counted');
    assert_same(date('Y-m'), (string) $db->scalar('SELECT local_calls_month FROM fixer WHERE user_id = 1'),
        'and counted in the current month');

    $db->close();
});

wallos_test('the page escalates before exhaustion and shows the stall and daily limit', function () {
    $endpoint = file_get_contents(WALLOS_ROOT . '/endpoints/settings/fixer_usage.php');
    $page = file_get_contents(WALLOS_ROOT . '/settings.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/settings.js');

    // Warning thresholds before the quota is gone, off the figure already
    // fetched (#106, part 1).
    assert_true(strpos($page, 'fixerUsageWarning') !== false, 'the page can warn before exhaustion');
    assert_true(strpos($page, 'fixerUsageHighWarning') !== false, 'and warn harder near it');
    assert_true(strpos($script, 'percent >= 75') !== false, 'running low at 75%');
    assert_true(strpos($script, 'percent >= 90') !== false, 'nearly gone at 90%');

    // A stalled refresh is legible, distinct from healthy (#106, part 2).
    assert_true(strpos($endpoint, 'rates_stale') !== false, 'the endpoint reports a stalled refresh');
    assert_true(strpos($page, 'fixerUsageStalled') !== false, 'and the page shows it');

    // The daily limit is a state apart from the monthly quota (#106, part 4).
    assert_true(strpos($endpoint, 'total_day') !== false, 'the endpoint carries the daily figure');
    assert_true(strpos($page, 'fixerUsageDailyReached') !== false, 'the page can say the daily limit is reached');
});
