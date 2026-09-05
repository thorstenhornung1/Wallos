<?php
/*
  The unauthenticated back-channel endpoint, and the cost an anonymous caller
  can impose on it (findings F2 and P2).

  F2: the endpoint's only defence is the token's signature, so verifying one
  means fetching the provider's signing keys. Before this, that fetch ran on
  every anonymous POST, uncached and before any cheap validation — a DoS lever
  and an amplification path. Two things fix it: a cheap pre-filter that rejects
  obvious junk (wrong issuer, stale or missing iat, no logout event) before any
  fetch, and a cache in front of the JWKS so a token that clears the pre-filter
  costs at most one fetch per TTL.

  The pre-filter is NOT a trust decision. Nothing that passes it is acted on; the
  signature is still verified first, before any claim is trusted. These cases
  hold both lines at once: junk is refused without a network touch, and a
  well-formed token still validates all the way through.

  P2: the JWKS and discovery fetches now route through the same SSRF allowlist
  (validate_oidc_endpoint_url) the token, userinfo and refresh fetches use. The
  URLs derive from the operator-configured issuer, so this is defence in depth;
  a disallowed address is refused rather than fetched.
*/

require_once WALLOS_ROOT . '/includes/oidc/jwt.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * A signing key pair plus its JWKS, made once and reused.
 *
 * @return array{private: mixed, jwks: array}
 */
function bcdos_key()
{
    static $key = null;

    if ($key === null) {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($resource);
        $b64 = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $key = [
            'private' => $resource,
            'jwks' => ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'wallos-dos',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $b64($details['rsa']['n']),
                'e' => $b64($details['rsa']['e']),
            ]]],
        ];
    }

    return $key;
}

/**
 * A logout token signed the way the provider signs one.
 *
 * @param array $overrides claims to override on the well-formed base
 * @return string
 */
function bcdos_token($overrides = [])
{
    $b64 = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $claims = array_merge([
        'iss' => 'https://auth.dos.example.com',
        'aud' => 'wallos-dos-client',
        'iat' => time(),
        'jti' => uniqid('', true),
        'events' => [WALLOS_BACKCHANNEL_LOGOUT_EVENT => new stdClass()],
        'sub' => 'dos-subject-1',
        'sid' => 'dos-session-1',
    ], $overrides);

    $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'wallos-dos']))
        . '.' . $b64(json_encode($claims));
    openssl_sign($input, $signature, bcdos_key()['private'], OPENSSL_ALGO_SHA256);

    return $input . '.' . $b64($signature);
}

/**
 * The expectations the endpoint would hand the validator.
 *
 * @return array
 */
function bcdos_expectations()
{
    return ['issuer' => 'https://auth.dos.example.com', 'audience' => 'wallos-dos-client'];
}

/**
 * Primes a fresh JWKS cache entry so a fetch is never needed.
 *
 * @param WallosDatabase $db
 * @param string  $jwksUri
 * @param int     $age seconds old
 */
