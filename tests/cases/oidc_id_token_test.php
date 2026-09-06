<?php
/*
  Phase 3a — the standards-compliant ID-token validator (OIDC Session Authority
  v2, WP2 / §11) and the (issuer, subject) identity binding with UserInfo
  sub-equality (WP3 / §12).

  The ID token is the OIDC authentication assertion, so it is validated the way
  OIDC Core §3.1.3.7 requires. These cases are the Phase-3a matrix:

    A  a normal login's ID token validates fully — signature, algorithm, issuer,
       audience, exp, iat, nonce, sub.
    C  a wrong or missing nonce is rejected.
    D  a wrong issuer, a wrong audience, and a bad signature are each rejected,
       as are alg=none and an HMAC alg (the confusion the allowlist removes).
    E  a UserInfo response whose sub differs from the ID token sub is rejected.

  The signature is exercised against a REAL RSA keypair (tests/bootstrap.php's
  wallos_test_rsa_keypair / wallos_test_sign_jwt, via openssl), not a stub that
  always says yes. Most cases seed the JWKS cache and run in-process — a fresh
  cache means the validator verifies without any network touch. The one case
  that must reach the network (an unknown kid, which forces a single JWKS
  refresh) runs in a subprocess that stands in for the transport through the
  wallos_oidc_jwks_http_get seam, so no case here makes a request.
*/

require_once WALLOS_ROOT . '/includes/oidc/id_token.php';

/** A public IP so validate_oidc_endpoint_url never resolves a name. */
function idtoken_jwks_uri()
{
    return 'https://93.184.216.34/jwks';
}

/** The keypair the in-process cases sign with, generated once per run. */
function idtoken_keypair()
{
    static $keypair = null;
    if ($keypair === null) {
        $keypair = wallos_test_rsa_keypair('idtoken-test-key');
    }

    return $keypair;
}

/** Seeds a fresh JWKS document into the cache so the validator verifies offline. */
function idtoken_seed_jwks($db, $jwksDocument, $jwksUri = null)
{
    $jwksUri = $jwksUri === null ? idtoken_jwks_uri() : $jwksUri;
    $stmt = $db->prepare('INSERT INTO oidc_jwks_cache (jwks_uri, document, fetched_at) VALUES (:uri, :doc, :ts)');
    $stmt->bindValue(':uri', $jwksUri);
    $stmt->bindValue(':doc', json_encode($jwksDocument));
    $stmt->bindValue(':ts', time());
    $stmt->execute();
}

/** A fixed clock, so exp/iat live at known offsets independent of the wall clock. */
function idtoken_now()
{
    return 1700000000;
}

/** Valid base claims for a login ID token, merged with per-case overrides. */
function idtoken_claims($overrides = [])
{
    return array_merge([
        'iss' => 'https://issuer.example',
        'aud' => 'wallos',
        'sub' => 'subject-A',
        'nonce' => 'the-nonce',
        'exp' => idtoken_now() + 300,
        'iat' => idtoken_now(),
    ], $overrides);
}

/** The validator expectations that match idtoken_claims()'s issuer/aud/nonce. */
function idtoken_expectations($overrides = [])
{
    return array_merge([
        'issuer' => 'https://issuer.example',
        'client_id' => 'wallos',
        'nonce' => 'the-nonce',
        'jwks_uri' => idtoken_jwks_uri(),
    ], $overrides);
}

/** Signs a token with the shared keypair (kid pinned), overriding the header when asked. */
function idtoken_sign($claims, $header = [])
{
    $keypair = idtoken_keypair();
    $header = array_merge(['kid' => $keypair['kid']], $header);

    return wallos_test_sign_jwt($keypair['private'], $claims, $header);
}

/** Runs a PHP snippet as its own process against the fixture database. */
function idtoken_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/idtoken-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/** The child body for the JWKS-transport cases: stub the fetch, then validate. */
function idtoken_jwks_child($responses, $token)
{
    return '$GLOBALS["calls"] = 0;' . "\n"
        . '$GLOBALS["responses"] = ' . var_export($responses, true) . ';' . "\n"
        . 'function wallos_oidc_jwks_http_get($uri, $resolve = null) {' . "\n"
        . '    $GLOBALS["calls"]++;' . "\n"
        . '    $next = array_shift($GLOBALS["responses"]);' . "\n"
        . '    return $next === null ? ["body" => false, "status" => 0] : $next;' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/id_token.php', true) . ';' . "\n"
        . '$r = wallos_oidc_validate_id_token($db, ' . var_export($token, true) . ', '
        . var_export(idtoken_expectations(), true) . ', ' . var_export(idtoken_now(), true) . ');' . "\n"
        . 'echo "valid=" . ($r["valid"] ? "yes" : "no") . " error=" . $r["error"] . " calls=" . $GLOBALS["calls"];';
}

