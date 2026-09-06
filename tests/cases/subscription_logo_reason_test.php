<?php
/*
  A failed subscription logo fetch names WHICH failure it was, and leaves a
  trace in the log (#158).

  endpoints/subscription/add.php::getLogoFromUrl() used to collapse every
  distinct failure — the SSRF/IP reject, a DNS miss, an HTTP 4xx/5xx, a
  non-image 200, a timeout — into one generic message and called no
  error_log(), so a blocked or failed fetch was invisible in both the UI and
  the log. This is the subscription-side sibling of the payment-logo fix
  (upstream #1200 / ellite/Wallos#1185): the two paths now report failures the
  same way.

  The pre-network reasons (bad URL, blocked address, unresolvable host) are
  exercised for real in an isolated subprocess with error_log() captured to a
  file, so the assertion is on behaviour, not on text. The reasons that need a
  live HTTP source (an HTTP status, a non-image 200, a transport error, an
  undecodable body) are asserted against the function's own source, together
  with the guarantee that no error_log() line carries the full URL — only the
  sanitised host — because the URL can hold a query secret.
*/

/**
 * The source of one function, brace-matched out of a file.
 */
function sublogo_extract_function($source, $name)
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return null;
    }
    $open = strpos($source, '{', $start);
    if ($open === false) {
        return null;
    }
    $depth = 0;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

wallos_test('a failed subscription logo fetch names the reason and logs it', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php');
    $function = sublogo_extract_function($source, 'getLogoFromUrl');
    assert_true($function !== null, 'getLogoFromUrl() was found in the source');

    // A self-contained harness: stub the helpers the function leans on, capture
    // error_log() to a file, and call it for the failures that never touch the
    // network. translate() echoes its key back as T:<key> so the test can see
    // which message the branch chose. Run in a subprocess so none of this — the
    // stubbed functions, the error_log redirect — leaks into the suite.
    $capture = WALLOS_TEST_TMP . '/sublogo-' . uniqid('', true) . '.log';
    $script  = WALLOS_TEST_TMP . '/sublogo-' . uniqid('', true) . '.php';

    $harness = "<?php\n"
        . "error_reporting(E_ERROR | E_PARSE);\n"
        . "ini_set('log_errors', '1');\n"
        . "ini_set('error_log', " . var_export($capture, true) . ");\n"
        . "function translate(\$key, \$i18n) { return 'T:' . \$key; }\n"
        . "function is_cgnat_ip(\$ip) { return false; }\n"
        . "function sanitizeFilename(\$n) { return preg_replace('/[^a-zA-Z0-9]/', '', \$n); }\n"
        . "function saveLogo(\$d, \$f, \$n, \$s) { return false; }\n"
        . $function . "\n"
        . "\$cases = [\n"
        . "  ['name' => 'invalid_url', 'url' => 'notaurl'],\n"
        . "  ['name' => 'ssrf',        'url' => 'http://127.0.0.1/logo.png?token=SECRETQUERY123'],\n"
        . "  ['name' => 'dns',         'url' => 'http://does-not-exist.invalid/logo.png?k=SECRETQUERY456'],\n"
        . "];\n"
        . "\$out = [];\n"
        . "foreach (\$cases as \$c) {\n"
        . "    @file_put_contents(" . var_export($capture, true) . ", '');\n"
        . "    \$r = getLogoFromUrl(\$c['url'], '/tmp/', 'The Vienna Times', [], []);\n"
        . "    clearstatcache();\n"
        . "    \$log = @file_get_contents(" . var_export($capture, true) . ");\n"
        . "    \$out[] = ['name' => \$c['name'], 'result' => \$r, 'log' => \$log === false ? '' : \$log];\n"
        . "}\n"
        . "echo json_encode(\$out);\n";

    file_put_contents($script, $harness);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, WALLOS_ROOT);
    assert_true(is_resource($process), 'the harness subprocess started');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    @unlink($script);
    @unlink($capture);

    $decoded = json_decode($stdout, true);
    assert_true(is_array($decoded) && count($decoded) === 3,
        'the harness returned three cases (stdout=' . $stdout . ' stderr=' . $stderr . ')');
    if (!is_array($decoded)) {
        return;
    }

    $byName = [];
    foreach ($decoded as $entry) {
        $byName[$entry['name']] = $entry;
    }

    // 1) Malformed URL — its own message, and a log line.
    $invalid = $byName['invalid_url'];
    assert_same(false, $invalid['result']['success'], 'a malformed URL is a failure');
    assert_same('Invalid URL format.', $invalid['result']['message'], 'the malformed URL is named as such');
    assert_contains('malformed URL', $invalid['log'], 'the malformed URL was logged');

    // 2) Blocked address (SSRF) — distinct message, distinct log, and the
    //    query secret must NOT appear in the log.
    $ssrf = $byName['ssrf'];
    assert_same(false, $ssrf['result']['success'], 'a private address is a failure');
    assert_same('Invalid IP Address.', $ssrf['result']['message'], 'the blocked address is named as such');
    assert_contains('blocked a non-public address', $ssrf['log'], 'the blocked address was logged');
    assert_contains('127.0.0.1', $ssrf['log'], 'the host reached the log');
    assert_not_contains('SECRETQUERY123', $ssrf['log'], 'the query secret never reaches the log');
    assert_not_contains('token=', $ssrf['log'], 'the query string never reaches the log');

    // 3) Unresolvable host (DNS) — distinct from the SSRF reject, and again no
    //    query secret in the log.
    $dns = $byName['dns'];
    assert_same(false, $dns['result']['success'], 'an unresolvable host is a failure');
    assert_contains('T:error_fetching_image', $dns['result']['message'], 'the DNS failure reuses the fetch-error string');
    assert_contains('T:could_not_resolve_host', $dns['result']['message'], 'the DNS failure names resolution');
    assert_contains('could not resolve host', $dns['log'], 'the DNS failure was logged');
    assert_not_contains('SECRETQUERY456', $dns['log'], 'the query secret never reaches the log');

    // The three reasons are genuinely distinct, not the same generic message.
    $messages = [
        $invalid['result']['message'],
        $ssrf['result']['message'],
        $dns['result']['message'],
    ];
    assert_same(3, count(array_unique($messages)), 'each reason produced a distinct message');
});

