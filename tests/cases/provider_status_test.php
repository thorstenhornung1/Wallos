<?php
/*
  Telling a refusal from an outage.

  The provider client asked for rates with @file_get_contents() and no
  'ignore_errors', so every 4xx and 5xx arrived as false and the @ discarded the
  warning that named the status. An expired key, an exhausted quota, a provider
  fault and a genuine network failure all produced "The currency provider could
  not be reached" — true in one of the four cases, and the other three sent
  whoever read it looking for a network problem that was not there (issue #101).

  What makes the difference recoverable is that PHP sets $http_response_header
  only when an HTTP response actually arrived. No headers means nothing
  answered; headers with a 401 means something answered and said no. That is
  the whole distinction, and it needs no network to test: these functions take
  the header array as an argument.

  The test that matters is the last one. Each individual message being right
  is worth little if two causes still produce the same sentence — the defect
  was never a wrong message, it was four causes sharing one.
*/

require_once WALLOS_ROOT . '/includes/http_status.php';

wallos_test('the status line yields its code', function () {
    assert_same(200, wallos_http_status_code(['HTTP/1.1 200 OK', 'Content-Type: application/json']),
        'a plain 200');
    assert_same(401, wallos_http_status_code(['HTTP/1.1 401 Unauthorized']), 'a refusal');
    assert_same(503, wallos_http_status_code(['HTTP/2 503']), 'HTTP/2 has no minor version');
});

wallos_test('a redirect keeps the status that answered last', function () {
    // file_get_contents follows redirects and appends each response's headers,
    // so the array holds several status lines. The first one describes a hop,
    // not the answer.
    $headers = ['HTTP/1.1 301 Moved Permanently', 'Location: https://example.test/',
                'HTTP/1.1 429 Too Many Requests'];

    assert_same(429, wallos_http_status_code($headers), 'the last status is the real one');
});

wallos_test('no headers at all means nothing answered', function () {
    // PHP leaves $http_response_header unset when the request never reached a
    // server. This is the one case that genuinely is "could not be reached".
    assert_same(null, wallos_http_status_code(null), 'unset headers');
    assert_same(null, wallos_http_status_code([]), 'empty headers');
    assert_same(null, wallos_http_status_code(['Content-Type: text/html']), 'no status line');
});

wallos_test('each failure names its own cause', function () {
    assert_contains('could not be reached', wallos_provider_failure_message(null, null),
        'no answer is the only real outage');
    assert_contains('rejected', wallos_provider_failure_message(401, null),
        '401 is a rejected credential');
    assert_contains('rejected', wallos_provider_failure_message(403, null),
        '403 is also a rejected credential');
    assert_contains('quota', wallos_provider_failure_message(429, null),
        '429 is the quota, not the key');
    assert_contains('provider', wallos_provider_failure_message(503, null),
        '5xx is the provider at fault, not us');
});

wallos_test('the provider gets to say it in its own words', function () {
    // apilayer and fixer.io both return a body explaining the refusal, and it
    // is more specific than any category we could assign: "You have exceeded
    // your daily rate limit" tells an admin what to do, "quota" does not.
    $body = ['error' => ['info' => 'Invalid authentication credentials']];
    $message = wallos_provider_failure_message(401, $body);

    assert_contains('Invalid authentication credentials', $message, 'the provider text survives');
    assert_contains('401', $message, 'and the status is still there to categorise it');
});

wallos_test('fixer.io phrases its errors differently and is still understood', function () {
    // fixer.io answers 200 with success:false and a numeric code rather than an
    // HTTP status, which is why the status alone cannot carry the diagnosis.
    $body = ['success' => false, 'error' => ['code' => 101, 'type' => 'invalid_access_key',
             'info' => 'You have not supplied a valid API Access Key.']];

    assert_contains('valid API Access Key', wallos_provider_failure_message(200, $body),
        'a 200 that carries a refusal is still a refusal');
});

wallos_test('the four causes do not share a sentence', function () {
    // The point of the issue. Four distinguishable states must produce four
    // distinguishable messages; anything else sends the reader after the wrong
    // problem.
    $messages = [
        'unreachable' => wallos_provider_failure_message(null, null),
        'rejected'    => wallos_provider_failure_message(401, null),
        'quota'       => wallos_provider_failure_message(429, null),
        'provider'    => wallos_provider_failure_message(503, null),
    ];

    assert_same(4, count(array_unique($messages)),
        'four causes, four messages: ' . json_encode($messages));
});

wallos_test('every provider request lets the error body through', function () {
    // The ratchet. Adding a provider branch without 'ignore_errors' puts the
    // defect back: the branch answers false for a 401, and the caller is left
    // saying "could not be reached" again. Both files reach the provider on two
    // paths — apilayer and fixer.io — and the fixer.io one is the one that was
    // missing a stream context entirely, in both files.
    foreach (['includes/currency_provider.php', 'api/fixer/set_fixer.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        $requests = substr_count($source, 'file_get_contents(');
        $permits = substr_count($source, "'ignore_errors' => true");

        assert_true($requests > 0, $path . ' does reach the provider');
        assert_same($requests, $permits,
            $path . ' sets ignore_errors on each of its ' . $requests . ' requests');
    }
});

wallos_test('the message is derived, not asserted', function () {
    // The old text is gone from both callers. It survives in exactly one place
    // — wallos_provider_failure_message(), for the case where it is true.
    foreach (['includes/currency_provider.php', 'api/fixer/set_fixer.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_not_contains('could not be reached', $source,
            $path . ' no longer hard-codes the outage message');
        assert_contains('wallos_provider_failure_message', $source,
            $path . ' asks for the message instead');
    }
});