// ------------------------------------------------------------------ Test A

wallos_test('Test A: a normal login ID token validates fully', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $token = idtoken_sign(idtoken_claims());
    $result = wallos_oidc_validate_id_token($db, $token, idtoken_expectations(), idtoken_now());

    assert_true($result['valid'], 'a fully-formed, correctly signed ID token validates: ' . (string) $result['error']);
    assert_true($result['signature_verified'], 'and its signature was actually verified, not skipped');
    assert_same('subject-A', $result['claims']['sub'], 'the validated subject is returned');
    assert_same('https://issuer.example', $result['claims']['iss'], 'and the issuer');
    $db->close();
});

// ------------------------------------------------------------------ Test C

wallos_test('Test C: a wrong or missing nonce is rejected', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    // A token minted for another request — a different nonce.
    $wrongNonce = idtoken_sign(idtoken_claims(['nonce' => 'someone-elses-nonce']));
    $result = wallos_oidc_validate_id_token($db, $wrongNonce, idtoken_expectations(), idtoken_now());
    assert_true(!$result['valid'], 'a token whose nonce is not this transaction\'s is refused');
    assert_same('nonce_mismatch', $result['error'], 'named as a nonce mismatch');

    // No nonce at all.
    $noNonceClaims = idtoken_claims();
    unset($noNonceClaims['nonce']);
    $noNonce = idtoken_sign($noNonceClaims);
    $result = wallos_oidc_validate_id_token($db, $noNonce, idtoken_expectations(), idtoken_now());
    assert_true(!$result['valid'], 'a token carrying no nonce is refused');
    assert_same('nonce_mismatch', $result['error'], 'also a nonce mismatch — a missing nonce never matches');
    $db->close();
});

// ------------------------------------------------------------------ Test D

wallos_test('Test D: a wrong issuer is rejected', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $token = idtoken_sign(idtoken_claims(['iss' => 'https://attacker.example']));
    $result = wallos_oidc_validate_id_token($db, $token, idtoken_expectations(), idtoken_now());

    assert_true(!$result['valid'], 'a token from the wrong issuer is refused even when correctly signed');
    assert_same('wrong_issuer', $result['error'], 'named as a wrong issuer');
    $db->close();
});

wallos_test('Test D: a wrong audience is rejected', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $token = idtoken_sign(idtoken_claims(['aud' => 'some-other-client']));
    $result = wallos_oidc_validate_id_token($db, $token, idtoken_expectations(), idtoken_now());

    assert_true(!$result['valid'], 'a token addressed to another client is refused');
    assert_same('wrong_audience', $result['error'], 'named as a wrong audience');

    // The multi-audience form: our client_id must be among them.
    $multi = idtoken_sign(idtoken_claims(['aud' => ['other-a', 'other-b'], 'azp' => 'other-a']));
    $result = wallos_oidc_validate_id_token($db, $multi, idtoken_expectations(), idtoken_now());
    assert_same('wrong_audience', $result['error'], 'a multi-audience token that omits our client_id is refused');
    $db->close();
});

wallos_test('Test D: a bad signature is rejected', function () {
    $db = wallos_test_open_database();
    // The cache holds the real key. The token is signed by a DIFFERENT key but
    // carries the real key's id, so the kid is "known" (no refresh) and the
    // signature simply does not verify.
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $imposter = wallos_test_rsa_keypair('idtoken-test-key'); // same kid, different key
    $token = wallos_test_sign_jwt($imposter['private'], idtoken_claims(), ['kid' => idtoken_keypair()['kid']]);
    $result = wallos_oidc_validate_id_token($db, $token, idtoken_expectations(), idtoken_now());

    assert_true(!$result['valid'], 'a token whose signature does not verify is refused');
    assert_same('invalid_signature', $result['error'], 'named as an invalid signature');
    $db->close();
});

