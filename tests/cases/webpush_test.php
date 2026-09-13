<?php
/*
  Web Push (issue #162): the self-implemented crypto held to the RFC 8291 test
  vector, the ES256 VAPID JWT, and the subscription store / replace / delete /
  ownership / 404-410 stale-cleanup — proven on both backends.
*/

// The one network touch is stubbed before the module loads, so the delivery and
// cleanup cases can drive a 410 Gone without a socket. Each case sets the
// handler it wants in $GLOBALS['wallos_webpush_test_http'].
if (!function_exists('wallos_webpush_http_post')) {
    function wallos_webpush_http_post($url, $body, array $headers, $resolve)
    {
        $handler = $GLOBALS['wallos_webpush_test_http'] ?? null;
        if (is_callable($handler)) {
            return $handler($url, $body, $headers, $resolve);
        }

        return ['response' => '', 'status' => 201, 'error' => ''];
    }
}

require_once WALLOS_ROOT . '/includes/webpush.php';

// The RFC 8291 §5 worked example.
const WALLOS_WEBPUSH_RFC_PLAINTEXT = 'When I grow up, I want to be a watermelon';
const WALLOS_WEBPUSH_RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const WALLOS_WEBPUSH_RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
const WALLOS_WEBPUSH_RFC_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
const WALLOS_WEBPUSH_RFC_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const WALLOS_WEBPUSH_RFC_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
const WALLOS_WEBPUSH_RFC_EXPECTED = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

// Any valid P-256 client key and 16-byte auth: reused where the exact bytes do
// not matter, only that encryption succeeds. The RFC receiver key serves.
function wallos_webpush_test_p256dh()
{
    return WALLOS_WEBPUSH_RFC_UA_PUBLIC;
}

function wallos_webpush_test_auth()
{
    return WALLOS_WEBPUSH_RFC_AUTH;
}

/* -------------------------------------------------------------------------
   Crypto — known-answer tests
   ------------------------------------------------------------------------- */

wallos_test('base64url round-trips binary that plain base64 would corrupt', function () {
    $binary = "\x00\xff\xfe\x04watermelon\x7f\x80";
    $encoded = wallos_webpush_b64u_encode($binary);

    assert_true(strpos($encoded, '+') === false && strpos($encoded, '/') === false && strpos($encoded, '=') === false,
        'the url-safe alphabet has no +, / or padding');
    assert_same($binary, wallos_webpush_b64u_decode($encoded), 'decode is the inverse of encode');
});

wallos_test('aes128gcm encryption reproduces the RFC 8291 test vector byte for byte', function () {
    $body = wallos_webpush_encrypt(
        WALLOS_WEBPUSH_RFC_PLAINTEXT,
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AS_PRIVATE),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AS_PUBLIC),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_SALT),
        // The worked example in the RFC has no padding, so the vector is asked
        // for without it. The production path pads; the case below is that one.
        0
    );

    assert_true($body !== null, 'the encryption produced a body');
    assert_same(WALLOS_WEBPUSH_RFC_EXPECTED, wallos_webpush_b64u_encode($body),
        'the aes128gcm body equals the RFC 8291 §5 expected result');
});

wallos_test('every push is the same size on the wire, whatever it says', function () {
    // Without padding the body length is the message length plus a constant, so
    // somebody watching the connection to the push service learns how long each
    // notification was without decrypting anything. The messages are
    // predictable and the set of subscriptions an account holds is small, so a
    // length is worth something to a watcher.
    $lengths = [];

    foreach ([
        'short' => 'Netflix',
        'typical' => json_encode(['title' => 'Wallos', 'body' => 'Netflix renews in 3 days']),
        'long' => json_encode(['title' => 'Wallos', 'body' => str_repeat('Versicherung ', 60)]),
        'empty' => '',
    ] as $label => $payload) {
        $body = wallos_webpush_encrypt(
            $payload,
            wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC),
            wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH)
        );

        assert_true($body !== null, $label . ' encrypts');
        $lengths[$label] = strlen($body);
    }

    assert_same(1, count(array_unique($lengths)),
        'all four are the same length on the wire: ' . json_encode($lengths));

    // And the size is the one the other implementations use, so the padding
    // does not identify this application by being unusual. 2820 record + 16
    // GCM tag + 86 header.
    assert_same(2922, $lengths['short'], 'the wire body is the agreed size');
});

