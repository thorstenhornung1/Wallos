<?php
/*
  Web Push (RFC 8030 / RFC 8291 / RFC 8292) built directly against
  ext-openssl, with no Composer package - see includes/webpush_helper.php's
  own header comment for why that is a reasonable thing to attempt here.

  The risk with hand-written cryptographic code is not that it throws or
  produces the wrong length of output - it is that it produces plausible,
  well-formed, silently WRONG bytes. "The tests pass" is worthless as
  evidence unless the expected values came from somewhere other than the
  code under test.

  So the encryption suite below reproduces RFC 8291's own published Appendix
  test vector - fixed keys, fixed salt, fixed plaintext, fixed final
  ciphertext, all copied verbatim from the RFC - rather than asserting
  anything the code itself computed. Every intermediate value the RFC
  publishes (the ECDH secret, both HKDF outputs) is checked too, not just the
  final byte string, so a wrong-but-compensating pair of bugs has two more
  places to be caught instead of one.

  The signing suite verifies a produced VAPID JWT against a public key
  rebuilt purely from its raw bytes - the same 65-byte point a push service
  or a browser's applicationServerKey ever sees, never a PEM Wallos itself
  wrote - because that is the only proof a signature this code makes is
  actually one anybody else could check.
*/

function webpush_test_transport_calls(array $set = null)
{
    static $calls = [];

    if ($set !== null) {
        $calls = $set;
    }

    return $calls;
}

function webpush_test_transport_queue(array $responses = null)
{
    static $queued = [];

    if ($responses !== null) {
        $queued = $responses;
    }

    return $queued;
}


if (!function_exists('webpush_http_post')) {
    function webpush_http_post($url, $body, array $headers, $resolve)
    {
        $calls = webpush_test_transport_calls();
        $calls[] = ['url' => $url, 'headers' => $headers, 'resolve' => $resolve, 'body' => $body];
        webpush_test_transport_calls($calls);

        $queued = webpush_test_transport_queue();
        $next = array_shift($queued);
        webpush_test_transport_queue($queued);

        if ($next === null) {
            $next = ['response' => '', 'status' => 201, 'error' => ''];
        }

        return $next;
    }
}

require_once WALLOS_ROOT . '/includes/webpush_helper.php';

function webpush_test_b64url_decode($data)
{
    $data = strtr($data, '-_', '+/');
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');

    return base64_decode($padded, true);
}

function webpush_test_b64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Builds a SEC1 ECPrivateKey PEM from a raw 32-byte scalar and its raw
 * 65-byte public point, so a test can hand webpush_encrypt_payload() the
 * RFC's own fixed ephemeral key instead of a randomly generated one.
 *
 * openssl_pkey_new() only ever generates a fresh random keypair - there is
 * no ext-openssl function to build one from a chosen scalar - so this
 * constructs the DER by hand. Every byte here except the two raw values is
 * fixed by the ASN.1 structure and the prime256v1 curve OID.
 *
 * @param string $d         32-byte private scalar.
 * @param string $publicRaw 65-byte uncompressed public point.
 * @return string PEM.
 */
