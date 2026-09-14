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

wallos_test('the account the SSRF check is asked about is the account, not the last payer', function () {
    // The cron holds the account being notified in $userId, set once per user
    // at the top of the loop. Every channel then iterates the subscriptions by
    // *payer*, and those loops used to be written `foreach ($notify as $userId
    // => $perUser)` — which overwrites the account with a household member id
    // and leaves it overwritten for everything that follows.
    //
    // What follows is the SSRF check. Discord, Gotify, Mattermost, ntfy and the
    // webhook each ask is_url_safe_for_ssrf($url, $db, $userId), and that third
    // argument decides one question: is this an administrator, who may reach a
    // private address. Asked with a household member id, the answer is about
    // somebody who may not even be an account.
    //
    // Measured on a real cron run against a local receiver, one condition
    // changed between the two:
    //
    //   Discord enabled (its loop runs first)  -> "SSRF attempt detected for
    //                                             webhook URL", nothing sent
    //   Discord disabled, everything else same -> "Webhook Notification sent",
    //                                             POST /webhook arrives
    //
    // Same owner (an admin), same allowlist, same target. Whether a webhook
    // reaches a permitted private address depended on which other channel had
    // run before it.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');

    assert_true(strpos($source, 'foreach ($notify as $userId =>') === false,
        'no channel loop binds the payer to $userId, which the SSRF checks below them read');

    // The positive half: the loops still iterate, under a name of their own.
    assert_true(substr_count($source, 'foreach ($notify as $payerUserId =>') >= 10,
        'the per-payer loops are still there, named for what they carry');

    // And the checks still ask about the account. Each of these sits after at
    // least one payer loop, which is exactly why the shadowing reached them.
    foreach (['discord', 'gotify', 'mattermost', 'ntfy', 'webhook'] as $channel) {
        assert_true(preg_match('/is_url_safe_for_ssrf\(\$' . $channel . '\[[^]]+\], \$db, \$userId\)/', $source) === 1,
            'the ' . $channel . ' check asks about $userId, the account');
    }
});