wallos_test('Test D: alg=none and an HMAC alg are refused by the allowlist', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    // alg=none — the classic forgery. Built with a dummy signature segment so it
    // parses and reaches the algorithm check rather than failing as malformed.
    $noneToken = wallos_test_base64url(json_encode(['alg' => 'none', 'typ' => 'JWT']))
        . '.' . wallos_test_base64url(json_encode(idtoken_claims()))
        . '.' . wallos_test_base64url('not-a-real-signature');
    $result = wallos_oidc_validate_id_token($db, $noneToken, idtoken_expectations(), idtoken_now());
    assert_true(!$result['valid'], 'an alg=none token is refused');
    assert_same('unsupported_alg', $result['error'], 'by the algorithm allowlist, before any key work');

    // An HMAC alg — the RSA/HMAC confusion the allowlist removes wholesale.
    $hmacToken = idtoken_sign(idtoken_claims(), ['alg' => 'HS256']);
    $result = wallos_oidc_validate_id_token($db, $hmacToken, idtoken_expectations(), idtoken_now());
    assert_true(!$result['valid'], 'an HS256 token is refused');
    assert_same('unsupported_alg', $result['error'], 'HMAC is not on the allowlist at all');
    $db->close();
});

// -------------------------------------------------------- azp, exp and iat

wallos_test('azp is enforced: multiple audiences need an azp that names this client', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    // Multiple audiences, azp names another client → refused.
    $wrongAzp = idtoken_sign(idtoken_claims(['aud' => ['wallos', 'other'], 'azp' => 'other']));
    $result = wallos_oidc_validate_id_token($db, $wrongAzp, idtoken_expectations(), idtoken_now());
    assert_same('wrong_azp', $result['error'], 'an azp that is not this client is refused');

    // Multiple audiences, no azp at all → refused (cannot tie the token to us).
    $noAzp = idtoken_sign(idtoken_claims(['aud' => ['wallos', 'other']]));
    $result = wallos_oidc_validate_id_token($db, $noAzp, idtoken_expectations(), idtoken_now());
    assert_same('missing_azp', $result['error'], 'multiple audiences with no azp is refused');

    // Multiple audiences, azp names us → accepted.
    $goodAzp = idtoken_sign(idtoken_claims(['aud' => ['wallos', 'other'], 'azp' => 'wallos']));
    $result = wallos_oidc_validate_id_token($db, $goodAzp, idtoken_expectations(), idtoken_now());
    assert_true($result['valid'], 'a correct azp with multiple audiences validates: ' . (string) $result['error']);
    $db->close();
});

wallos_test('an expired token and a future iat are refused', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $expired = idtoken_sign(idtoken_claims(['exp' => idtoken_now() - 3600]));
    $result = wallos_oidc_validate_id_token($db, $expired, idtoken_expectations(), idtoken_now());
    assert_same('expired', $result['error'], 'a token past its exp is refused');

    $future = idtoken_sign(idtoken_claims(['iat' => idtoken_now() + 3600]));
    $result = wallos_oidc_validate_id_token($db, $future, idtoken_expectations(), idtoken_now());
    assert_same('issued_in_the_future', $result['error'], 'a token issued in the future is refused');
    $db->close();
});

wallos_test('a token with an empty subject is refused', function () {
    $db = wallos_test_open_database();
    idtoken_seed_jwks($db, idtoken_keypair()['jwks']);

    $token = idtoken_sign(idtoken_claims(['sub' => '']));
    $result = wallos_oidc_validate_id_token($db, $token, idtoken_expectations(), idtoken_now());
    assert_same('missing_subject', $result['error'], 'a token naming no subject cannot establish identity');
    $db->close();
});

// ------------------------------------------ §11: unknown kid → one refresh

wallos_test('an unknown kid triggers exactly one JWKS refresh, then verifies', function () {
    // The provider rotated its signing key mid-cache-window: the first JWKS the
    // validator sees does not carry the token's kid, and one forced refresh picks
    // up the new keys. Run in a subprocess so the transport can be stood in for.
    $db = wallos_test_open_database();
    $db->close();

    $oldKey = wallos_test_rsa_keypair('old-key');
    $newKey = wallos_test_rsa_keypair('new-key');
    $token = wallos_test_sign_jwt($newKey['private'], idtoken_claims(), ['kid' => 'new-key']);

    // Two transport answers: the stale keyset first, the rotated keyset on the
    // forced refresh.
    $responses = [
        ['body' => json_encode($oldKey['jwks']), 'status' => 200],
        ['body' => json_encode($newKey['jwks']), 'status' => 200],
    ];

    $out = idtoken_run_php(idtoken_jwks_child($responses, $token));

    assert_contains('valid=yes', $out, 'the token verifies once the rotated keys are fetched (' . $out . ')');
    assert_contains('calls=2', $out, 'exactly one initial fetch and one refresh — never a fetch per request');
});