wallos_test('a payload between the padding and the ceiling is sent as it is', function () {
    // Longer than the padding target, so it says its own length — that is all
    // every push said before the padding, and it beats not notifying at all.
    $long = str_repeat('x', WALLOS_WEBPUSH_PADDED_RECORD + 100);
    assert_true(strlen($long) <= WALLOS_WEBPUSH_MAX_PLAINTEXT, 'the case stays below the ceiling');

    $body = wallos_webpush_encrypt(
        $long,
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH)
    );

    assert_true($body !== null, 'it still encrypts');
    assert_same(86 + strlen($long) + 1 + 16, strlen($body), 'header, plaintext, delimiter, tag — no padding left to add');
});

wallos_test('a payload past the RFC ceiling is refused, not silently unreadable', function () {
    // The header announces a record size of 4096. A record larger than that
    // overruns what it declared, and RFC 8291 §4 gives the boundary in plain
    // words: at most 3993 octets of plaintext. Past it the receiver reads a
    // plaintext octet where the 0x02 delimiter should be and MUST discard the
    // message — so "sending" it notifies nobody while the push service answers
    // 201 and the log says the notification went out.
    $ok = str_repeat('x', WALLOS_WEBPUSH_MAX_PLAINTEXT);
    $tooLong = str_repeat('x', WALLOS_WEBPUSH_MAX_PLAINTEXT + 1);

    $atCeiling = wallos_webpush_encrypt(
        $ok,
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH)
    );

    assert_true($atCeiling !== null, 'the longest permitted payload still encrypts');
    assert_true(strlen($atCeiling) - 86 <= 4096,
        'and its record fits the size the header declares: ' . (strlen($atCeiling) - 86));

    assert_same(null, wallos_webpush_encrypt(
        $tooLong,
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC),
        wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH)
    ), 'one octet past the ceiling is refused');
});

wallos_test('a subscription that could never be encrypted to is refused at the door', function () {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
    $key = wallos_webpush_test_p256dh();
    $auth = wallos_webpush_test_auth();

    assert_true(wallos_webpush_subscription_is_wellformed($endpoint, $key, $auth),
        'what a browser actually produces is accepted');

    // The p256dh is an uncompressed P-256 point: 65 octets, leading 0x04.
    $compressed = wallos_webpush_b64u_encode("\x02" . substr(wallos_webpush_b64u_decode($key), 1, 32));
    assert_true(!wallos_webpush_subscription_is_wellformed($endpoint, $compressed, $auth),
        'a compressed point is not the form RFC 8291 §4 fixes');
    assert_true(!wallos_webpush_subscription_is_wellformed($endpoint, wallos_webpush_b64u_encode("\x05" . str_repeat('a', 64)), $auth),
        '65 octets with the wrong leading byte is still not a point');
    assert_true(!wallos_webpush_subscription_is_wellformed($endpoint, 'not base64url at all !!!', $auth),
        'a key that does not even decode is refused');

    // The auth secret is exactly sixteen octets.
    assert_true(!wallos_webpush_subscription_is_wellformed($endpoint, $key, wallos_webpush_b64u_encode(str_repeat('a', 15))),
        'fifteen octets of auth is refused');
    assert_true(!wallos_webpush_subscription_is_wellformed($endpoint, $key, wallos_webpush_b64u_encode(str_repeat('a', 17))),
        'seventeen too');

    // And the endpoint is a bounded http(s) URL, not whatever arrived.
    assert_true(!wallos_webpush_subscription_is_wellformed('https://push.example/' . str_repeat('a', 2048), $key, $auth),
        'two kilobytes is the limit for a URL a push service issued');
    assert_true(!wallos_webpush_subscription_is_wellformed('javascript:alert(1)', $key, $auth), 'a non-http scheme is refused');
    assert_true(!wallos_webpush_subscription_is_wellformed('/relative/path', $key, $auth), 'so is a relative path');
    assert_true(!wallos_webpush_subscription_is_wellformed('', $key, $auth), 'and an empty endpoint');

    // The check must not be encryptable-in-principle only: the material it
    // accepts has to survive the actual encryption.
    assert_true(wallos_webpush_encrypt('x', wallos_webpush_b64u_decode($key), wallos_webpush_b64u_decode($auth)) !== null,
        'what the check accepts, the encryption accepts');
});

