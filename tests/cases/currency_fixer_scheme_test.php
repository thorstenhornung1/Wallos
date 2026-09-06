<?php
/*
  The direct fixer.io path prefers https and only sends the key in cleartext over
  http when the plan genuinely forbids https (#141).

  data.fixer.io serves its FREE tier over http only; https is a paid feature. So
  the direct-fixer arm (provider mode 0) has always put the access_key in the
  query string over plain http. The fix tries https first — protecting a paid
  plan automatically — and falls back to http only on fixer.io's own
  https-restriction answer (error code 105 / https_access_restricted). When it
  does fall back, it says so: a logged reason at the fallback point and a mark
  the settings page reads to warn the operator.

  No test here makes a real request. The one network touch is
  wallos_provider_http_get(), guarded by function_exists(), so the child
  processes below define their own transport before loading the client — exactly
  as currency_symbols_test.php and currency_frankfurter_test.php do. The
  transport inspects the URL scheme and answers like the live service:
  https -> the 105 refusal on a free plan, rates on a paid one; http -> rates.

  The persistence half (the settings-page mark) runs in-process against the real
  database fixture, so it is exercised on SQLite and PostgreSQL both.
*/

require_once WALLOS_ROOT . '/includes/currency_provider.php';

/**
 * Runs a PHP snippet as its own process, so a stub transport takes effect before
 * the client is loaded. Local to this file for the same reason the other
 * currency suites keep their runner local: the filter may load this file alone.
 * The script path is generated here and quoted; nothing a request could reach.
 *
 * @param string $body PHP without the opening tag.
 * @return array{output: string, status: int}
 */
function fixer_scheme_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/fixer-scheme-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * A transport that records every URL it is asked for and answers by scheme.
 *
 * @param bool $httpsAllowed Whether the plan serves https (a paid plan) — if not,
 *                           an https request gets fixer.io's 105 refusal.
 * @return string PHP source defining wallos_provider_http_get() and the recorder.
 */
function fixer_scheme_transport($httpsAllowed)
{
    $restriction = json_encode([
        'success' => false,
        'error' => [
            'code' => 105,
            'type' => 'https_access_restricted',
            'info' => 'Access Restricted - Your current Subscription Plan does not support HTTPS Encryption.',
        ],
    ]);
    $rates = '{"success":true,"rates":{"USD":1.1612,"GBP":0.86005,"EUR":1}}';
    $symbols = '{"success":true,"symbols":{"USD":"United States Dollar","EUR":"Euro"}}';

    return '$GLOBALS["seen"] = [];' . "\n"
        . '$GLOBALS["https_allowed"] = ' . ($httpsAllowed ? 'true' : 'false') . ';' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["seen"][] = $url;' . "\n"
        . '    $isHttps = strpos($url, "https://") === 0;' . "\n"
        . '    $isSymbols = strpos($url, "/symbols") !== false;' . "\n"
        . '    if ($isHttps && !$GLOBALS["https_allowed"]) {' . "\n"
        . '        return ["body" => ' . var_export($restriction, true) . ', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '    }' . "\n"
        . '    $body = $isSymbols ? ' . var_export($symbols, true) . ' : ' . var_export($rates, true) . ';' . "\n"
        . '    return ["body" => $body, "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        // error_log() to a file we can read back, so "it logged the reason" is a
        // deterministic assertion rather than a guess about the CLI's stderr.
        . '$GLOBALS["logfile"] = tempnam(sys_get_temp_dir(), "wlog");' . "\n"
        . 'ini_set("error_log", $GLOBALS["logfile"]);' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n";
}

/**
 * The lines a case echoes so the parent can read one transcript.
 *
 * @return string
 */
function fixer_scheme_report()
{
    return 'echo "seen=" . implode(" ", $GLOBALS["seen"]) . "\n";' . "\n"
        . 'echo "log=" . str_replace("\n", " ", (string) @file_get_contents($GLOBALS["logfile"])) . "\n";';
}

/* -------------------------------------------------------------------------
   https first.
   ------------------------------------------------------------------------- */

