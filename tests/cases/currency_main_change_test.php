<?php
/*
  Changing the main currency: the rates have to move with the base, and the
  page must not claim success when they did not (#143, #149).

  Two defects live at the same trigger. #143: the settings save reported "user
  details saved" even when the rate refresh was refused, leaving every total
  wrong by the cross rate with nothing on screen to say so. #149: a currency
  the new base cannot re-price kept its old-base number, which is a different
  wrong unit in the same column.

  No test here makes a request: each child defines wallos_provider_http_get()
  before the client loads, exactly as currency_usage_test.php does.
*/

require_once WALLOS_ROOT . '/includes/currency_provider.php';

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * @param string $body PHP code, without the opening tag.
 * @return array{output: string, status: int}
 */
function mainchange_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/mainchange-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * Adds one currency beyond the two the fixture seeds, with a chosen rate.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $id
 * @param string  $code
 * @param float   $rate
 */
function mainchange_add_currency($db, $userId, $id, $code, $rate)
{
    $stmt = $db->prepare('INSERT INTO currencies (id, name, symbol, code, rate, user_id)
                          VALUES (:id, :name, :symbol, :code, :rate, :userId)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $code, SQLITE3_TEXT);
    $stmt->bindValue(':symbol', $code, SQLITE3_TEXT);
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $stmt->bindValue(':rate', $rate, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $code
 * @return string the raw stored rate
 */
function mainchange_rate($db, $userId, $code)
{
    return (string) $db->scalar('SELECT rate FROM currencies WHERE user_id = :u AND code = :c',
        [':u' => $userId, ':c' => $code]);
}

// The provider prices EUR and USD but not the held code — the ordinary case
// once a base moves to a currency Frankfurter or fixer will not quote against.
$mainchangeStub =
    'function wallos_provider_http_get($url, $context) {' . "\n"
    . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
    . '}' . "\n"
    . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
    . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
    . '$db = wallos_database_connect();' . "\n";

wallos_test('a base change blanks a held rate instead of applying it against the new base (#149)',
function () use ($mainchangeStub) {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    // A held code the provider will not price, carrying a known EUR-relative rate.
    mainchange_add_currency($db, 1, wallos_test_currency_id(1, 2), 'BTC', 2.0);
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    // The account switches its main currency from EUR to USD.
    $stmt = $db->prepare('UPDATE "user" SET main_currency = :c WHERE id = 1');
    $stmt->bindValue(':c', wallos_test_currency_id(1, 1), SQLITE3_INTEGER);
    $stmt->execute();

    $before = mainchange_rate($db, 1, 'BTC');
    assert_same('2', rtrim(rtrim($before, '0'), '.'), 'the held rate starts at its old-base value');

    $run = mainchange_run_php($mainchangeStub
        . '$r = wallos_update_exchange_rates_for_user($db, 1, "EUR");' . "\n"
        . 'echo "success=" . ($r["success"] ? "yes" : "no") . "\n";');

    assert_contains('success=yes', $run['output'], 'the refresh itself succeeded (got: ' . $run['output'] . ')');

    // The priced currency moved onto the new base: EUR is now 1/1.1 relative
    // to USD, proving the refresh ran rather than being skipped.
    $eur = (float) mainchange_rate($db, 1, 'EUR');
    assert_true(abs($eur - (1 / 1.1)) < 0.0001, 'EUR was re-based onto USD (got ' . $eur . ')');

    // The held code is no longer its old EUR-relative number. It is blanked,
    // which the conversion layer reads as no-rate — never divided by 2.0 as if
    // that were a USD-relative figure.
    $btc = mainchange_rate($db, 1, 'BTC');
    assert_true($btc === '' || (float) $btc === 0.0,
        'the held rate is blanked, not applied against the new base (got ' . var_export($btc, true) . ')');

    $db->close();
});

wallos_test('without the base-change signal the held rate is kept — the guard is what invalidates it (#149)',
function () use ($mainchangeStub) {
    // Broken-to-count: the same refresh, called the way the cron and the manual
    // endpoint call it (no previous base), must NOT blank the held rate — "keep
    // the last known rate" is correct when the base did not move. If this ever
    // starts blanking too, the invalidation has stopped being conditional and
    // the ordinary refresh has regressed.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    mainchange_add_currency($db, 1, wallos_test_currency_id(1, 2), 'BTC', 2.0);
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = mainchange_run_php($mainchangeStub
        . '$r = wallos_update_exchange_rates_for_user($db, 1);' . "\n"
        . 'echo "success=" . ($r["success"] ? "yes" : "no") . "\n";');

    assert_contains('success=yes', $run['output'], 'the ordinary refresh succeeded (got: ' . $run['output'] . ')');

    $btc = (float) mainchange_rate($db, 1, 'BTC');
    assert_true(abs($btc - 2.0) < 0.0001,
        'a refresh that did not move the base keeps the held rate (got ' . $btc . ')');

    $db->close();
});

/**
 * Runs endpoints/user/save_user.php as a logged-in POST, with the transport
 * stubbed so no request leaves the machine. Modelled on the harness in
 * oidc_managed_fields_save_test.php.
 *
 * @param int    $userId
 * @param array  $post
 * @param string $providerBody the stubbed provider response body
 * @param string $providerStatus the stubbed HTTP status line
 * @return string merged stdout/stderr
 */
function mainchange_save_invoke($userId, array $post, $providerBody, $providerStatus)
{
    $sessionDir = WALLOS_TEST_TMP . '/mainchange-sess-' . uniqid('', true);
    mkdir($sessionDir, 0700, true);
    $sessionId = 'mainchange' . bin2hex(random_bytes(8));

    $post['csrf_token'] = 'test-csrf-token';

    $lines = [];
    // The stub the save's refresh will call, defined before the endpoint pulls
    // in the currency client, whose function_exists guard lets it stand.
    $lines[] = 'function wallos_provider_http_get($url, $context) {';
    $lines[] = '    return ["body" => ' . var_export($providerBody, true)
        . ', "headers" => [' . var_export($providerStatus, true) . ']];';
    $lines[] = '}';
    $lines[] = 'ini_set(' . var_export('session.save_path', true) . ', ' . var_export($sessionDir, true) . ');';
    $lines[] = 'session_id(' . var_export($sessionId, true) . ');';
    $lines[] = 'session_start();';
    $lines[] = '$_SESSION[' . var_export('loggedin', true) . '] = true;';
    $lines[] = '$_SESSION[' . var_export('userId', true) . '] = ' . (int) $userId . ';';
    $lines[] = '$_SESSION[' . var_export('username', true) . '] = ' . var_export('tester', true) . ';';
    $lines[] = '$_SESSION[' . var_export('csrf_token', true) . '] = ' . var_export('test-csrf-token', true) . ';';
    $lines[] = '$_POST = ' . var_export($post, true) . ';';
    $lines[] = '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('POST', true) . ';';
    $lines[] = '$_SERVER[' . var_export('PHP_SELF', true) . '] = ' . var_export('/endpoints/user/save_user.php', true) . ';';
    $lines[] = 'chdir(' . var_export(WALLOS_ROOT . '/endpoints/user', true) . ');';
    $lines[] = 'require ' . var_export('save_user.php', true) . ';';

    $script = WALLOS_TEST_TMP . '/mainchange-save-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . implode("\n", $lines) . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);

    unlink($script);
    foreach (glob($sessionDir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($sessionDir);

    return implode("\n", $output);
}

/**
 * The POST body save_user.php requires, with a main currency to switch to.
 *
 * @param int $mainCurrencyId
 * @return array
 */
function mainchange_post($mainCurrencyId)
{
    return [
        'firstname' => 'Tester',
        'lastname' => 'Person',
        'email' => 'tester@example.com',
        'avatar' => 'images/avatar.png',
        'main_currency' => (string) $mainCurrencyId,
        'language' => 'en',
    ];
}

wallos_test('a refused refresh on a main-currency change is reported as a failure (#143)', function () {
    $db = wallos_test_open_database();
    $userId = 3;
    wallos_test_create_user($db, $userId, 'switcher');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    // The account is on EUR and switches to USD while the provider refuses.
    $usd = wallos_test_currency_id($userId, 1);
    $out = mainchange_save_invoke($userId, mainchange_post($usd),
        '{"message":"You have exceeded your monthly quota"}', 'HTTP/1.1 429 Too Many Requests');

    $response = json_decode($out, true);
    assert_true(is_array($response), 'the endpoint returned JSON, not a warning-polluted body (got: ' . $out . ')');
    assert_same(false, $response['success'] ?? null,
        'the save does not report success when no rate was converted (got: ' . $out . ')');

    // The main currency itself was still saved — the defect is the false
    // success over unconverted rates, not that the row must not change.
    assert_same((string) $usd, (string) $db->scalar('SELECT main_currency FROM "user" WHERE id = :id', [':id' => $userId]),
        'the user row saved; only the rate outcome failed');

    $db->close();
});

wallos_test('the same change reports success when the refresh works — the failure is real, not blanket (#143)', function () {
    // The negative control for the case above: with a provider that answers,
    // the identical change reports success. Without it, "reports failure" could
    // be a save that always fails.
    $db = wallos_test_open_database();
    $userId = 4;
    wallos_test_create_user($db, $userId, 'worker');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $usd = wallos_test_currency_id($userId, 1);
    $out = mainchange_save_invoke($userId, mainchange_post($usd),
        '{"rates":{"EUR":1,"USD":1.1}}', 'HTTP/1.1 200 OK');

    $response = json_decode($out, true);
    assert_true(is_array($response), 'the endpoint returned JSON (got: ' . $out . ')');
    assert_same(true, $response['success'] ?? null,
        'a change whose rates converted reports success (got: ' . $out . ')');

    $db->close();
});
