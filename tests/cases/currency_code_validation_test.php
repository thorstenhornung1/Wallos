<?php
/*
  A currency code is validated before it is stored (#133).

  Three free-text fields let "LUN" into the currencies table, where it kept the
  seeded rate of 1 and converted every price at 1:1 while the refresh reported
  success — a wrong total no screen could tell from a right one. The valid set
  follows issue #163: a code is acceptable when CLDR (the offline ISO 4217 set)
  knows it, OR the configured provider will price it. That refuses an invented
  code while still allowing a provider-supported asset outside ISO, crypto such
  as BTC — the provider governs availability, and a code's absence from CLDR
  must not block one it prices.

  No test here makes a request: the child defines wallos_provider_http_get()
  before the module loads, and the provider is asked only for a non-ISO code.
*/

require_once WALLOS_ROOT . '/includes/currency_codes.php';

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * @param string $body
 * @return string merged stdout/stderr
 */
function codevalidation_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/codeval-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return implode("\n", $output);
}

wallos_test('an invented code is refused; a real ISO code and a provider crypto code are accepted (#133)', function () {
    $db = wallos_test_open_database();

    // The provider's symbol catalogue lists BTC — a code CLDR does not carry.
    $out = codevalidation_run_php(
        '$GLOBALS["calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["calls"]++;' . "\n"
        . '    return ["body" => \'{"symbols":{"USD":"US Dollar","EUR":"Euro","BTC":"Bitcoin"}}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_codes.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "k", "provider" => 1], "notes" => []];' . "\n"
        . 'echo "usd=" . (wallos_currency_code_acceptable($db, $config, "USD") ? "1" : "0") . " calls=" . $GLOBALS["calls"] . "\n";' . "\n"
        . 'echo "btc=" . (wallos_currency_code_acceptable($db, $config, "BTC") ? "1" : "0") . " calls=" . $GLOBALS["calls"] . "\n";' . "\n"
        . 'echo "lun=" . (wallos_currency_code_acceptable($db, $config, "LUN") ? "1" : "0") . " calls=" . $GLOBALS["calls"] . "\n";' . "\n"
        . 'echo "junk=" . (wallos_currency_code_acceptable($db, $config, "\u{20AC}\u{20AC}\u{20AC}") ? "1" : "0") . "\n";'
    );

    // A real ISO code is accepted by CLDR alone — the provider is never asked,
    // so the common add costs no request (#134's measurement, honoured here).
    assert_contains('usd=1 calls=0', $out, 'a real ISO code is accepted with no provider request (got: ' . $out . ')');
    // A provider-supported non-ISO code reaches the provider and is accepted.
    assert_contains('btc=1 calls=1', $out, 'a provider-supported crypto code is accepted (got: ' . $out . ')');
    // An invented code is neither in CLDR nor priced by the provider: refused,
    // never silently converted at 1:1. The provider was consulted once (cached).
    assert_contains('lun=0 calls=1', $out, 'an invented code is refused (got: ' . $out . ')');
    // Not even structurally a code.
    assert_contains('junk=0', $out, 'a non-code string is refused (got: ' . $out . ')');

    $db->close();
});

wallos_test('with no provider configured, only ISO codes are acceptable (#133)', function () {
    // The offline installation: no credentials, so the provider catalogue is
    // empty and CLDR is the whole of the valid set. A real currency still works;
    // a code only a provider could vouch for cannot be proved and is refused,
    // which fails closed rather than back to the silent-1:1 behaviour.
    $out = codevalidation_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => false, "headers" => null];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_codes.php', true) . ';' . "\n"
        . '$config = ["valid" => false, "values" => [], "notes" => ["not configured"]];' . "\n"
        . 'echo "jpy=" . (wallos_currency_code_acceptable(null, $config, "JPY") ? "1" : "0") . "\n";' . "\n"
        . 'echo "btc=" . (wallos_currency_code_acceptable(null, $config, "BTC") ? "1" : "0") . "\n";'
    );

    assert_contains('jpy=1', $out, 'a real ISO code is accepted offline (got: ' . $out . ')');
    assert_contains('btc=0', $out, 'a code no configured provider can vouch for is refused offline (got: ' . $out . ')');
});

/**
 * Invokes api/currencies/set_currencies.php (authenticated by api_key, no
 * session) with an add action and the given code.
 *
 * @param string $apiKey
 * @param string $code
 * @return string merged stdout/stderr
 */
function codevalidation_api_add($apiKey, $code)
{
    $body = [];
    $body[] = 'function wallos_provider_http_get($url, $context) {';
    $body[] = '    return ["body" => false, "headers" => null];';
    $body[] = '}';
    $body[] = '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('POST', true) . ';';
    $body[] = '$_POST = ' . var_export([
        'api_key' => $apiKey,
        'action' => 'add',
        'name' => 'Test',
        'symbol' => 'x',
        'code' => $code,
    ], true) . ';';
    $body[] = 'chdir(' . var_export(WALLOS_ROOT . '/api/currencies', true) . ');';
    $body[] = 'require ' . var_export('set_currencies.php', true) . ';';

    return codevalidation_run_php(implode("\n", $body));
}

wallos_test('the API path refuses an invented code and accepts a real one (#133)', function () {
    // The path an integration uses. Its "not empty" check was the whole of its
    // validation; now the same code rule applies here as on the settings page.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', 'alice-api-key', SQLITE3_TEXT);
    $stmt->execute();

    $refused = codevalidation_api_add('alice-api-key', 'LUN');
    assert_contains('"success":false', $refused, 'the API refuses an invented code (got: ' . $refused . ')');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'LUN']),
        'and does not store it');

    $accepted = codevalidation_api_add('alice-api-key', 'JPY');
    assert_contains('"success":true', $accepted, 'the API accepts a real ISO code (got: ' . $accepted . ')');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'JPY']),
        'and stores it');

    $db->close();
});
