<?php
/*
  The probe that answers what the provider does with a code it does not know.

  Two outcomes, far apart, and which one it is decides how bad #135 is: a
  provider that drops the unknown symbol leaves an invented currency at its
  seeded rate of 1 in every total (#133, quiet and wrong), and one that refuses
  the whole request lets a single account's typo stop rate refreshes for
  everyone sharing the credential (#135, quiet and broken).

  The probe reaches that answer with one request and no user data. An earlier
  plan inserted an invented currency on a real account, reset the freshness
  rows and waited for a scheduled run — a lot of moving parts for a question
  one request answers, and every one of them a way to leave the instance
  changed.
*/

/**
 * Runs the probe's decision with a stubbed transport, in its own process so
 * the function_exists guard on the transport is reached before the client.
 *
 * @param string $body    what the provider answers with
 * @param string $headers PHP array literal of response headers
 * @return array{output: string, status: int}
 */
function probe_run($body, $headers = '["HTTP/1.1 200 OK"]')
{
    $script = WALLOS_TEST_TMP . '/probe-' . uniqid('', true) . '.php';

    file_put_contents($script, "<?php\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    echo "asked=" . $url . "\n";' . "\n"
        . '    return ["body" => ' . $body . ', "headers" => ' . $headers . '];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "notes" => [], "mode" => "instance",' . "\n"
        . '    "values" => ["provider" => 1, "api_key" => "k"]];' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "EUR,USD,ZQX");' . "\n"
        . 'if ($answer["success"]) {' . "\n"
        . '    $priced = array_change_key_case($answer["rates"] ?? [], CASE_UPPER);' . "\n"
        . '    echo "verdict=" . (array_key_exists("ZQX", $priced) ? "priced" : "ignored") . "\n";' . "\n"
        . '} else {' . "\n"
        . '    echo "verdict=refused\n";' . "\n"
        . '}' . "\n"
        . 'echo "transport=" . ($answer["transport"] ? "yes" : "no") . "\n";' . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('a provider that drops the unknown symbol reads as ignored', function () {
    $run = probe_run('\'{"success":true,"rates":{"EUR":1,"USD":1.09}}\'');

    assert_contains('verdict=ignored', $run['output'],
        'the rest was priced and the unknown code simply is not in the answer');
    assert_contains('symbols=EUR,USD,ZQX', $run['output'],
        'and the unknown code really was asked for');
});

wallos_test('a provider that refuses the request reads as refused', function () {
    // fixer answers an unknown code with error 202, over HTTP 200 — which is
    // why the status line alone cannot be the test.
    $run = probe_run('\'{"success":false,"error":{"code":202,"info":"You have provided one or more invalid Currency Codes."}}\'');

    assert_contains('verdict=refused', $run['output'],
        'a body with no rates is a refusal however the status line reads');
    assert_contains('transport=yes', $run['output'], 'and it cost the request');
});

wallos_test('a provider that prices the probe code says so rather than guessing', function () {
    // Nothing expects this. If the code turns out to be one the provider
    // knows, the probe proved nothing about unknown codes, and a verdict that
    // said "ignored" here would be a wrong answer dressed as a measurement.
    $run = probe_run('\'{"success":true,"rates":{"EUR":1,"USD":1.09,"ZQX":42}}\'');

    assert_contains('verdict=priced', $run['output'],
        'the probe code came back with a rate, so it is not unknown to this provider');
});

wallos_test('the probe asks once and stores what it found', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/providerprobe.php');

    assert_contains("!empty(\$settings['probe_verdict'])", $source,
        'a stored verdict stops it asking again — one request per installation');
    assert_contains("wallos_set_instance_setting(\$db, 'currency', 'probe_verdict'", $source,
        'and the verdict is written down');
    assert_contains('wallos_count_currency_call', $source,
        'the request is counted like any other, because it is one');

    // The whole point of the redesign: it must not reach user data.
    assert_true(strpos($source, 'INSERT INTO currencies') === false,
        'it does not add a currency to anybody');
    assert_true(strpos($source, 'last_exchange_update') === false,
        'and it does not touch the freshness rows');
});
