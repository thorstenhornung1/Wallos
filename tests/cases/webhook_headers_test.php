<?php
/*
  What a webhook request says about its own body (#128; upstream #990).

  cURL labels a string body application/x-www-form-urlencoded unless told
  otherwise, so a JSON payload arrived at receivers like n8n as one giant
  form key — '{"name":...}' as the key, empty string as the value, exactly
  the shape the upstream report shows. Custom headers were only ever a
  workaround. One helper decides now, and both send paths use it.
*/

require_once WALLOS_ROOT . '/includes/webhook_headers.php';

wallos_test('a JSON payload announces itself', function () {
    $headers = wallos_webhook_headers('{"name":"Netflix"}', null);

    assert_true(in_array('Content-Type: application/json', $headers, true),
        'the default header is set (got: ' . json_encode($headers) . ')');
});

wallos_test('a custom content type always wins', function () {
    $headers = wallos_webhook_headers('{"a":1}', ['content-type: text/plain', 'X-Token: t']);

    assert_true(in_array('content-type: text/plain', $headers, true), 'the custom type stays');
    assert_true(in_array('X-Token: t', $headers, true), 'other custom headers stay');

    foreach ($headers as $header) {
        assert_true(stripos($header, 'application/json') === false,
            'and nothing second-guesses it: ' . $header);
    }
});

wallos_test('a body that is not JSON is not labelled as JSON', function () {
    assert_same([], wallos_webhook_headers('name=Netflix&price=9.99', null),
        'no header is invented for a form body');
    assert_same([], wallos_webhook_headers('', null),
        'nor for an empty one');
});

wallos_test('both send paths use the one helper', function () {
    foreach (['endpoints/cronjobs/sendnotifications.php',
              'endpoints/notifications/testwebhooknotifications.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(strpos($source, 'wallos_webhook_headers(') !== false,
            $path . ' builds its headers through the helper');
    }
});