wallos_test('the network-only failure branches are named and logged, and the log never carries the URL', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php');
    $function = sublogo_extract_function($source, 'getLogoFromUrl');
    assert_true($function !== null, 'getLogoFromUrl() was found in the source');

    // An HTTP status from the source is named with the code.
    assert_contains('(HTTP ', $function, 'an HTTP status branch reports the code');
    assert_contains("got HTTP", $function, 'the HTTP status is logged');

    // A 200 that is not an image is named as not-an-image, off the content type.
    assert_contains('CURLINFO_CONTENT_TYPE', $function, 'the content-type is inspected');
    assert_contains('error_not_an_image', $function, 'a non-image response is named');
    assert_contains('non-image content-type', $function, 'the non-image response is logged');

    // A transport failure / timeout is named from curl_error.
    assert_contains('$imageData === false', $function, 'a transport failure has its own branch');
    assert_contains('transport error', $function, 'the transport failure is logged');

    // An undecodable body is distinct from the branches above.
    assert_contains('could not decode', $function, 'an undecodable body is logged');

    // The i18n keys the new messages use exist in the canonical catalogue, so
    // translate() resolves them rather than returning the missing-string marker.
    $en = file_get_contents(WALLOS_ROOT . '/includes/i18n/en.php');
    assert_contains('"error_not_an_image"', $en, 'error_not_an_image is defined in en.php');
    assert_contains('"could_not_resolve_host"', $en, 'could_not_resolve_host is defined in en.php');

    // The guarantee: every error_log() line logs the sanitised host, never the
    // full URL — the URL can carry a query secret. Walk each error_log( call
    // and assert none of them names $url or $currentUrl.
    $offset = 0;
    $seen = 0;
    while (($at = strpos($function, 'error_log(', $offset)) !== false) {
        $seen++;
        $open = strpos($function, '(', $at);
        $depth = 0;
        $call = '';
        for ($i = $open, $n = strlen($function); $i < $n; $i++) {
            $ch = $function[$i];
            $call .= $ch;
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        assert_not_contains('$currentUrl', $call, 'an error_log() line must not carry $currentUrl');
        assert_not_contains('$url', $call, 'an error_log() line must not carry $url');
        $offset = $at + 10;
    }
    assert_true($seen >= 6, 'every failure branch logs (found ' . $seen . ' error_log calls)');
});