wallos_test('a paid fixer.io plan is served over https, and the key never touches http', function () {
    $run = fixer_scheme_run_php(
        fixer_scheme_transport(true)
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 0, "api_key" => "secret-key"]];' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "USD");' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "fallback=" . ($answer["http_fallback"] ? "yes" : "no") . "\n";' . "\n"
        . fixer_scheme_report()
    );

    assert_contains('success=yes', $run['output'], 'the rates came back (got: ' . $run['output'] . ')');
    assert_contains('https://data.fixer.io/api/latest', $run['output'], 'https was the request that was made');
    assert_not_contains('http://data.fixer.io', $run['output'],
        'and the key never appeared in a cleartext http URL');
    assert_contains('fallback=no', $run['output'], 'so nothing fell back');
    assert_not_contains('cleartext', $run['output'], 'a paid plan produces no cleartext warning');
});

wallos_test('a free fixer.io plan falls back to http and raises the warning', function () {
    $run = fixer_scheme_run_php(
        fixer_scheme_transport(false)
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 0, "api_key" => "secret-key"]];' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "USD");' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "fallback=" . ($answer["http_fallback"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "restricted=" . ($answer["https_restricted"] ? "yes" : "no") . "\n";' . "\n"
        . fixer_scheme_report()
    );

    assert_contains('success=yes', $run['output'], 'the http fallback still returned rates (got: ' . $run['output'] . ')');
    assert_contains('https://data.fixer.io/api/latest', $run['output'], 'https was probed first');
    assert_contains('http://data.fixer.io/api/latest?access_key=secret-key', $run['output'],
        'then http carried the request when the plan refused https');
    assert_contains('fallback=yes', $run['output'], 'the answer reports the http fallback');
    assert_contains('restricted=yes', $run['output'], 'as a proven plan restriction, not a passing outage');
    assert_contains('cleartext', $run['output'], 'and the reason was logged at the fallback point');
});

wallos_test('the http-only plan is remembered, so https is not re-probed every request', function () {
    // Two fetches with different code lists, so the per-run answer cache misses
    // and the client actually asks again — and the second ask must go straight to
    // http, not pay the failed https round-trip a second time.
    $run = fixer_scheme_run_php(
        fixer_scheme_transport(false)
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 0, "api_key" => "secret-key"]];' . "\n"
        . 'wallos_fetch_exchange_rates($config, "USD");' . "\n"
        . 'wallos_fetch_exchange_rates($config, "GBP");' . "\n"
        . '$https = 0; $http = 0;' . "\n"
        . 'foreach ($GLOBALS["seen"] as $u) { strpos($u, "https://") === 0 ? $https++ : $http++; }' . "\n"
        . 'echo "https=" . $https . "\n";' . "\n"
        . 'echo "http=" . $http . "\n";'
    );

    assert_contains('https=1', $run['output'],
        'https is probed once for the process, not once per request (got: ' . $run['output'] . ')');
    assert_contains('http=2', $run['output'],
        'while both requests went out over http (got: ' . $run['output'] . ')');
});

wallos_test('the symbols endpoint uses the same https-first fallback', function () {
    $run = fixer_scheme_run_php(
        fixer_scheme_transport(false)
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 0, "api_key" => "secret-key"]];' . "\n"
        . '$answer = wallos_fetch_currency_symbols($config);' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "fallback=" . ($answer["http_fallback"] ? "yes" : "no") . "\n";' . "\n"
        . fixer_scheme_report()
    );

    assert_contains('success=yes', $run['output'], 'the symbol list came back (got: ' . $run['output'] . ')');
    assert_contains('https://data.fixer.io/api/symbols', $run['output'], 'https was probed first');
    assert_contains('http://data.fixer.io/api/symbols?access_key=secret-key', $run['output'],
        'then http carried the symbol request');
    assert_contains('fallback=yes', $run['output'], 'the symbols answer reports the fallback too');
});

/* -------------------------------------------------------------------------
   The other providers are untouched and never warn.
   ------------------------------------------------------------------------- */