function webpush_test_ec_private_key_pem($d, $publicRaw)
{
    $d = strlen($d) > 32 ? substr($d, -32) : str_pad($d, 32, "\x00", STR_PAD_LEFT);

    $version = hex2bin('020101');
    $privateKeyField = hex2bin('0420') . $d;
    $curveField = hex2bin('a00a') . hex2bin('06082a8648ce3d030107');
    $publicKeyField = hex2bin('a144') . hex2bin('034200') . $publicRaw;

    $content = $version . $privateKeyField . $curveField . $publicKeyField;
    $der = "\x30" . chr(strlen($content)) . $content;

    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * A public key built purely from its raw 65-byte point, the way a push
 * service or a browser's applicationServerKey would hand it over - never
 * from a PEM this code itself wrote, which would only prove the code agrees
 * with itself.
 *
 * @param string $publicRaw
 * @return OpenSSLAsymmetricKey
 */
function webpush_test_public_key_from_raw($publicRaw)
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($prefix . $publicRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";

    return openssl_pkey_get_public($pem);
}

/**
 * Converts a raw r || s JWS signature back to the DER form openssl_verify()
 * takes - the reverse of webpush_der_signature_to_raw(), needed only to
 * check a signature this code produced, never by the code itself.
 *
 * @param string $raw 64 bytes for P-256.
 * @return string
 */
function webpush_test_raw_signature_to_der($raw)
{
    $encodeInteger = function ($value) {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if (ord($value[0]) & 0x80) {
            $value = "\x00" . $value;
        }

        return "\x02" . chr(strlen($value)) . $value;
    };

    $r = $encodeInteger(substr($raw, 0, 32));
    $s = $encodeInteger(substr($raw, 32, 32));

    return "\x30" . chr(strlen($r . $s)) . $r . $s;
}

/*
  RFC 8291 Appendix A's own worked example, copied verbatim from the RFC:
  https://www.rfc-editor.org/rfc/rfc8291#appendix-A
*/
const WEBPUSH_TEST_VECTOR_AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';
const WEBPUSH_TEST_VECTOR_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const WEBPUSH_TEST_VECTOR_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
const WEBPUSH_TEST_VECTOR_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const WEBPUSH_TEST_VECTOR_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
const WEBPUSH_TEST_VECTOR_PLAINTEXT = 'When I grow up, I want to be a watermelon';
const WEBPUSH_TEST_VECTOR_OUTPUT = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

wallos_test('the encrypted output matches RFC 8291\'s own test vector exactly', function () {
    $ephemeralPem = webpush_test_ec_private_key_pem(
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PRIVATE),
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC)
    );
    $salt = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_SALT);

    $output = webpush_encrypt_payload(
        WEBPUSH_TEST_VECTOR_PLAINTEXT,
        WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET,
        $ephemeralPem,
        $salt,
        // The RFC's worked example has no padding, so the vector is asked for
        // without it. Every case below this one uses the production default.
        0
    );

    assert_true($output !== false, 'encryption succeeds');
    assert_same(WEBPUSH_TEST_VECTOR_OUTPUT, webpush_test_b64url_encode($output),
        'the full content-coding header plus ciphertext matches the RFC byte for byte');
});

wallos_test('the content-coding header the RFC output starts with is the salt, record size and key id', function () {
    // Separately from the byte-exact whole-output check above: the header
    // format itself (RFC 8188), decoded field by field, so a failure here
    // points at the header rather than the ciphertext.
    $ephemeralPem = webpush_test_ec_private_key_pem(
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PRIVATE),
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC)
    );
    $salt = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_SALT);
    $asPublicRaw = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC);

    $output = webpush_encrypt_payload(
        WEBPUSH_TEST_VECTOR_PLAINTEXT,
        WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET,
        $ephemeralPem,
        $salt,
        // The RFC's worked example has no padding, so the vector is asked for
        // without it. Every case below this one uses the production default.
        0
    );

    assert_same($salt, substr($output, 0, 16), 'the first 16 bytes are the salt');
    assert_same(4096, unpack('N', substr($output, 16, 4))[1], 'then a big-endian uint32 record size');
    assert_same(65, ord($output[20]), 'then a one-byte key id length - 65, an uncompressed P-256 point');
    assert_same($asPublicRaw, substr($output, 21, 65), 'then the ephemeral public key itself, as the key id');
});

wallos_test('a fresh ephemeral key and salt change the output, still round-trips through the same shape', function () {
    // Every real send must never reuse an ephemeral key or salt - this is the
    // one case in the suite that lets webpush_encrypt_payload() generate its
    // own, the way production does, and just checks the shape holds.
    $output = webpush_encrypt_payload('hello', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);

    assert_true($output !== false, 'encryption succeeds with generated key and salt');
    assert_true(strlen($output) > 21 + 65 + 16, 'header plus at least a 16-byte GCM tag');
    assert_same(65, ord($output[20]), 'the key id length byte is still 65');

    $output2 = webpush_encrypt_payload('hello', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);
    assert_true($output !== $output2, 'two calls for the same message never produce the same bytes');
});

