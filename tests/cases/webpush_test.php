<?php
/*
  Web Push (issue #162), on branch webpush_external: minishlink/web-push v11
  does the cryptography and the transport, so what is left to prove here is
  everything the library is not responsible for.

  What went away, and why:

  * the RFC 8291 §5 known-answer test, the base64url round-trip, the ES256
    signature verification and the "encryption refuses a malformed key" case
    all proved code that no longer exists. The library carries the same RFC
    vector in its own suite. Re-asserting it here would test the dependency,
    not us, and would go green even if Wallos had wired it up wrong.

  * what replaces them is the wiring, which is entirely ours and which a
    known-answer test never covered: that the request actually leaving the
    process carries the aes128gcm content coding (the library still defaults
    to the legacy aesgcm draft when the argument is omitted), a VAPID header
    signed by the instance keypair our configuration layer resolved, our TTL,
    and a body that is not the plaintext.

  What stayed, because the library has no notion of any of it: per-user
  scoping, the SSRF refusal before any send, the 404/410 sweep, and the
  keypair resolution through WALLOS_VAPID_* over the database.
*/

// The library takes a PSR-18 client, so the seam that used to be a function
// call is an object now. Composer's autoloader has to be up before a class in
// this file can implement one of its interfaces.
require_once WALLOS_ROOT . '/vendor/autoload.php';

/**
 * Stands in for the push service. Records every request the library builds, and
 * answers from the handler the case installed in $GLOBALS['wallos_webpush_test_http'].
 * A handler may return a Throwable to make the transport fail instead.
 */
class WallosWebPushTestClient implements \Psr\Http\Client\ClientInterface
{
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $GLOBALS['wallos_webpush_test_requests'][] = $request;

        $handler = $GLOBALS['wallos_webpush_test_http'] ?? null;
        $answer = is_callable($handler)
            ? $handler((string) $request->getUri(), $request)
            : ['status' => 201, 'body' => ''];

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return new \GuzzleHttp\Psr7\Response(
            (int) ($answer['status'] ?? 201),
            [],
            (string) ($answer['body'] ?? '')
        );
    }
}

if (!function_exists('wallos_webpush_http_client')) {
    function wallos_webpush_http_client($safe)
    {
        // The SSRF verdict is not used by the stub, but a case asserts it was
        // computed — a client built without one would mean the check was skipped.
        $GLOBALS['wallos_webpush_test_pinned'] = $safe;

        return new WallosWebPushTestClient();
    }
}

require_once WALLOS_ROOT . '/includes/webpush.php';

// The RFC 8291 §5 receiver key: any valid P-256 point and 16-byte auth secret
// will do, and this pair is a published one that is certainly well formed.
const WALLOS_WEBPUSH_RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const WALLOS_WEBPUSH_RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

function wallos_webpush_test_p256dh()
{
    return WALLOS_WEBPUSH_RFC_UA_PUBLIC;
}

function wallos_webpush_test_auth()
{
    return WALLOS_WEBPUSH_RFC_AUTH;
}

/**
 * Clears what the stub recorded, so a case reads only its own requests.
 */
function wallos_webpush_test_reset_http()
{
    $GLOBALS['wallos_webpush_test_requests'] = [];
    $GLOBALS['wallos_webpush_test_http'] = null;
    $GLOBALS['wallos_webpush_test_pinned'] = null;
}

/**
 * base64url, for reading back the JWT the library signed. Deliberately local to
 * the test: the module no longer has a base64url helper of its own, and one
 * written here cannot accidentally be the thing under test.
 */
function wallos_webpush_test_b64u_decode($text)
{
    $text = strtr((string) $text, '-_', '+/');
    $decoded = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

    return $decoded === false ? '' : $decoded;
}

/* -------------------------------------------------------------------------
   The adapter over the library's key generation
   ------------------------------------------------------------------------- */