wallos_test('encryption refuses malformed client key material', function () {
    // A p256dh that is not a 65-byte point, and an auth that is not 16 bytes.
    assert_same(null, wallos_webpush_encrypt('x', 'too short', wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_AUTH)),
        'a bad client public key is rejected');
    assert_same(null, wallos_webpush_encrypt('x', wallos_webpush_b64u_decode(WALLOS_WEBPUSH_RFC_UA_PUBLIC), 'short'),
        'a bad auth secret is rejected');
});

wallos_test('a generated VAPID keypair is a raw P-256 point and scalar', function () {
    $keys = wallos_webpush_generate_vapid_keys();

    assert_true($keys !== null, 'a keypair was generated');
    assert_same(65, strlen(wallos_webpush_b64u_decode($keys['public'])), 'the public key is a 65-byte uncompressed point');
    assert_same(32, strlen(wallos_webpush_b64u_decode($keys['private'])), 'the private key is a 32-byte scalar');
    assert_same("\x04", substr(wallos_webpush_b64u_decode($keys['public']), 0, 1), 'the point is uncompressed');
});

wallos_test('a VAPID JWT is a valid ES256 signature over its claims', function () {
    $keys = wallos_webpush_generate_vapid_keys();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abcdef';

    $jwt = wallos_webpush_vapid_jwt($endpoint, 'mailto:admin@example.com', $keys['public'], $keys['private']);
    assert_true($jwt !== null, 'a token was produced');

    $parts = explode('.', $jwt);
    assert_same(3, count($parts), 'the JWT has three dot-separated parts');

    $header = json_decode(wallos_webpush_b64u_decode($parts[0]), true);
    assert_same('ES256', $header['alg'] ?? '', 'the algorithm is ES256');
    assert_same('JWT', $header['typ'] ?? '', 'the type is JWT');

    $claims = json_decode(wallos_webpush_b64u_decode($parts[1]), true);
    assert_same('https://fcm.googleapis.com', $claims['aud'] ?? '', 'the audience is the endpoint origin, not its path');
    assert_same('mailto:admin@example.com', $claims['sub'] ?? '', 'the subject is carried through');
    assert_true(($claims['exp'] ?? 0) > time(), 'the token has not already expired');

    // The signature is raw r||s; rebuild the DER OpenSSL verifies and check it
    // against the public key the browser would be handed.
    $raw = wallos_webpush_b64u_decode($parts[2]);
    assert_same(64, strlen($raw), 'an ES256 signature is 64 bytes');

    $encodeInt = function ($value) {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if (ord($value[0]) & 0x80) {
            $value = "\x00" . $value;
        }
        return "\x02" . chr(strlen($value)) . $value;
    };
    $sequence = $encodeInt(substr($raw, 0, 32)) . $encodeInt(substr($raw, 32, 32));
    $der = "\x30" . chr(strlen($sequence)) . $sequence;

    $publicKey = openssl_pkey_get_public(
        wallos_webpush_ec_public_pem(wallos_webpush_b64u_decode($keys['public']))
    );
    assert_true($publicKey !== false, 'the public key PEM is readable');

    $verified = openssl_verify($parts[0] . '.' . $parts[1], $der, $publicKey, OPENSSL_ALGO_SHA256);
    assert_same(1, $verified, 'the signature verifies against the VAPID public key');
});

wallos_test('the JWT audience drops non-default ports but keeps custom ones', function () {
    assert_same('https://push.example', wallos_webpush_endpoint_origin('https://push.example:443/wp/1'),
        'the default https port is omitted');
    assert_same('https://push.example:8443', wallos_webpush_endpoint_origin('https://push.example:8443/wp/1'),
        'a custom port is kept');
    assert_same(null, wallos_webpush_endpoint_origin('not a url'), 'a non-URL has no origin');
});

/* -------------------------------------------------------------------------
   Instance VAPID configuration
   ------------------------------------------------------------------------- */