wallos_test('the apilayer path is unchanged: https, key in a header, no warning', function () {
    $run = fixer_scheme_run_php(
        fixer_scheme_transport(true)
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 1, "api_key" => "secret-key"]];' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "USD");' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "fallback=" . ($answer["http_fallback"] ? "yes" : "no") . "\n";' . "\n"
        . fixer_scheme_report()
    );

    assert_contains('success=yes', $run['output'], 'apilayer still answers (got: ' . $run['output'] . ')');
    assert_contains('https://api.apilayer.com/fixer/latest', $run['output'], 'over its own https host');
    assert_not_contains('data.fixer.io', $run['output'], 'nothing routed it through the direct-fixer path');
    assert_not_contains('access_key=secret-key', $run['output'],
        'and its key stays in the apikey header, never in the URL');
    assert_contains('fallback=no', $run['output'], 'it never falls back');
    assert_not_contains('cleartext', $run['output'], 'and never warns about http');
});

wallos_test('the Frankfurter path is unchanged and never warns', function () {
    $run = fixer_scheme_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["seen"][] = $url;' . "\n"
        . '    return ["body" => json_encode([' . "\n"
        . '        ["date" => "2026-09-04", "base" => "EUR", "quote" => "USD", "rate" => 1.1612],' . "\n"
        . '    ]), "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . '$GLOBALS["seen"] = [];' . "\n"
        . '$GLOBALS["logfile"] = tempnam(sys_get_temp_dir(), "wlog");' . "\n"
        . 'ini_set("error_log", $GLOBALS["logfile"]);' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "notes" => [], "values" => ["provider" => 2, "api_key" => ""]];' . "\n"
        . '$answer = wallos_fetch_exchange_rates($config, "USD", "EUR");' . "\n"
        . 'echo "success=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "fallback=" . ($answer["http_fallback"] ? "yes" : "no") . "\n";' . "\n"
        . fixer_scheme_report()
    );

    assert_contains('success=yes', $run['output'], 'Frankfurter still answers (got: ' . $run['output'] . ')');
    assert_contains('https://api.frankfurter.dev', $run['output'], 'over its own https host');
    assert_not_contains('data.fixer.io', $run['output'], 'nothing routed it through the direct-fixer path');
    assert_contains('fallback=no', $run['output'], 'it never falls back');
    assert_not_contains('cleartext', $run['output'], 'and never warns about http');
});

/* -------------------------------------------------------------------------
   The settings-page mark: set on http, cleared on https, on both backends.
   ------------------------------------------------------------------------- */

wallos_test('the http-only mark is set on fallback and cleared on an https answer', function () {
    // The persistence the settings page reads, exercised against the real
    // database fixture so it runs on SQLite and PostgreSQL alike. record_scheme
    // reads the flags a fetch produced; here they are supplied directly so the
    // case is about the store, not the transport.
    $db = wallos_test_open_database();

    $config = ['valid' => true, 'mode' => 'instance', 'notes' => [],
        'values' => ['provider' => 0, 'api_key' => 'free-tier-key']];

    assert_true(!wallos_fixer_is_http_only($db, 'free-tier-key'),
        'nothing is marked before any request');

    // An http fallback with a proven restriction sets the mark.
    wallos_currency_record_scheme($db, $config,
        ['transport' => true, 'http_fallback' => true, 'https_restricted' => true]);
    assert_true(wallos_fixer_is_http_only($db, 'free-tier-key'),
        'a proven http fallback sets the mark the settings page warns from');

    // A later https answer (an upgraded plan) clears it.
    wallos_currency_record_scheme($db, $config,
        ['transport' => true, 'http_fallback' => false, 'https_restricted' => false]);
    assert_true(!wallos_fixer_is_http_only($db, 'free-tier-key'),
        'an https answer clears the mark, so an upgraded plan stops being warned about');

    $db->close();
});

wallos_test('a cached answer and the other providers never touch the mark', function () {
    $db = wallos_test_open_database();

    $fixer = ['valid' => true, 'mode' => 'instance', 'notes' => [],
        'values' => ['provider' => 0, 'api_key' => 'free-tier-key']];

    // Set the mark first, then prove the guards leave it exactly as it was.
    wallos_currency_record_scheme($db, $fixer,
        ['transport' => true, 'http_fallback' => true, 'https_restricted' => true]);
    assert_true(wallos_fixer_is_http_only($db, 'free-tier-key'), 'the mark is set to begin with');

    // A cached answer carries no transport, so it must not re-decide the mark.
    wallos_currency_record_scheme($db, $fixer,
        ['transport' => false, 'http_fallback' => false, 'https_restricted' => false]);
    assert_true(wallos_fixer_is_http_only($db, 'free-tier-key'),
        'a cached answer (no transport) leaves the mark untouched');

    // An apilayer answer with the same key must not clear the direct-fixer mark:
    // only the direct-fixer provider has a scheme to record.
    $apilayer = ['valid' => true, 'mode' => 'custom', 'notes' => [],
        'values' => ['provider' => 1, 'api_key' => 'free-tier-key']];
    wallos_currency_record_scheme($db, $apilayer,
        ['transport' => true, 'http_fallback' => false, 'https_restricted' => false]);
    assert_true(wallos_fixer_is_http_only($db, 'free-tier-key'),
        'a non-fixer provider never records a scheme, so the mark stands');

    $db->close();
});