wallos_test('a generated VAPID keypair is a raw P-256 point and scalar under the keys the store expects', function () {
    // Not a test of the library's key generation, which is its own business.
    // VAPID::createVapidKeys() returns publicKey/privateKey; everything in
    // Wallos — the stored instance setting, the browser's applicationServerKey —
    // reads public/private. A rename on either side stores empty strings and
    // nothing else notices until a push silently stops going out.
    $keys = wallos_webpush_generate_vapid_keys();

    assert_true($keys !== null, 'a keypair was generated');
    assert_true(isset($keys['public'], $keys['private']), 'under the public/private keys the callers read');
    assert_same(65, strlen(wallos_webpush_test_b64u_decode($keys['public'])), 'the public key is a 65-byte uncompressed point');
    assert_same(32, strlen(wallos_webpush_test_b64u_decode($keys['private'])), 'the private key is a 32-byte scalar');
    assert_same("\x04", substr(wallos_webpush_test_b64u_decode($keys['public']), 0, 1), 'the point is uncompressed');
});

/* -------------------------------------------------------------------------
   Instance VAPID configuration — the resolution order is ours, only the
   generation moved into the library
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
   The wiring: what the library is actually told to send

   This is what replaces the known-answer tests. The library's own suite proves
   its aes128gcm output against RFC 8291; nothing there proves that Wallos asked
   for aes128gcm, signed with the keypair the configuration layer resolved, or
   kept its four-week TTL.
   ------------------------------------------------------------------------- */

wallos_test('the request that leaves carries aes128gcm, our TTL, and a VAPID token signed by the instance keypair', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();

    putenv('WALLOS_VAPID_SUBJECT=mailto:admin@example.com');
    wallos_reset_config_cache($db);
    $config = wallos_get_instance_webpush_config($db);

    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/alice',
        wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $plaintext = json_encode(['title' => 'Wallos', 'body' => 'renewal', 'url' => './']);
    $result = wallos_webpush_deliver($db, $subscription, $plaintext, 1);
    assert_true($result['sent'], 'the push was accepted by the stub push service');

    assert_same(1, count($GLOBALS['wallos_webpush_test_requests']), 'exactly one request was built');
    $request = $GLOBALS['wallos_webpush_test_requests'][0];

    assert_same('POST', $request->getMethod(), 'a push is a POST');
    assert_same('https://8.8.8.8/push/alice', (string) $request->getUri(), 'to the subscription endpoint');

    // The library still defaults to the legacy aesgcm draft coding when the
    // Subscription is built without one. Choosing aes128gcm is ours.
    assert_same('aes128gcm', $request->getHeaderLine('Content-Encoding'),
        'the RFC 8291 content coding is requested explicitly, not the library default');
    assert_same((string) WALLOS_WEBPUSH_TTL, $request->getHeaderLine('TTL'), 'the four-week TTL survives');

    $body = (string) $request->getBody();
    assert_true($body !== '', 'there is a body');
    assert_true(strpos($body, 'renewal') === false, 'the plaintext is not on the wire');

    // The Authorization header is where the instance keypair actually shows up.
    // Anything wrong in the resolution — wrong key, empty subject, the public
    // key not matching the private one — lands here and nowhere else.
    $authorization = $request->getHeaderLine('Authorization');
    assert_true(strpos($authorization, 'vapid t=') === 0, 'the aes128gcm VAPID scheme is used');
    assert_contains('k=' . $config['values']['public_key'], $authorization,
        'the instance public key is the one advertised to the push service');

    $jwt = substr($authorization, strlen('vapid t='), strpos($authorization, ',') - strlen('vapid t='));
    $parts = explode('.', $jwt);
    assert_same(3, count($parts), 'the token is a compact JWS');

    $claims = json_decode(wallos_webpush_test_b64u_decode($parts[1]), true);
    assert_same('mailto:admin@example.com', $claims['sub'] ?? '', 'the resolved subject is the one signed');
    assert_true(($claims['exp'] ?? 0) > time(), 'the token has not already expired');

    // Rebuild the DER OpenSSL verifies from the raw r||s ES256 signature, and
    // check it against the public key the browser was handed.
    $raw = wallos_webpush_test_b64u_decode($parts[2]);
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

    $point = wallos_webpush_test_b64u_decode($config['values']['public_key']);
    $pem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point), 64, "\n")
        . "-----END PUBLIC KEY-----\n";

    $verified = openssl_verify($parts[0] . '.' . $parts[1], $der, openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256);
    assert_same(1, $verified, 'the token is signed by the private half of the instance keypair');

    putenv('WALLOS_VAPID_SUBJECT');
    wallos_webpush_test_reset_http();
    $db->close();
});