wallos_test('the instance VAPID keypair is generated on first use, persisted, and its private key stays server-side', function () {
    $db = wallos_test_open_database();

    $config = wallos_get_instance_webpush_config($db);
    assert_true((bool) $config['values']['deliverable'], 'a keypair is available after first use');
    assert_true($config['values']['private_key'] !== '', 'the private key is present server-side');

    $payload = wallos_webpush_public_payload($config);
    $encoded = json_encode($payload);
    assert_not_contains($config['values']['private_key'], $encoded, 'the private key never reaches the public payload');
    assert_same($config['values']['public_key'], $payload['public_key'], 'the public key is exposed');
    assert_same(true, $payload['private_key']['configured'], 'the private key is reported as present');

    // Resolving again returns the same stored keypair rather than a fresh one.
    wallos_reset_config_cache($db);
    $again = wallos_get_instance_webpush_config($db);
    assert_same($config['values']['public_key'], $again['values']['public_key'], 'the keypair is persisted, not regenerated');
    assert_same($config['values']['private_key'], $again['values']['private_key'], 'including the private half');

    $db->close();
});

wallos_test('an environment VAPID keypair overrides the database', function () {
    $db = wallos_test_open_database();

    $keys = wallos_webpush_generate_vapid_keys();
    putenv('WALLOS_VAPID_PUBLIC_KEY=' . $keys['public']);
    putenv('WALLOS_VAPID_PRIVATE_KEY=' . $keys['private']);
    wallos_reset_config_cache($db);

    $config = wallos_get_instance_webpush_config($db);
    assert_same($keys['public'], $config['values']['public_key'], 'the environment public key is used');
    assert_same($keys['private'], $config['values']['private_key'], 'the environment private key is used');
    assert_same('environment', $config['source']['public_key'], 'the public key is reported as managed');
    assert_true((bool) $config['values']['deliverable'], 'a full environment keypair is deliverable');

    // Nothing was written to the database: the environment owns the pair.
    $stored = wallos_get_instance_settings($db, 'webpush');
    assert_true(empty($stored['vapid_public_key']), 'the environment keypair is never written back to the database');

    putenv('WALLOS_VAPID_PUBLIC_KEY');
    putenv('WALLOS_VAPID_PRIVATE_KEY');
    $db->close();
});

/* -------------------------------------------------------------------------
   Subscription storage: store / replace / delete / ownership
   ------------------------------------------------------------------------- */

wallos_test('a subscription is stored and read back for its owner only', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    assert_true(wallos_webpush_store_subscription($db, 1, 'https://push.example/alice-1', wallos_webpush_test_p256dh(), wallos_webpush_test_auth()),
        'alice stores her first device');
    assert_true(wallos_webpush_store_subscription($db, 1, 'https://push.example/alice-2', wallos_webpush_test_p256dh(), wallos_webpush_test_auth()),
        'alice stores a second device');
    assert_true(wallos_webpush_store_subscription($db, 2, 'https://push.example/bob-1', wallos_webpush_test_p256dh(), wallos_webpush_test_auth()),
        'bob stores his device');

    $alice = wallos_webpush_user_subscriptions($db, 1);
    $bob = wallos_webpush_user_subscriptions($db, 2);

    assert_same(2, count($alice), 'alice has two devices');
    assert_same(1, count($bob), 'bob has one');
    assert_same('https://push.example/bob-1', $bob[0]['endpoint'], "bob only ever sees his own");

    $aliceEndpoints = [$alice[0]['endpoint'], $alice[1]['endpoint']];
    assert_true(!in_array('https://push.example/bob-1', $aliceEndpoints, true), "alice never sees bob's subscription");

    $db->close();
});

wallos_test('an endpoint cannot be taken over by an account that only knows its address', function () {
    // The endpoint is unique across the table, so a second account storing the
    // same string used to move the row: alice's phone stopped being notified
    // and every notification for that device went to the other account. The
    // endpoint is not a secret — it travels to the push service on every send,
    // it is in the browser's own storage, and it is in a database backup.
    //
    // What is not shared is the p256dh: the browser generated that key pair for
    // this subscription and hands it out through getSubscription(). So the row
    // changes hands only for a request that can show it — which the shared
    // family browser can, and somebody who merely learned the address cannot.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'mallory');

    $endpoint = 'https://push.example/alice-phone';
    $aliceKey = wallos_webpush_test_p256dh();
    $auth = wallos_webpush_test_auth();

    assert_true(wallos_webpush_store_subscription($db, 1, $endpoint, $aliceKey, $auth), 'alice subscribes her phone');

    // A different key means the request never held this subscription.
    $otherKey = wallos_webpush_b64u_encode("\x04" . str_repeat("\x07", 64));
    assert_true(!wallos_webpush_store_subscription($db, 2, $endpoint, $otherKey, $auth),
        'the takeover is refused, and refused out loud rather than reported as a save');
    assert_same(1, count(wallos_webpush_user_subscriptions($db, 1)), 'alice still has her phone');
    assert_same(0, count(wallos_webpush_user_subscriptions($db, 2)), 'mallory got nothing');

    // The shared browser: the same device, the same getSubscription() object,
    // a second household account signing in. That one is a real move.
    assert_true(wallos_webpush_store_subscription($db, 2, $endpoint, $aliceKey, $auth),
        'the same subscription presented with its own key does move');
    assert_same(0, count(wallos_webpush_user_subscriptions($db, 1)), 'the device left alice');
    assert_same(1, count(wallos_webpush_user_subscriptions($db, 2)), 'and arrived at the account now using it');

    $db->close();
});