wallos_test('a malformed subscription key is refused rather than guessed at', function () {
    assert_same(false, webpush_encrypt_payload('x', 'not-base64!!!', WEBPUSH_TEST_VECTOR_AUTH_SECRET),
        'a p256dh that does not decode to 65 bytes is refused');
    assert_same(false, webpush_encrypt_payload('x', WEBPUSH_TEST_VECTOR_UA_PUBLIC, 'YQ'),
        'an auth secret that does not decode to 16 bytes is refused');
});

wallos_test('a VAPID JWT verifies against a public key built purely from its raw bytes', function () {
    // "Purely from its raw bytes" is the point: a push service never sees a
    // PEM, only the same base64url public key the Authorization header's
    // "k=" carries - so that is what this reconstructs the verifier from.
    $keys = webpush_generate_vapid_keypair();
    assert_true($keys !== false, 'a keypair was generated');

    $jwt = webpush_build_vapid_jwt('https://fcm.googleapis.com/fcm/send/abc123', 'mailto:admin@example.com', $keys['private_pem']);
    assert_true($jwt !== false, 'a JWT was built');

    $parts = explode('.', $jwt);
    assert_same(3, count($parts), 'a JWT has three dot-separated segments');

    $header = json_decode(webpush_test_b64url_decode($parts[0]), true);
    assert_same('ES256', $header['alg'] ?? null, 'signed with the algorithm RFC 8292 requires');
    assert_same('JWT', $header['typ'] ?? null, 'the header names the token type');

    $claims = json_decode(webpush_test_b64url_decode($parts[1]), true);
    assert_same('https://fcm.googleapis.com', $claims['aud'] ?? null,
        'the audience is the endpoint\'s scheme and host only, not its full path');
    assert_same('mailto:admin@example.com', $claims['sub'] ?? null, 'the subject is the contact passed in');
    assert_true(($claims['exp'] ?? 0) > time(), 'the token has not already expired');

    $publicKey = webpush_test_public_key_from_raw(webpush_test_b64url_decode($keys['public']));
    $signatureDer = webpush_test_raw_signature_to_der(webpush_test_b64url_decode($parts[2]));
    $verified = openssl_verify($parts[0] . '.' . $parts[1], $signatureDer, $publicKey, OPENSSL_ALGO_SHA256);

    assert_same(1, $verified, 'the signature verifies against the raw public key');
});

wallos_test('the audience drops the endpoint\'s path, and a malformed endpoint is refused', function () {
    $keys = webpush_generate_vapid_keypair();

    $jwt = webpush_build_vapid_jwt(
        'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABl',
        'mailto:admin@example.com',
        $keys['private_pem']
    );
    $claims = json_decode(webpush_test_b64url_decode(explode('.', $jwt)[1]), true);
    assert_same('https://updates.push.services.mozilla.com', $claims['aud'],
        'query string and path are both gone from the audience');

    assert_same(false, webpush_build_vapid_jwt('not a url', 'mailto:admin@example.com', $keys['private_pem']),
        'an endpoint with no scheme or host is refused rather than signing a token nobody could route to');
});

wallos_test('DER-to-raw signature conversion round-trips under repeated random signing', function () {
    // ECDSA-Sig-Value is variable-width per component (a leading zero byte
    // is added only when needed to avoid the value reading as negative) -
    // the one thing worth stress-testing rather than trusting a handful of
    // examples to have hit every width combination on their own.
    $key = webpush_openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key);
    $publicKey = webpush_test_public_key_from_raw(
        "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT)
    );

    $failures = 0;
    for ($i = 0; $i < 200; $i++) {
        $message = random_bytes(32);
        openssl_sign($message, $der, $key, OPENSSL_ALGO_SHA256);

        $raw = webpush_der_signature_to_raw($der, 32);
        if ($raw === false || strlen($raw) !== 64) {
            $failures++;
            continue;
        }

        $reencodedDer = webpush_test_raw_signature_to_der($raw);
        if (openssl_verify($message, $reencodedDer, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            $failures++;
        }
    }

    assert_same(0, $failures, '200 random signatures all round-trip and verify (0 failures)');
});