wallos_test('the SSRF verdict, not the raw endpoint, is what the transport is pinned to', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();

    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/alice',
        wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);

    // The production client turns this into CURLOPT_RESOLVE, so that the host
    // the check approved is the host curl connects to and a second DNS answer
    // cannot move it. The library would simply hand the URL to curl.
    $pinned = $GLOBALS['wallos_webpush_test_pinned'];
    assert_true(is_array($pinned), 'the client was built from an SSRF verdict');
    assert_same('8.8.8.8', $pinned['host'], 'the approved host is carried to the transport');
    assert_same('8.8.8.8', $pinned['ip'], 'together with the address it resolved to');
    assert_same(443, $pinned['port'], 'and the port that was checked');

    wallos_webpush_test_reset_http();
    $db->close();
});

wallos_test('a subscription the library cannot use is a failed result, not an exception', function () {
    // The self-implemented version returned null for a p256dh that was not a
    // point; the library throws. The cron loops over every device of every
    // account, so a throw here would take out the whole notification run.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();

    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/broken', 'not-a-point', 'not-an-auth-secret');
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);

    assert_true(!$result['sent'], 'the push is reported as not sent');
    assert_true(!$result['expired'], 'and not as a subscription to delete — the row is fine, the keys are not');
    assert_true($result['error'] !== '', 'with a reason the cron can log');
    assert_same(0, count($GLOBALS['wallos_webpush_test_requests']), 'nothing was put on the wire');

    wallos_webpush_test_reset_http();
    $db->close();
});

wallos_test_pending(
    'the VAPID audience keeps a non-default port',
    'minishlink/web-push v11 builds the audience as scheme://host and drops the port '
        . '(WebPush::prepare()), so a push service on a custom port is signed for the wrong '
        . 'origin. The self-implemented version kept it. RFC 8292 §2 names the origin, and '
        . 'RFC 6454 §4 puts the port in it. No public push service is affected; a self-hosted '
        . 'one on :8443 is. There is no seam to fix it through — the audience is computed '
        . 'inside prepare() from the endpoint.',
    function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');
        wallos_webpush_test_reset_http();

        wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8:8443/push/alice',
            wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
        $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

        wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);

        $request = $GLOBALS['wallos_webpush_test_requests'][0];
        $authorization = $request->getHeaderLine('Authorization');
        $jwt = substr($authorization, strlen('vapid t='), strpos($authorization, ',') - strlen('vapid t='));
        $claims = json_decode(wallos_webpush_test_b64u_decode(explode('.', $jwt)[1]), true);

        assert_same('https://8.8.8.8:8443', $claims['aud'] ?? '', 'the audience is the full origin, port included');

        wallos_webpush_test_reset_http();
        $db->close();
    }
);

/* -------------------------------------------------------------------------
   Subscription storage: store / replace / delete / ownership

   None of this exists in the library. Its Subscription is a data class holding
   an endpoint and two keys; it belongs to nobody.
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

wallos_test('a notification for one user never reaches another user\'s device', function () {
    // The scoping the cron depends on, end to end: the send loop takes the
    // subscriptions of one account, so bob's endpoint must never appear among
    // the requests built for alice. The library would send to any endpoint
    // handed to it; choosing which ones is entirely this side of the boundary.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_webpush_test_reset_http();

    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/alice-phone', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/alice-laptop', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    wallos_webpush_store_subscription($db, 2, 'https://8.8.8.8/push/bob-phone', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());

    foreach (wallos_webpush_user_subscriptions($db, 1) as $subscription) {
        wallos_webpush_deliver($db, $subscription, json_encode(['title' => 'for alice']), 1);
    }

    $sentTo = array_map(fn($request) => (string) $request->getUri(), $GLOBALS['wallos_webpush_test_requests']);
    sort($sentTo);

    assert_same(2, count($sentTo), "both of alice's devices were sent to");
    assert_same(['https://8.8.8.8/push/alice-laptop', 'https://8.8.8.8/push/alice-phone'], $sentTo,
        'and only hers');

    wallos_webpush_test_reset_http();
    $db->close();
});

/* -------------------------------------------------------------------------
   Dispatch: send, and clean up on 404/410 Gone

   The library classifies the status (MessageSentReport::isSubscriptionExpired());
   deleting the row is ours, and nothing in the library would do it.
   ------------------------------------------------------------------------- */