function bcdos_prime_jwks($db, $jwksUri, $age = 0)
{
    $stmt = $db->prepare('INSERT INTO oidc_jwks_cache (jwks_uri, document, fetched_at)
                          VALUES (:uri, :document, :fetchedAt)');
    $stmt->bindValue(':uri', $jwksUri);
    $stmt->bindValue(':document', json_encode(bcdos_key()['jwks']));
    $stmt->bindValue(':fetchedAt', time() - $age);
    $stmt->execute();
}

// ---------------------------------------------------- the pre-filter, in itself

wallos_test('the pre-filter refuses junk without needing keys or a database', function () {
    $now = 1000000;
    $issuer = 'https://auth.dos.example.com';

    assert_same('malformed_token',
        wallos_oidc_logout_token_prefilter('not-a-jwt', $issuer, $now), 'garbage');
    assert_same('wrong_issuer',
        wallos_oidc_logout_token_prefilter(bcdos_token(['iss' => 'https://evil.example.com', 'iat' => $now]), $issuer, $now),
        'a different issuer');
    assert_same('token_too_old',
        wallos_oidc_logout_token_prefilter(bcdos_token(['iat' => $now - 4000]), $issuer, $now),
        'a stale token');
    assert_same('issued_in_the_future',
        wallos_oidc_logout_token_prefilter(bcdos_token(['iat' => $now + 4000]), $issuer, $now),
        'one from the future');

    $noEvent = bcdos_token(['iat' => $now, 'events' => []]);
    assert_same('not_a_logout_event',
        wallos_oidc_logout_token_prefilter($noEvent, $issuer, $now), 'no logout event');

    // The positive control: a well-formed token clears the filter, so a real
    // one is never rejected before it can be verified.
    assert_true(
        wallos_oidc_logout_token_prefilter(bcdos_token(['iat' => $now]), $issuer, $now) === null,
        'a well-formed token is allowed through to verification');
});

// -------------------------------------- authorize: rejected before any fetch

wallos_test('a junk token is rejected before the key fetch is even attempted', function () {
    // The jwks_uri is a loopback with no cache entry: if the fetch path were
    // reached at all it would refuse the address and answer jwks_unavailable.
    // That the error is instead the pre-filter's own (wrong_issuer) is the proof
    // that verification was abandoned before the fetch — no cache read, no SSRF
    // check, no transport call.
    $db = wallos_test_open_database();

    $verdict = wallos_oidc_authorize_logout_token(
        $db,
        bcdos_token(['iss' => 'https://evil.example.com']),
        'http://127.0.0.1:9/jwks',
        bcdos_expectations(),
        time()
    );

    assert_true(!$verdict['valid'], 'refused');
    assert_same('wrong_issuer', $verdict['error'],
        'the pre-filter refused it, not the fetch (which would say jwks_unavailable)');

    $db->close();
});

wallos_test('a well-formed token validates against the cached keys, no fetch', function () {
    // The cache is fresh, so the fetch path — and with it the SSRF check and the
    // transport — is never entered. The token still validates all the way
    // through, signature first.
    $db = wallos_test_open_database();
    $jwksUri = 'https://auth.dos.example.com/jwks';
    bcdos_prime_jwks($db, $jwksUri);

    $verdict = wallos_oidc_authorize_logout_token(
        $db, bcdos_token(), $jwksUri, bcdos_expectations(), time());

    assert_true($verdict['valid'], 'accepted: ' . ($verdict['error'] ?? ''));
    assert_same('dos-subject-1', $verdict['sub'], 'the subject it names');
    assert_same('dos-session-1', $verdict['sid'], 'and the session');

    $db->close();
});

wallos_test('a forged well-formed token clears the pre-filter but fails verification', function () {
    // The whole point of "pre-filter is not a trust decision": this token has
    // the right issuer, a fresh iat and the logout event, so it passes the cheap
    // filter and a fetch is spent on it — and then the signature check refuses
    // it. A junk filter, not a verifier.
    $db = wallos_test_open_database();
    $jwksUri = 'https://auth.dos.example.com/jwks';
    bcdos_prime_jwks($db, $jwksUri);

    $forger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $claims = [
        'iss' => 'https://auth.dos.example.com',
        'aud' => 'wallos-dos-client',
        'iat' => time(),
        'events' => [WALLOS_BACKCHANNEL_LOGOUT_EVENT => new stdClass()],
        'sub' => 'dos-subject-1',
    ];
    $input = $b64(json_encode(['alg' => 'RS256', 'kid' => 'wallos-dos'])) . '.' . $b64(json_encode($claims));
    openssl_sign($input, $sig, $forger, OPENSSL_ALGO_SHA256);
    $forged = $input . '.' . $b64($sig);

    $verdict = wallos_oidc_authorize_logout_token($db, $forged, $jwksUri, bcdos_expectations(), time());

    assert_same('invalid_signature', $verdict['error'], 'the signature is still the authority');

    $db->close();
});

// ------------------------------------------------------------- the JWKS cache

wallos_test('a fresh cached JWKS is served, a stale one reports itself stale', function () {
    $db = wallos_test_open_database();

    bcdos_prime_jwks($db, 'https://fresh.example.com/jwks', 60);
    $fresh = wallos_oidc_jwks_cache_read($db, 'https://fresh.example.com/jwks');
    assert_true($fresh !== null && $fresh['fresh'], 'a recent entry is fresh');
    assert_true(isset($fresh['document']['keys']), 'and hands back the key set');

    bcdos_prime_jwks($db, 'https://stale.example.com/jwks', WALLOS_OIDC_JWKS_TTL + 60);
    $stale = wallos_oidc_jwks_cache_read($db, 'https://stale.example.com/jwks');
    assert_true($stale !== null && !$stale['fresh'], 'an old entry is present but not fresh');

    $db->close();
});

// -------------------------------------------------------- P2: the SSRF gate

wallos_test('the JWKS fetch refuses a disallowed address without a transport call', function () {
    // No cache, and a loopback jwks_uri: the SSRF allowlist refuses it, so the
    // seam is never reached and there is nothing to fall back to. Loopback is a
    // literal IP, so this resolves nothing and touches no network.
    $db = wallos_test_open_database();

    assert_true(wallos_oidc_fetch_jwks($db, 'http://127.0.0.1:9/jwks') === null,
        'a loopback jwks_uri is refused, not fetched');
    assert_true(wallos_oidc_fetch_jwks($db, 'http://169.254.169.254/jwks') === null,
        'and so is the cloud metadata address');

    // Positive control: the same gate the fetch routes through accepts a public
    // address, so a legitimately configured issuer is not blocked.
    assert_true(validate_oidc_endpoint_url('https://93.184.216.34/jwks', $db) !== false,
        'a public jwks_uri passes the allowlist the fetch now consults');

    $db->close();
});

wallos_test('discovery refuses a disallowed issuer and accepts a public one', function () {
    // P2 for the discovery document. The private issuer is refused by the same
    // gate; the function routes through it rather than fetching blind. Both use
    // literal IPs, so nothing here resolves a name or opens a socket.
    $db = wallos_test_open_database();

    // "not permitted" is the SSRF gate's own wording. A generic transport
    // failure would say "Connection refused", which also contains "refused" —
    // so asserting the gate's phrase is what makes this fail if the routing
    // through validate_oidc_endpoint_url is removed rather than merely if the
    // socket happens to be closed.
    [$document, $error] = wallos_fetch_oidc_discovery_document('http://127.0.0.1', $db);
    assert_true($document === null, 'a loopback issuer yields no document');
    assert_contains('not permitted', (string) $error, 'the SSRF gate refused it, not the socket');

    [$document2, $error2] = wallos_fetch_oidc_discovery_document('http://169.254.169.254', $db);
    assert_true($document2 === null, 'the metadata address is refused too');
    assert_contains('not permitted', (string) $error2, 'by the gate, not by a failed connection');

    // Positive control: the gate the function consults accepts a public issuer.
    assert_true(
        validate_oidc_endpoint_url('https://93.184.216.34/.well-known/openid-configuration', $db) !== false,
        'a public issuer address passes the allowlist discovery now consults');

    $db->close();
});

// --------------------- the transport seam itself, driven through the endpoint

/**
 * Makes the installation look like one configured against the fixture provider,
 * with the JWKS served over https from a public literal IP so the SSRF check
 * passes and the transport seam is the only thing that can reach it. The JWKS
 * cache is deliberately NOT primed, so a well-formed token forces exactly one
 * fetch — which the child records to a marker file.
 *
 * @param WallosDatabase $db
 * @param string  $jwksUri
 */
function bcdos_configure($db, $jwksUri)
{
    $document = json_encode([
        'issuer' => 'https://auth.dos.example.com',
        'jwks_uri' => $jwksUri,
        'authorization_endpoint' => 'https://auth.dos.example.com/auth',
        'token_endpoint' => 'https://auth.dos.example.com/token',
        'userinfo_endpoint' => 'https://auth.dos.example.com/userinfo',
    ]);

    $stmt = $db->prepare('INSERT INTO oidc_discovery_cache (issuer, document, fetched_at)
                          VALUES (:issuer, :document, :fetchedAt)');
    $stmt->bindValue(':issuer', 'https://auth.dos.example.com');
    $stmt->bindValue(':document', $document);
    $stmt->bindValue(':fetchedAt', time());
    $stmt->execute();

    putenv('OIDC_ENABLED=1');
    putenv('OIDC_ISSUER=https://auth.dos.example.com');
    putenv('OIDC_CLIENT_ID=wallos-dos-client');
    putenv('OIDC_AUTH_URL=https://auth.dos.example.com/auth');
    putenv('OIDC_TOKEN_URL=https://auth.dos.example.com/token');
    putenv('OIDC_USERINFO_URL=https://auth.dos.example.com/userinfo');
    putenv('OIDC_REDIRECT_URL=https://wallos.example.com/login.php');
    putenv('OIDC_USER_IDENTIFIER=email');
}

/**
 * POSTs a token to backchannel-logout.php in a child that has replaced the JWKS
 * transport with a counter, so the parent can tell whether a fetch happened.
 * Returns ['body' => string, 'fetches' => int].
 *
 * @param string $token
 * @param string $jwksUri
 * @return array{body: string, fetches: int}
 */
function bcdos_post_counting($token, $jwksUri)
{
    $marker = WALLOS_TEST_TMP . '/bcdos-fetch-' . uniqid('', true);
    $jwksBody = json_encode(bcdos_key()['jwks']);

    $script = WALLOS_TEST_TMP . '/bcdos-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n"
        . 'chdir(' . var_export(WALLOS_ROOT, true) . ');' . "\n"
        // The seam, defined before the endpoint loads so the function_exists
        // guard in backchannel.php leaves it standing. It records the call and
        // returns the fixture keys, so a fetch that happens is both counted and
        // usable; a fetch that never happens leaves the marker absent.
        . 'function wallos_oidc_jwks_http_get($uri, $resolve = null) {' . "\n"
        . '    file_put_contents(' . var_export($marker, true) . ', "1", FILE_APPEND);' . "\n"
        . '    return ["body" => ' . var_export($jwksBody, true) . ', "status" => 200];' . "\n"
        . '}' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
        . '$_POST["logout_token"] = ' . var_export($token, true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/backchannel-logout.php', true) . ';' . "\n");

    $output = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output);
    unlink($script);

    $fetches = is_file($marker) ? strlen(file_get_contents($marker)) : 0;
    @unlink($marker);

    $text = implode("\n", $output);
    $body = preg_match('/\{.*\}/s', $text, $matches) === 1 ? $matches[0] : $text;

    return ['body' => $body, 'fetches' => $fetches];
}

wallos_test('an anonymous junk POST reaches the endpoint but never the key fetch', function () {
    // The finding itself, at the endpoint, with the transport watched. A wrong
    // issuer is refused by the pre-filter, so the outbound fetch an anonymous
    // caller was able to force before is not made at all.
    $db = wallos_test_open_database();
    bcdos_configure($db, 'https://93.184.216.34/jwks');
    $db->close();

    $result = bcdos_post_counting(bcdos_token(['iss' => 'https://evil.example.com']), 'https://93.184.216.34/jwks');

    assert_same(0, $result['fetches'], 'the transport seam was not called');
    assert_contains('invalid_request', $result['body'], 'and the token was refused');
});

wallos_test('the endpoint refuses a private jwks_uri without a transport call (P2)', function () {
    // P2 at the endpoint, break-detecting: the token is well-formed, so it clears
    // the pre-filter and the fetch path is entered — but the jwks_uri is a
    // loopback address. With the SSRF gate in place it is refused before the
    // transport, so the seam is never called and the keys are unavailable. Remove
    // the routing through validate_oidc_endpoint_url and the seam is reached (the
    // marker fills, the token validates), which is exactly what this refuses to
    // let pass.
    $db = wallos_test_open_database();
    bcdos_configure($db, 'http://127.0.0.1:9/jwks');
    $db->close();

    $result = bcdos_post_counting(bcdos_token(), 'http://127.0.0.1:9/jwks');

    assert_same(0, $result['fetches'], 'the SSRF gate refused the address before any transport call');
    assert_contains('invalid_request', $result['body'],
        'so the keys were unavailable and the token was refused');
});

wallos_test('a well-formed POST does reach the key fetch and validates', function () {
    // The mandatory positive control: without it the case above would pass for a
    // guard that simply refuses everything. A real token still fetches the keys
    // (once) and validates through to a revocation count.
    $db = wallos_test_open_database();
    bcdos_configure($db, 'https://93.184.216.34/jwks');
    $db->close();

    $result = bcdos_post_counting(bcdos_token(), 'https://93.184.216.34/jwks');

    assert_true($result['fetches'] >= 1, 'the transport seam was called for a token worth verifying');
    assert_same('{"revoked":0}', $result['body'], 'and the token validated (no session matched)');
});

wallos_test('a fresh cached JWKS spares the endpoint the fetch entirely (F2 cache)', function () {
    // The caching half of F2, break-detecting through the transport counter. With
    // a fresh cache entry a well-formed token is served without the seam being
    // called at all, so an anonymous flood costs at most one fetch per TTL rather
    // than one per request. Remove the cache read and the same token reaches the
    // seam (public jwks_uri, SSRF passes), which the fetch count would catch.
    $db = wallos_test_open_database();
    bcdos_configure($db, 'https://93.184.216.34/jwks');
    bcdos_prime_jwks($db, 'https://93.184.216.34/jwks');
    $db->close();

    $result = bcdos_post_counting(bcdos_token(), 'https://93.184.216.34/jwks');

    assert_same(0, $result['fetches'], 'the fresh cache was served without a transport call');
    assert_same('{"revoked":0}', $result['body'], 'and the token still validated against it');
});