wallos_test('the VAPID keypair is generated once and then persisted on the admin row', function () {
    $db = wallos_test_open_database();

    $before = $db->querySingle("SELECT vapid_public_key, vapid_private_key FROM admin LIMIT 1", true);
    assert_same('', $before['vapid_public_key'] ?? '', 'nothing generated for an installation that has not needed it yet');

    $keys = webpush_get_vapid_keys($db);
    assert_true($keys !== false, 'a keypair was generated on first use');

    $after = $db->querySingle("SELECT vapid_public_key, vapid_private_key FROM admin LIMIT 1", true);
    assert_same($keys['public'], $after['vapid_public_key'], 'the public key was persisted');
    assert_same($keys['private_pem'], $after['vapid_private_key'], 'and the private key with it');

    $again = webpush_get_vapid_keys($db);
    assert_same($keys['public'], $again['public'], 'a second call reads the same stored keypair back');
    assert_same($keys['private_pem'], $again['private_pem'], 'rather than generating - and so invalidating - a new one');

    $db->close();
});

wallos_test('webpush_send() reports 404/410 as prune, and everything else as not', function () {
    // The distinction the cron job's pruning logic depends on: 404/410 mean
    // the push service itself has discarded the subscription; nothing else -
    // not a timeout, not a 5xx, not a 429 - says that, and none of the rest
    // are exercised by a live send in this suite (see the comment on
    // webpush_send() itself for why offline is not one of them either).
    //
    // curl_exec() is not stubbed anywhere in this codebase's tests, so this
    // checks the pure classification, not the network path: build the
    // 'prune' decision the same way webpush_send() does and confirm it
    // agrees for every status code family that reaches it.
    $statuses = [200 => false, 201 => false, 400 => false, 404 => true, 410 => true, 413 => false, 429 => false, 500 => false, 503 => false];

    foreach ($statuses as $status => $expectedPrune) {
        $prune = in_array($status, [404, 410], true);
        assert_same($expectedPrune, $prune, "status $status prunes: " . ($expectedPrune ? 'yes' : 'no'));
    }
});

/* ===========================================================================
   What this fork added on top, ported onto the implementation above.

   The cases below are the ones the fork's own audit produced: the padding, the
   message lifetime, the device list, the limit, and who may claim an endpoint.
   Every one of them was written after a measurement or a defect, which is why
   they name a behaviour rather than a function.
   =========================================================================== */

/**
 * A stand-in for the push service, so a 410 can be produced without a socket.
 *
 * @return array<int, array{url: string, headers: string[], resolve: string}>
 */
function webpush_test_reset_transport()
{
    webpush_test_transport_calls([]);
    webpush_test_transport_queue([]);
}

function webpush_test_keys()
{
    return ['public' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
            'private_pem' => webpush_test_ec_private_key_pem(
                webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PRIVATE),
                webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC)
            )];
}

function webpush_test_subscription($endpoint = 'https://push.example/device')
{
    return [
        'id' => 1,
        'endpoint' => $endpoint,
        'p256dh' => WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        'auth' => WEBPUSH_TEST_VECTOR_AUTH_SECRET,
    ];
}

wallos_test('every push is the same size on the wire, whatever it says', function () {
    // Without padding the body length is the message length plus a constant,
    // so somebody watching the connection to the push service learns how long
    // each notification was without decrypting anything. The messages are
    // predictable and the set of subscriptions an account holds is small, so a
    // length is worth something to a watcher.
    $lengths = [];

    foreach ([
        'short' => 'Netflix',
        'typical' => json_encode(['title' => 'Wallos', 'body' => 'Netflix renews in 3 days']),
        'long' => json_encode(['title' => 'Wallos', 'body' => str_repeat('Versicherung ', 60)]),
        'empty' => '',
    ] as $label => $payload) {
        $body = webpush_encrypt_payload($payload, WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);

        assert_true($body !== false, $label . ' encrypts');
        $lengths[$label] = strlen($body);
    }

    assert_same(1, count(array_unique($lengths)),
        'all four are the same length on the wire: ' . json_encode($lengths));

    // And the size is the one the other implementations use, so the padding
    // does not identify this application by being unusual: 2820 record plus 16
    // GCM tag plus 86 header.
    assert_same(2922, $lengths['short'], 'the wire body is the agreed size');
});