wallos_test('re-subscribing the same endpoint replaces the row rather than duplicating it', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $endpoint = 'https://push.example/device';
    wallos_webpush_store_subscription($db, 1, $endpoint, 'first-p256dh', 'first-auth');
    wallos_webpush_store_subscription($db, 1, $endpoint, 'second-p256dh', 'second-auth');

    $subscriptions = wallos_webpush_user_subscriptions($db, 1);
    assert_same(1, count($subscriptions), 'the same endpoint is one row, not two');
    assert_same('second-p256dh', $subscriptions[0]['p256dh'], 'the keys are refreshed to the latest subscription');
    assert_same('second-auth', $subscriptions[0]['auth'], 'including the auth secret');

    $db->close();
});

wallos_test('deleting a subscription is scoped to its owner', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    wallos_webpush_store_subscription($db, 1, 'https://push.example/alice', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());

    // Bob cannot delete alice's subscription.
    wallos_webpush_delete_by_endpoint($db, 2, 'https://push.example/alice');
    assert_same(1, count(wallos_webpush_user_subscriptions($db, 1)), "another user's delete does not remove it");

    // The owner can.
    assert_true(wallos_webpush_delete_by_endpoint($db, 1, 'https://push.example/alice'), 'the owner deletes by endpoint');
    assert_same(0, count(wallos_webpush_user_subscriptions($db, 1)), 'the subscription is gone');

    $db->close();
});

/* -------------------------------------------------------------------------
   Dispatch: select a user's subscriptions, send, and clean up on 404/410 Gone
   ------------------------------------------------------------------------- */

wallos_test('a send that gets 410 Gone deletes the stale subscription while a live one is kept', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // Public IP-literal endpoints so the SSRF check passes without DNS. The stub
    // decides the status from the path; nothing leaves the process.
    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/gone', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    wallos_webpush_store_subscription($db, 1, 'https://1.1.1.1/push/live', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());

    $GLOBALS['wallos_webpush_test_http'] = function ($url) {
        $status = strpos($url, '/gone') !== false ? 410 : 201;
        return ['response' => $status === 410 ? 'gone' : '', 'status' => $status, 'error' => ''];
    };

    $subscriptions = wallos_webpush_user_subscriptions($db, 1);
    assert_same(2, count($subscriptions), 'both devices are selected for the user');

    $sent = 0;
    $cleaned = 0;
    foreach ($subscriptions as $subscription) {
        $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 'Wallos', 'body' => 'renewal']), 1);
        if ($result['sent']) {
            $sent++;
        } elseif ($result['expired']) {
            wallos_webpush_delete_by_endpoint($db, 1, $subscription['endpoint']);
            $cleaned++;
        }
    }

    assert_same(1, $sent, 'the live device received the push');
    assert_same(1, $cleaned, 'the gone device was cleaned up');

    $remaining = wallos_webpush_user_subscriptions($db, 1);
    assert_same(1, count($remaining), 'exactly one subscription remains');
    assert_same('https://1.1.1.1/push/live', $remaining[0]['endpoint'], 'the surviving one is the live device');

    $GLOBALS['wallos_webpush_test_http'] = null;
    $db->close();
});

wallos_test('a 404 Not Found is treated as gone, and a 5xx is a retryable failure', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/x', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $GLOBALS['wallos_webpush_test_http'] = function () {
        return ['response' => 'not found', 'status' => 404, 'error' => ''];
    };
    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true($result['expired'], '404 marks the subscription expired');
    assert_true(!$result['sent'], 'and not sent');

    $GLOBALS['wallos_webpush_test_http'] = function () {
        return ['response' => 'server error', 'status' => 500, 'error' => ''];
    };
    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true(!$result['expired'], '500 does not delete the subscription');
    assert_true(!$result['sent'], 'and is reported as a failure to retry');

    $GLOBALS['wallos_webpush_test_http'] = null;
    $db->close();
});

