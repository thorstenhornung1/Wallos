<?php
/*
  Asking the provider which codes it will price.

  The rate endpoint cannot answer that. It is handed a symbol list and answers
  with rates, so a code it does not know either comes back missing — and the
  update loop walks the rates it got, not the codes it asked for, so nothing
  notices — or it takes the whole request down and says nothing about which
  code was the problem (#133, #135).

  The symbols endpoint answers it directly, which is what makes an invented
  currency findable instead of merely wrong.

  Each case runs in a child process. The transport is function_exists-guarded,
  so a stub only takes effect when nothing has loaded the client yet — and in a
  suite that is a question about case order rather than about the code. The
  first draft of this file passed alone and failed in company.
*/

/**
 * Runs PHP that defines its own transport before loading the client.
 *
 * @param string $body PHP without the opening tag.
 * @return array{output: string, status: int}
 */
function symbols_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/symbols-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * @param string $body     what the transport answers with
 * @param string $headers  PHP array literal of response headers
 * @param int    $provider 0 fixer.io, 1 apilayer
 * @return string
 */
function symbols_probe($body, $headers = '["HTTP/1.1 200 OK"]', $provider = 1)
{
    return 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    echo "url=" . $url . "\n";' . "\n"
        . '    return ["body" => ' . $body . ', "headers" => ' . $headers . '];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "notes" => [],' . "\n"
        . '    "values" => ["provider" => ' . (int) $provider . ', "api_key" => "test-key"]];' . "\n"
        . '$answer = wallos_fetch_currency_symbols($config);' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "transport=" . ($answer["transport"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "codes=" . implode(",", array_keys($answer["symbols"])) . "\n";' . "\n"
        . 'echo "usd=" . ($answer["symbols"]["USD"] ?? "-") . "\n";' . "\n"
        . 'echo "message=" . $answer["message"] . "\n";';
}

wallos_test('the symbols come back uppercased and sorted', function () {
    $run = symbols_run_php(symbols_probe(
        '\'{"success":true,"symbols":{"usd":"United States Dollar","EUR":"Euro","chf":"Swiss Franc"}}\''));

    assert_contains('success=yes', $run['output'], 'the provider answered');
    assert_contains('codes=CHF,EUR,USD', $run['output'],
        'codes are comparable however the provider spelled them, and in one order');
    assert_contains('usd=United States Dollar', $run['output'], 'and keep their names');
    assert_contains('transport=yes', $run['output'], 'the answer cost a request');
});

wallos_test('each provider is asked at its own address', function () {
    $apilayer = symbols_run_php(symbols_probe('\'{"symbols":{"EUR":"Euro"}}\''));
    assert_contains('api.apilayer.com/fixer/symbols', $apilayer['output'],
        'apilayer is asked over its own path');

    $fixer = symbols_run_php(symbols_probe('\'{"symbols":{"EUR":"Euro"}}\'', '["HTTP/1.1 200 OK"]', 0));
    assert_contains('data.fixer.io/api/symbols', $fixer['output'], 'and fixer.io over its own');
});

wallos_test('a refusal is a refusal, not an empty list', function () {
    // The distinction the whole tool rests on: an empty answer would report
    // every stored code as unknown to the provider, which is the loudest
    // possible way to be wrong.
    $refused = symbols_run_php(symbols_probe(
        '\'{"success":false,"error":{"code":104,"info":"monthly limit reached"}}\''));

    assert_contains('success=no', $refused['output'], 'a body without symbols is a failure');
    assert_contains('codes=' . "\n", $refused['output'] . "\n", 'and carries no symbols');
    assert_true(strpos($refused['output'], 'message=') !== false
        && trim(substr($refused['output'], strpos($refused['output'], 'message=') + 8)) !== '',
        'and says something about why');

    $unreachable = symbols_run_php(symbols_probe('false', 'null'));

    assert_contains('success=no', $unreachable['output'], 'so is no answer at all');
    assert_contains('transport=yes', $unreachable['output'], 'which still cost the request');
});

wallos_test('an unconfigured provider is not asked at all', function () {
    $run = symbols_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    echo "REACHED\n";' . "\n"
        . '    return ["body" => false, "headers" => null];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$answer = wallos_fetch_currency_symbols(["valid" => false,' . "\n"
        . '    "notes" => ["Currency provider is not configured."], "values" => []]);' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "transport=" . ($answer["transport"] ? "yes" : "no") . "\n";');

    assert_contains('success=no', $run['output'], 'it fails');
    assert_contains('transport=no', $run['output'], 'without spending a request');
    assert_true(strpos($run['output'], 'REACHED') === false,
        'and without reaching the transport at all');
});

wallos_test('the scheduled refresh can be told to insist', function () {
    // Without this an operator cannot reproduce a provider problem on the day
    // it happens: the job skips every account already refreshed, and the 02:00
    // run refreshed them. The web endpoint has had a force parameter all
    // along; a session is what an operator diagnosing a provider does not have.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php');

    assert_contains("php_sapi_name() === 'cli' && in_array('--force'", $source,
        'the flag is reachable from the command line and nowhere else');
    assert_contains('!$force && wallos_exchange_rates_fresh', $source,
        'and it is what the daily skip is asked about');
    assert_contains("wallos_cron_count('forced')", $source,
        'a forced run says so, because it replaces the scheduled run row');

    // The union fetch has to be told as well, or the forced accounts are
    // fetched one by one and the run costs a request per account.
    assert_contains('wallos_prewarm_shared_exchange_rates($db, array_column($userRows, \'id\'), $force)',
        $source, 'the union prewarm is forced with it');
});