wallos_test('a known kid that fails to verify is NOT refreshed', function () {
    // The mirror of the case above: a kid the cache already carries that still
    // does not verify is a bad signature, and refetching the same keys would only
    // add a network touch to a certain failure.
    $db = wallos_test_open_database();
    $db->close();

    $realKey = wallos_test_rsa_keypair('stable-key');
    $imposter = wallos_test_rsa_keypair('stable-key'); // same kid, different key
    $token = wallos_test_sign_jwt($imposter['private'], idtoken_claims(), ['kid' => 'stable-key']);

    $responses = [['body' => json_encode($realKey['jwks']), 'status' => 200]];

    $out = idtoken_run_php(idtoken_jwks_child($responses, $token));

    assert_contains('valid=no', $out, 'a bad signature under a known kid is refused (' . $out . ')');
    assert_contains('calls=1', $out, 'and no wasted refresh — the kid was already present');
});

// --------------------------------- no discovery: claims still fully enforced

wallos_test('without a jwks_uri the signature is unverifiable but every claim is still enforced', function () {
    // A legacy install with no discovery cannot check the signature, but the
    // validator must not fail open: nonce, issuer, audience, exp, iat and sub are
    // all still enforced, and the result says the signature was not verified.
    $db = wallos_test_open_database();

    $token = idtoken_sign(idtoken_claims());
    $expectations = idtoken_expectations(['jwks_uri' => '']);

    $good = wallos_oidc_validate_id_token($db, $token, $expectations, idtoken_now());
    assert_true($good['valid'], 'the claims validate without a jwks_uri');
    assert_true(!$good['signature_verified'], 'but the result records that the signature was NOT verified');

    $badNonce = idtoken_sign(idtoken_claims(['nonce' => 'wrong']));
    $result = wallos_oidc_validate_id_token($db, $badNonce, $expectations, idtoken_now());
    assert_same('nonce_mismatch', $result['error'], 'a wrong nonce is still refused with no signature to check');
    $db->close();
});

// ------------------------------------------------------------------ Test E

wallos_test('Test E: a UserInfo sub that differs from the ID token sub is rejected', function () {
    // The pure equality helper the callback enforces. OIDC Core §5.3.2 requires
    // the UserInfo sub to equal the ID token sub; a mismatch is a substituted
    // identity and must be refused.
    assert_true(wallos_oidc_userinfo_sub_matches('subject-A', ['sub' => 'subject-A', 'email' => 'a@example.com']),
        'a UserInfo response for the same subject matches');
    assert_true(!wallos_oidc_userinfo_sub_matches('subject-A', ['sub' => 'subject-B']),
        'a UserInfo response for a DIFFERENT subject is rejected (test E)');
    assert_true(!wallos_oidc_userinfo_sub_matches('subject-A', ['email' => 'a@example.com']),
        'a UserInfo response with no sub names nobody and is rejected');
    assert_true(!wallos_oidc_userinfo_sub_matches('subject-A', ['sub' => '']),
        'an empty UserInfo sub is rejected');
    assert_true(!wallos_oidc_userinfo_sub_matches('', ['sub' => '']),
        'an empty ID token sub can never match');
});

// ------------------------------- the login and resume paths wire the validator

wallos_test('the login callback validates the ID token and requires UserInfo sub-equality', function () {
    // As calls (not strpos), because deleting either is exactly the change that
    // would let the callback trust an unvalidated token or a substituted profile
    // with the suite green.
    assert_true(wallos_test_file_calls('includes/oidc/handle_oidc_callback.php', 'wallos_oidc_validate_id_token'),
        'the login callback validates the ID token');
    assert_true(wallos_test_file_calls('includes/oidc/handle_oidc_callback.php', 'wallos_oidc_userinfo_sub_matches'),
        'and requires the UserInfo subject to equal the ID token subject (test E)');

    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/handle_oidc_callback.php');
    assert_contains('oidc_sub = :oidcSub', $source, 'and looks the account up by the OIDC subject');
    assert_contains('issuer = :issuer', $source, 'bound to the issuer that minted it — the (iss, sub) pair (§12)');
});

wallos_test('the resume path validates the ID token through the same WP2 validator', function () {
    assert_true(wallos_test_file_calls('includes/oidc/resume.php', 'wallos_oidc_validate_id_token'),
        'resume runs the returned ID token through the full validator too');
});

// ------------------------------------------------- WP3: the migration and column

wallos_test('the issuer column exists so identity can bind on (iss, sub)', function () {
    $db = wallos_test_open_database();
    assert_true($db->columnExists('user', 'issuer'),
        'migration 000084 adds the issuer column to the user table');
    $db->close();
});
