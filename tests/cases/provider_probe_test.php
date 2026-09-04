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
 * The stub answers the control request and the probe request differently,
 * which is the whole point: the probe is a comparison, not a single reading.
 *
 * @param string $controlBody    the answer to EUR,USD
 * @param string $controlHeaders PHP array literal
 * @param string $probeBody      the answer to EUR,USD,ZQX
 * @param string $probeHeaders   PHP array literal
 * @return array{output: string, status: int}
 */
function probe_run($controlBody, $controlHeaders, $probeBody, $probeHeaders)
{
    $script = WALLOS_TEST_TMP . '/probe-' . uniqid('', true) . '.php';

    file_put_contents($script, "<?php\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $probe = strpos($url, "ZQX") !== false;' . "\n"
        . '    echo "asked=" . ($probe ? "probe" : "control") . "\n";' . "\n"
        . '    return $probe' . "\n"
        . '        ? ["body" => ' . $probeBody . ', "headers" => ' . $probeHeaders . ']' . "\n"
        . '        : ["body" => ' . $controlBody . ', "headers" => ' . $controlHeaders . '];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "notes" => [], "mode" => "instance",' . "\n"
        . '    "values" => ["provider" => 1, "api_key" => "k"]];' . "\n"
        // The control goes first, exactly as the probe script does it: asked
        // afterwards it would be answered from the refusal cache.
        . '$control = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'if (!$control["success"]) { echo "verdict=inconclusive\n"; exit; }' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "EUR,USD,ZQX");' . "\n"
        . '$s = $answer["status"] ?? null;' . "\n"
        . '$aboutKey = $s === null || $s === 401 || $s === 403 || $s === 429 || $s >= 500;' . "\n"
        . 'if (!$answer["success"] && $aboutKey) { echo "verdict=inconclusive\n"; exit; }' . "\n"
        . 'if ($answer["success"]) {' . "\n"
        . '    $priced = array_change_key_case($answer["rates"] ?? [], CASE_UPPER);' . "\n"
        . '    echo "verdict=" . (array_key_exists("ZQX", $priced) ? "priced" : "ignored") . "\n";' . "\n"
        . '} else {' . "\n"
        . '    echo "verdict=refused\n";' . "\n"
        . '}' . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/** The answer a working provider gives to either request. */
const PROBE_PRICED = '\'{"success":true,"rates":{"EUR":1,"USD":1.09}}\'';
const PROBE_OK = '["HTTP/1.1 200 OK"]';

wallos_test('a provider that drops the unknown symbol reads as ignored', function () {
    $run = probe_run(PROBE_PRICED, PROBE_OK, PROBE_PRICED, PROBE_OK);

    assert_contains('verdict=ignored', $run['output'],
        'the rest was priced and the unknown code simply is not in the answer');
    assert_contains('asked=control', $run['output'], 'the control request was made');
    assert_contains('asked=probe', $run['output'], 'and so was the probe');
});

wallos_test('a provider that refuses only the request with the unknown symbol', function () {
    // fixer answers an unknown code with error 202, over HTTP 200 — which is
    // why the status line alone can never be the test.
    $run = probe_run(PROBE_PRICED, PROBE_OK,
        '\'{"success":false,"error":{"code":202,"info":"You have provided one or more invalid Currency Codes."}}\'',
        PROBE_OK);

    assert_contains('verdict=refused', $run['output'],
        'priced without the unknown symbol, refused with it — the symbol is the difference');
});

wallos_test('an exhausted quota is not an answer about symbols', function () {
    // This is what actually happened on 2026-09-04, and what the first version
    // of this probe recorded as proof that an unknown symbol takes a request
    // down. The provider's monthly limit was reached, so it would have refused
    // anything at all — including the control.
    $run = probe_run(
        '\'{"message":"Your monthly usage limit has been reached. Please upgrade your Subscription Plan."}\'',
        '["HTTP/1.1 429 Too Many Requests"]',
        PROBE_PRICED, PROBE_OK);

    assert_contains('verdict=inconclusive', $run['output'],
        'a refusal that arrives without an unknown symbol says nothing about them');
    assert_true(strpos($run['output'], 'verdict=refused') === false,
        'and must not be recorded as one');
});

wallos_test('a quota reached between the two requests is also inconclusive', function () {
    // The narrow case the control alone does not cover: the control was
    // priced, the probe was refused, and the reason is still not the symbol.
    $run = probe_run(PROBE_PRICED, PROBE_OK,
        '\'{"message":"You have exceeded your monthly quota"}\'',
        '["HTTP/1.1 429 Too Many Requests"]');

    assert_contains('verdict=inconclusive', $run['output'],
        'a credential-level refusal stays inconclusive even after a good control');
});

wallos_test('a provider that prices the probe code says so rather than guessing', function () {
    // Nothing expects this. If the code turns out to be one the provider
    // knows, the probe proved nothing about unknown codes, and a verdict of
    // "ignored" here would be a wrong answer dressed as a measurement.
    $run = probe_run(PROBE_PRICED, PROBE_OK,
        '\'{"success":true,"rates":{"EUR":1,"USD":1.09,"ZQX":42}}\'', PROBE_OK);

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

    // The rule that matters most, because getting it wrong is worse than not
    // asking: an inconclusive run stores nothing, so it asks again rather than
    // cementing an answer to a question that was never put.
    assert_contains("wallos_cron_count('inconclusive')", $source,
        'an inconclusive run says so');
    $inconclusive = substr($source, strpos($source, "wallos_cron_count('inconclusive')"));
    assert_true(strpos(substr($inconclusive, 0, 400), 'probe_verdict') === false,
        'and writes no verdict, so the next start asks again');

    // The whole point of the redesign: it must not reach user data.
    assert_true(strpos($source, 'INSERT INTO currencies') === false,
        'it does not add a currency to anybody');
    assert_true(strpos($source, 'last_exchange_update') === false,
        'and it does not touch the freshness rows');
});