/* -------------------------------------------------------------------------
   The warning is wired into the settings page, and reaches the fetch client.
   ------------------------------------------------------------------------- */

wallos_test('the settings page shows the cleartext warning off the persisted mark', function () {
    $page = file_get_contents(WALLOS_ROOT . '/settings.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/settings.js');

    assert_contains('fixerHttpOnlyWarning', $page, 'the page carries the warning line');
    assert_contains('wallos_fixer_is_http_only', $page,
        'and decides whether to show it from the persisted mark, not the provider alone');
    assert_contains('data-http-only', $page, 'the mark rides to the client on a data attribute');
    assert_contains('fixer_http_only_warning', $page, 'in the translated words');

    assert_contains('fixerHttpOnlyWarning', $script, 'the toggle knows the warning line');
    assert_contains('httpOnly', $script, 'and reads the mark');
    assert_contains('effectiveCurrencyProvider() === 0', $script,
        'showing it for the direct-fixer provider alone');

    // The English string exists and names both zero-cost https ways out.
    $english = (static function () {
        require WALLOS_ROOT . '/includes/i18n/en.php';

        return $i18n;
    })();

    assert_true(isset($english['fixer_http_only_warning']) && $english['fixer_http_only_warning'] !== '',
        'the warning has English text');
    assert_contains('cleartext', $english['fixer_http_only_warning'], 'it names the exposure');
    assert_contains('apilayer', strtolower($english['fixer_http_only_warning']),
        'and apilayer as the https way out');
    assert_contains('Frankfurter', $english['fixer_http_only_warning'],
        'and keyless Frankfurter as the other');
});

wallos_test('the direct-fixer host is named in one place, and save_user routes through the shared client', function () {
    // The centralisation the issue asks for (#141): no plaintext data.fixer.io
    // URL is built at any call site, and the fetch reaches the shared helper.
    $provider = 'includes/currency_provider.php';
    assert_not_contains('http://data.fixer.io', file_get_contents(WALLOS_ROOT . '/' . $provider),
        $provider . ' builds no plaintext direct-fixer URL of its own');
    assert_true(wallos_test_file_calls($provider, 'wallos_fixer_direct_get'),
        $provider . ' fetches through the shared helper');

    // save_user.php used to carry a third implementation of the provider call —
    // its own direct-fixer fetch, missing every guard the client has (#143). It
    // now builds no provider request at all: the main-currency-change refresh is
    // delegated to the shared client, which is the one place the helper is
    // reached, so the direct-fixer site here is gone rather than merely routed.
    $saveUser = file_get_contents(WALLOS_ROOT . '/endpoints/user/save_user.php');
    assert_not_contains('http://data.fixer.io', $saveUser,
        'save_user.php builds no plaintext direct-fixer URL of its own');
    assert_true(wallos_test_file_calls('endpoints/user/save_user.php', 'wallos_update_exchange_rates_for_user'),
        'save_user.php routes the refresh through the shared client (#143)');
    assert_true(!wallos_test_file_calls('endpoints/user/save_user.php', 'wallos_fixer_direct_get'),
        'and no longer carries its own direct-fixer implementation');

    // And the helper itself is the only place that names the direct-fixer host.
    $helper = file_get_contents(WALLOS_ROOT . '/includes/fixer_direct.php');
    assert_contains("wallos_fixer_direct_url('https'", $helper, 'the helper tries https first');
    assert_contains("wallos_fixer_direct_url('http'", $helper, 'and falls back to http');
    assert_contains('data.fixer.io', $helper, 'against the direct-fixer host');
    assert_contains('error_log', $helper, 'and logs the reason at the fallback point');
});