wallos_test('a push to a private endpoint is refused by the SSRF check before any send', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_store_subscription($db, 1, 'http://127.0.0.1/push/internal', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $reached = false;
    $GLOBALS['wallos_webpush_test_http'] = function () use (&$reached) {
        $reached = true;
        return ['response' => '', 'status' => 201, 'error' => ''];
    };

    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true(!$result['sent'], 'a loopback endpoint is not delivered to');
    assert_true(!$reached, 'the SSRF check blocks the request before the network is touched');

    $GLOBALS['wallos_webpush_test_http'] = null;
    $db->close();
});

wallos_test('the send goes to the address the SSRF check approved, not to a second lookup', function () {
    // Checking a name and then connecting by name is two lookups, and a name
    // the sender controls can answer differently the second time: public for
    // the check, 169.254.169.254 for the connection. Every endpoint here comes
    // from a client, so the gap is reachable — the fix is to connect to the
    // address that was checked, which is what the pin handed to curl does.
    //
    // A literal address is used so the case decides nothing on DNS: it makes
    // the wiring the subject, which is the part this tree owns.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $endpoint = 'https://93.184.216.34/push/device';
    wallos_webpush_store_subscription($db, 1, $endpoint, wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $seen = null;
    $GLOBALS['wallos_webpush_test_http'] = function ($url, $body, $headers, $resolve) use (&$seen) {
        $seen = $resolve;
        return ['response' => '', 'status' => 201, 'error' => ''];
    };

    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true($result['sent'], 'a public endpoint is delivered to');

    $approved = is_url_safe_for_ssrf($endpoint, $db, 1);
    assert_true(is_array($approved), 'the check approved it');
    assert_same($approved['host'] . ':' . $approved['port'] . ':' . $approved['ip'], $seen,
        'the transport is pinned to exactly the host, port and address the check returned');

    $GLOBALS['wallos_webpush_test_http'] = null;
    $db->close();
});

wallos_test('the transport applies the pin it is handed', function () {
    // The case above holds that deliver() computes the pin and passes it on.
    // Whether curl is actually told to use it cannot be reached from here: the
    // transport is the one function this suite replaces, so that the delivery
    // and 410-cleanup cases can run without a socket. The stub would answer for
    // a wallos_webpush_http_post() that had dropped the option entirely.
    //
    // So this half is read off the source instead of run. It is a weaker test
    // and it is stated as one — it catches the line being deleted, which is the
    // realistic way this protection would be lost, and not a subtler mistake.
    $source = file_get_contents(WALLOS_ROOT . '/includes/webpush.php');
    $transport = substr($source, (int) strpos($source, 'function wallos_webpush_http_post'));
    $transport = substr($transport, 0, (int) strpos($transport, "\n    }\n}"));

    assert_true(strpos($transport, 'CURLOPT_RESOLVE') !== false,
        'the transport sets CURLOPT_RESOLVE');
    assert_true(preg_match('/curl_setopt\(\$ch,\s*CURLOPT_RESOLVE,\s*\[\$resolve\]\)/', $transport) === 1,
        'and sets it to the pin it was given, not to something it worked out itself');
    assert_true(strpos($transport, 'CURLOPT_FOLLOWLOCATION') === false,
        'and does not follow redirects, which would leave the pinned address behind');
});

/* -------------------------------------------------------------------------
   The dispatch's own gate: a user with a subscription is "with notifications"
   ------------------------------------------------------------------------- */

wallos_test('a stored subscription puts the user in the notification dispatch set', function () {
    require_once WALLOS_ROOT . '/includes/notification_settings.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    // Bob has no channel of any kind; alice has one subscribed device.
    wallos_webpush_store_subscription($db, 1, 'https://push.example/alice', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());

    $settings = wallos_load_notification_settings($db);
    $users = wallos_users_with_notifications($settings, $db);

    assert_true(isset($users[1]), 'the account with a subscription is included');
    assert_true(!isset($users[2]), 'the account with none is not');

    $db->close();
});
