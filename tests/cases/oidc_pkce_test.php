<?php
/*
  PKCE (Proof Key for Code Exchange, RFC 7636, S256) on the OIDC
  authorization-code flow (#152).

  The verifier is generated beside the state, its S256 challenge rides the
  authorization request, the verifier itself rides the token exchange, and it is
  consumed in the same breath as the state — one single-use lifecycle.

  The cases drive the pure helpers directly; each breakable one names, in a
  comment, the one-line change that makes it fail.
*/

require_once WALLOS_ROOT . '/includes/oidc/pkce.php';

/**
 * base64url(sha256(verifier)), computed here independently of the helper, so a
 * test comparing the two is comparing two derivations rather than one value
 * against itself.
 */
function pkce_expected_challenge($verifier)
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/**
 * The effective settings a token-exchange helper reads. A confidential client
 * unless $secret is given as ''.
 */
function pkce_settings($secret = 'confidential')
{
    return [
        'client_id' => 'wallos',
        'client_secret' => $secret,
    ];
}

wallos_test('the code verifier is a 43-character base64url string within RFC 7636 range', function () {
    $verifier = wallos_oidc_generate_code_verifier();

    assert_same(43, strlen($verifier), 'a 32-byte value is 43 base64url characters');
    assert_true(strlen($verifier) >= 43 && strlen($verifier) <= 128, 'inside RFC 7636 43-128');
    assert_true((bool) preg_match('/^[A-Za-z0-9_-]+$/', $verifier),
        'base64url alphabet only — no +, /, or = padding');
    assert_true(wallos_oidc_generate_code_verifier() !== $verifier, 'a fresh one each time');
});

wallos_test('the S256 challenge is the base64url sha256 of the verifier', function () {
    // "The stored verifier hashes to it." Break: change wallos_oidc_code_challenge
    // to hash something else (e.g. drop the ', true' so it hashes the hex digest,
    // or use 'sha1') and this equality fails.
    $verifier = wallos_oidc_generate_code_verifier();

    assert_same(pkce_expected_challenge($verifier), wallos_oidc_code_challenge($verifier),
        'challenge == base64url(sha256(verifier))');
    assert_true((bool) preg_match('/^[A-Za-z0-9_-]+$/', wallos_oidc_code_challenge($verifier)),
        'the challenge is itself base64url with no padding');
});

wallos_test('the authorization request carries the challenge and S256, and login.php builds it', function () {
    // The pure round trip: a verifier, its challenge, and the query string the
    // login page assembles from them.
    $verifier = wallos_oidc_generate_code_verifier();
    $challenge = wallos_oidc_code_challenge($verifier);
    $state = bin2hex(random_bytes(16));

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => 'wallos',
        'redirect_uri' => 'https://wallos.example.com/login.php',
        'scope' => 'openid email profile',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    assert_contains('code_challenge=' . $challenge, $query, 'the exact challenge is on the request');
    assert_contains('code_challenge_method=S256', $query, 'as the S256 method');
    assert_contains('state=' . $state, $query, 'and the state is still there');

    // login.php is a full page, so it is checked structurally — but with the
    // tokeniser, which tells a real call from a mention. Break: comment out the
    // two helper calls or the two appended params and these fail.
    assert_true(wallos_test_file_calls('login.php', 'wallos_oidc_generate_code_verifier'),
        'the login page actually generates a verifier');
    assert_true(wallos_test_file_calls('login.php', 'wallos_oidc_code_challenge'),
        'and derives the challenge from it');
    $login = file_get_contents(WALLOS_ROOT . '/login.php');
    assert_contains("'code_challenge_method' => 'S256'", $login, 'the request declares S256');
    assert_contains("\$_SESSION['oidc_code_verifier']", $login, 'and the verifier is bound to the session');
});

wallos_test('the token exchange sends the matching verifier', function () {
    // "The token exchange sends the matching code_verifier." Break: in
    // includes/oidc/pkce.php delete the block that adds $fields['code_verifier'],
    // and the code_verifier key is gone — this fails on the missing/!== value.
    $verifier = wallos_oidc_generate_code_verifier();
    $challenge = wallos_oidc_code_challenge($verifier);

    $fields = wallos_oidc_token_request_fields(pkce_settings(), 'the-auth-code', 'https://wallos.example.com/login.php', $verifier);

    assert_true(isset($fields['code_verifier']), 'a code_verifier is sent');
    assert_same($verifier, $fields['code_verifier'], 'and it is the verifier that was stored');
    assert_same('authorization_code', $fields['grant_type'], 'still an authorization-code exchange');
    assert_same('the-auth-code', $fields['code'], 'carrying the code');

    // Round-trip binding: the verifier that is sent hashes back to the challenge
    // that was presented on the authorization request.
    assert_same($challenge, wallos_oidc_code_challenge($fields['code_verifier']),
        'the sent verifier hashes to the presented challenge');
});

wallos_test('a consumed or absent verifier sends no code_verifier, so a PKCE-ignoring provider still works', function () {
    // The single-use guarantee at the field level: once consume_oidc_callback.php
    // has cleared the session copy, $codeVerifier is null and nothing is sent — a
    // second callback cannot replay a verifier, and a provider that never wanted
    // PKCE still completes the exchange.
    $fields = wallos_oidc_token_request_fields(pkce_settings(), 'code', 'https://wallos.example.com/login.php', null);

    assert_true(!isset($fields['code_verifier']), 'no verifier is sent when there is none');
    assert_same('wallos', $fields['client_id'], 'the exchange is otherwise intact');
    assert_same('confidential', $fields['client_secret'], 'and the confidential client still authenticates');

    // An empty string is treated the same as absent.
    $empty = wallos_oidc_token_request_fields(pkce_settings(), 'code', 'https://wallos.example.com/login.php', '');
    assert_true(!isset($empty['code_verifier']), 'an empty verifier is not sent blank');
});

wallos_test('a public client with no secret still gets a valid PKCE token request', function () {
    $verifier = wallos_oidc_generate_code_verifier();
    $fields = wallos_oidc_token_request_fields(pkce_settings(''), 'code', 'https://wallos.example.com/login.php', $verifier);

    assert_true(!isset($fields['client_secret']), 'an empty secret is omitted, not sent blank');
    assert_same($verifier, $fields['code_verifier'], 'and PKCE stands in for it');
});

wallos_test('the verifier is single-use: consume clears it in lockstep with the state', function () {
    // Behaviour is proven above (a null verifier sends nothing); this pins that
    // consume_oidc_callback.php is what nulls it, on BOTH the failure and success
    // paths, in the very same unset as the state. Break: drop oidc_code_verifier
    // from either unset and the count drops below two.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    assert_contains("\$codeVerifier = \$_SESSION['oidc_code_verifier'] ?? null;", $source,
        'the verifier is read out before the session copy is cleared');
    assert_same(2, substr_count($source, "unset(\$_SESSION['oidc_state'], \$_SESSION['oidc_code_verifier'])"),
        'and cleared with the state on both the failure and success paths');

    // And handle really calls the field builder, so the exchange cannot quietly
    // stop sending the verifier without this failing.
    assert_true(wallos_test_file_calls('includes/oidc/handle_oidc_callback.php', 'wallos_oidc_token_request_fields'),
        'the token exchange is built through the helper that carries the verifier');
});