wallos_test('a send that gets 410 Gone deletes the stale subscription while a live one is kept', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();

    // Public IP-literal endpoints so the SSRF check passes without DNS. The stub
    // decides the status from the path; nothing leaves the process.
    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/gone', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    wallos_webpush_store_subscription($db, 1, 'https://1.1.1.1/push/live', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());

    $GLOBALS['wallos_webpush_test_http'] = function ($url) {
        $status = strpos($url, '/gone') !== false ? 410 : 201;
        return ['status' => $status, 'body' => $status === 410 ? 'gone' : ''];
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

    wallos_webpush_test_reset_http();
    $db->close();
});

wallos_test('a 404 Not Found is treated as gone, and a 5xx is a retryable failure', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();
    wallos_webpush_store_subscription($db, 1, 'https://8.8.8.8/push/x', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $GLOBALS['wallos_webpush_test_http'] = function () {
        return ['status' => 404, 'body' => 'not found'];
    };
    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true($result['expired'], '404 marks the subscription expired');
    assert_true(!$result['sent'], 'and not sent');
    assert_same(404, $result['status'], 'the status is carried back');

    $GLOBALS['wallos_webpush_test_http'] = function () {
        return ['status' => 500, 'body' => 'server error'];
    };
    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true(!$result['expired'], '500 does not delete the subscription');
    assert_true(!$result['sent'], 'and is reported as a failure to retry');

    // A transport failure — no response at all — is the third shape, and the
    // library reports it as a rejected report rather than letting it escape.
    $GLOBALS['wallos_webpush_test_http'] = function ($url, $request) {
        return new \GuzzleHttp\Exception\ConnectException('connection refused', $request);
    };
    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);
    assert_true(!$result['sent'], 'a dead push service is a failure');
    assert_true(!$result['expired'], 'and not a reason to delete the subscription');
    assert_same(0, $result['status'], 'with no status, because there was no response');

    wallos_webpush_test_reset_http();
    $db->close();
});

wallos_test('a push to a private endpoint is refused by the SSRF check before any send', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_webpush_test_reset_http();
    wallos_webpush_store_subscription($db, 1, 'http://127.0.0.1/push/internal', wallos_webpush_test_p256dh(), wallos_webpush_test_auth());
    $subscription = wallos_webpush_user_subscriptions($db, 1)[0];

    $result = wallos_webpush_deliver($db, $subscription, json_encode(['title' => 't']), 1);

    assert_true(!$result['sent'], 'a loopback endpoint is not delivered to');
    assert_same(0, count($GLOBALS['wallos_webpush_test_requests']),
        'the SSRF check blocks the request before the network is touched');
    assert_true($GLOBALS['wallos_webpush_test_pinned'] === null,
        'no transport was even built for it');

    wallos_webpush_test_reset_http();
    $db->close();
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

/* -------------------------------------------------------------------------
   What vendoring Composer into the webroot costs

   The repository is the deployable artifact, so vendor/ ships inside the
   document root: a few hundred PHP files that were never meant to be entry
   points, under a web server that hands anything matching \.php$ to php-fpm.
   ------------------------------------------------------------------------- */

wallos_test('the Composer tree is present and the web server refuses to serve it', function () {
    assert_true(is_file(WALLOS_ROOT . '/vendor/autoload.php'),
        'vendor/ is committed, because there is no build step for a git clone into the webroot');

    $nginx = file_get_contents(WALLOS_ROOT . '/nginx.conf');

    $deny = strpos($nginx, 'location ^~ /vendor/');
    $handler = strpos($nginx, 'location ~ \.php$');

    assert_true($deny !== false, 'nginx denies the vendor tree by prefix');
    assert_true($handler !== false, 'and still has the php handler');
    assert_true($deny < $handler,
        'the deny comes first — nginx takes the first matching location, so one placed after the '
            . 'php handler would never run');
});