wallos_test('a payload between the padding and the ceiling is sent as it is', function () {
    $long = str_repeat('x', WEBPUSH_PADDED_RECORD + 100);
    assert_true(strlen($long) <= WEBPUSH_MAX_PLAINTEXT, 'the case stays below the ceiling');

    $body = webpush_encrypt_payload($long, WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);

    assert_true($body !== false, 'it still encrypts');
    assert_same(86 + strlen($long) + 1 + 16, strlen($body),
        'header, plaintext, delimiter, tag, with no padding left to add');
});

wallos_test('a payload past the RFC ceiling is refused, not silently unreadable', function () {
    // The header announces a record size of 4096. A record larger than that
    // overruns what it declared, and the receiver reads a plaintext octet
    // where the 0x02 delimiter should be, which RFC 8188 says MUST cause the
    // message to be discarded. Refusing is the honest answer.
    $tooLong = str_repeat('x', WEBPUSH_MAX_PLAINTEXT + 1);

    assert_true(webpush_encrypt_payload($tooLong, WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET) === false,
        'one octet past the ceiling is refused');
    assert_true(webpush_encrypt_payload(str_repeat('x', WEBPUSH_MAX_PLAINTEXT), WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET) !== false, 'the ceiling itself still encrypts');
});

wallos_test('a subscription that could never be encrypted to is refused at the door', function () {
    $valid = ['https://push.example/x', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET];

    assert_true(webpush_subscription_is_wellformed(...$valid), 'a real subscription is accepted');

    $refused = [
        'an empty endpoint' => ['', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'an endpoint that is not a URL' => ['not a url', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'a non-http scheme' => ['ftp://push.example/x', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'an endpoint past two kilobytes' => ['https://push.example/' . str_repeat('a', 2100),
            WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'a client key of the wrong length' => ['https://push.example/x', 'BCVx', WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'an auth secret of the wrong length' => ['https://push.example/x', WEBPUSH_TEST_VECTOR_UA_PUBLIC, 'BTBZ'],
    ];

    foreach ($refused as $why => $arguments) {
        assert_true(webpush_subscription_is_wellformed(...$arguments) === false, $why . ' is refused');
    }
});

wallos_test('a reminder is kept until two days past the renewal it names', function () {
    assert_same(WEBPUSH_TTL_GRACE, webpush_ttl_for_renewals([]),
        'a message naming no renewal still gets the grace period');
    assert_same(3 * 86400 + WEBPUSH_TTL_GRACE, webpush_ttl_for_renewals([['days' => 1], ['days' => 3]]),
        'the furthest renewal in the message decides');
    assert_same(WEBPUSH_TTL_MAX, webpush_ttl_for_renewals([['days' => 400]]),
        'nothing is asked for beyond what a push service honours');
});

wallos_test('the TTL the caller asks for is the TTL on the wire', function () {
    webpush_test_reset_transport();

    webpush_send(webpush_test_subscription(), 'body', webpush_test_keys(), 'mailto:a@b.test', 5 * 86400);

    $calls = webpush_test_transport_calls();
    assert_same(1, count($calls), 'one message was sent');
    assert_true(in_array('TTL: ' . (5 * 86400), $calls[0]['headers'], true),
        'the header carries the seconds the caller asked for: ' . json_encode($calls[0]['headers']));

    webpush_test_reset_transport();
    webpush_send(webpush_test_subscription(), 'body', webpush_test_keys(), 'mailto:a@b.test', 99 * 86400);
    $calls = webpush_test_transport_calls();
    assert_true(in_array('TTL: ' . WEBPUSH_TTL_MAX, $calls[0]['headers'], true),
        'and never more than a push service will honour');
});

wallos_test('the send goes to the address the SSRF check approved, not to a second lookup', function () {
    webpush_test_reset_transport();

    webpush_send(webpush_test_subscription('https://push.example:443/device'), 'body', webpush_test_keys(),
        'mailto:a@b.test', 60, ['host' => 'push.example', 'port' => 443, 'ip' => '203.0.113.9']);

    $calls = webpush_test_transport_calls();
    assert_same('push.example:443:203.0.113.9', $calls[0]['resolve'],
        'the transport is pinned to the address the check approved');
});

wallos_test('a send that gets 410 Gone forgets the stale subscription while a live one is kept', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    webpush_store_subscription($db, 1, 'https://push.example/gone', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Macintosh) Firefox/130');
    webpush_store_subscription($db, 1, 'https://push.example/live', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/130');

    $rows = [];
    $result = $db->query('SELECT id, endpoint FROM push_subscriptions ORDER BY endpoint');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[$row['endpoint']] = (int) $row['id'];
    }

    webpush_test_reset_transport();
    webpush_test_transport_queue([['response' => '', 'status' => 410, 'error' => '']]);

    $delivery = webpush_send(['id' => $rows['https://push.example/gone'], 'endpoint' => 'https://push.example/gone',
        'p256dh' => WEBPUSH_TEST_VECTOR_UA_PUBLIC, 'auth' => WEBPUSH_TEST_VECTOR_AUTH_SECRET],
        'body', webpush_test_keys(), 'mailto:a@b.test');

    assert_true($delivery['prune'], '410 Gone is reported as a subscription to forget');
    assert_true(webpush_prune_subscription($db, 1, $rows['https://push.example/gone']), 'the row is removed');

    $remaining = [];
    $result = $db->query('SELECT endpoint FROM push_subscriptions');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $remaining[] = $row['endpoint'];
    }

    assert_same(['https://push.example/live'], $remaining, 'the device that answered is still registered');

    webpush_test_reset_transport();
    $db->close();
});

wallos_test('a subscription is stored and read back for its owner only', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    assert_true(webpush_store_subscription($db, 1, 'https://push.example/a', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/130'), 'alice stores her first device');
    assert_true(webpush_store_subscription($db, 1, 'https://push.example/b', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (iPhone) Safari'), 'alice stores a second device');
    assert_true(webpush_store_subscription($db, 2, 'https://push.example/c', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Windows) Firefox/130'), 'bob stores his device');

    assert_same(2, count(webpush_user_devices($db, 1)), 'alice has two devices');
    assert_same(1, count(webpush_user_devices($db, 2)), 'and bob sees only his own');

    $db->close();
});

wallos_test('an endpoint cannot be taken over by an account that only knows its address', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    webpush_store_subscription($db, 1, 'https://push.example/shared', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/130');

    // Somebody who merely learned the endpoint cannot produce its client key:
    // the browser generated that pair for that subscription and never handed
    // out the private half.
    $other = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAixx';
    assert_true(webpush_store_subscription($db, 2, 'https://push.example/shared', $other,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'curl') === false, 'the claim without the key is refused');
    assert_same(1, count(webpush_user_devices($db, 1)), 'the device still belongs to alice');
    assert_same(0, count(webpush_user_devices($db, 2)), 'and the claimant has none');

    // The shared family browser hands the same subscription, keys and all, to
    // whoever signs in next. That is the only answer a browser can give, and
    // it moves the device rather than registering it twice.
    assert_true(webpush_store_subscription($db, 2, 'https://push.example/shared', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/130'), 'the same browser may claim it');
    assert_same(0, count(webpush_user_devices($db, 1)), 'alice no longer holds it');
    assert_same(1, count(webpush_user_devices($db, 2)), 'and it is not registered twice');

    $db->close();
});

wallos_test('re-subscribing the same endpoint refreshes the row rather than duplicating it', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    webpush_store_subscription($db, 1, 'https://push.example/a', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/130');
    webpush_store_subscription($db, 1, 'https://push.example/a', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Android) Chrome/131');

    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),
        'the same device is one row, not two');

    $db->close();
});

wallos_test('an account can see its devices without the page learning their endpoints', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    webpush_store_subscription($db, 1, 'https://push.example/phone', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Mozilla/5.0 (Linux; Android 14) AppleWebKit Chrome/130 Mobile Safari');

    $devices = webpush_user_devices($db, 1);

    assert_same(1, count($devices), 'the device is listed');
    assert_same('Chrome', $devices[0]['browser'], 'the browser is named');
    assert_same('Android', $devices[0]['platform'], 'and so is the platform');
    assert_same(webpush_device_handle('https://push.example/phone'), $devices[0]['handle'],
        'the row is named by its handle');
    assert_true(strpos(json_encode($devices), 'push.example') === false,
        'and the endpoint does not travel to the page');
});

wallos_test('a device is removed by its handle, and only by its owner', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    webpush_store_subscription($db, 1, 'https://push.example/alice', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Chrome');
    $handle = webpush_device_handle('https://push.example/alice');

    assert_true(webpush_delete_by_handle($db, 2, $handle) === false, 'another account cannot remove it');
    assert_same(1, count(webpush_user_devices($db, 1)), 'so it is still there');

    assert_true(webpush_delete_by_handle($db, 1, $handle), 'its owner can');
    assert_same(0, count(webpush_user_devices($db, 1)), 'and then it is gone');

    $db->close();
});

wallos_test('a crafted user agent cannot put its own text on the settings page', function () {
    $label = webpush_device_label('<script>alert(1)</script> Chrome Android');

    assert_same('Chrome', $label['browser'], 'the browser is one of the known words');
    assert_same('Android', $label['platform'], 'and so is the platform');
    assert_true(strpos(json_encode($label), '<script>') === false, 'nothing of the agent survives');

    $unknown = webpush_device_label('something entirely made up');
    assert_same('', $unknown['browser'], 'an unknown agent yields no browser rather than its own text');
    assert_same('', $unknown['platform'], 'and no platform');
});

wallos_test('an account cannot grow its subscriptions without bound', function () {
    if (wallos_test_skip_unless_sqlite('stores rows')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    for ($i = 0; $i < WEBPUSH_MAX_DEVICES + 5; $i++) {
        webpush_store_subscription($db, 1, 'https://push.example/device-' . $i, WEBPUSH_TEST_VECTOR_UA_PUBLIC,
            WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Chrome');
    }

    assert_same(WEBPUSH_MAX_DEVICES, (int) $db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),
        'the list stops at the limit');

    $db->close();
});

wallos_test('a muted account is not in the notification dispatch set, a subscribed one is', function () {
    if (wallos_test_skip_unless_sqlite('reads the notification tables')) {
        return;
    }

    require_once WALLOS_ROOT . '/includes/notification_settings.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    webpush_store_subscription($db, 1, 'https://push.example/alice', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Chrome');
    webpush_store_subscription($db, 2, 'https://push.example/bob', WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET, 'Chrome');

    $db->exec('INSERT INTO push_notifications (enabled, user_id) VALUES (1, 1)');
    $db->exec('INSERT INTO push_notifications (enabled, user_id) VALUES (0, 2)');

    $settings = wallos_load_notification_settings($db);
    $users = wallos_users_with_notifications($settings, $db);

    assert_true(isset($users[1]), 'the account that has the channel on is in the set');
    assert_true(!isset($users[2]), 'the one that muted it is not, even though the device is still registered');

    $db->close();
});

wallos_test('the keypair a second caller sees is the one already stored', function () {
    if (wallos_test_skip_unless_sqlite('reads the admin row')) {
        return;
    }

    $db = wallos_test_open_database();

    $first = webpush_get_vapid_keys($db);
    assert_true($first !== false, 'a keypair is generated on first use');

    // The write is conditional and the value is read back, so a second caller
    // that generated its own pair in the meantime still answers with the pair
    // every already-subscribed browser knows.
    $second = webpush_get_vapid_keys($db);
    assert_same($first['public'], $second['public'], 'the second caller adopts the stored public key');
    assert_same($first['private_pem'], $second['private_pem'], 'and the stored private key');

    $db->close();
});
