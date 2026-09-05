<?php
/*
  Two spec-level hardenings on the OIDC endpoints (#153):

    1. https is enforced on the endpoints validate_oidc_endpoint_url guards
       (token / userinfo / JWKS / discovery), with a loopback exception for dev.
       OIDC Core requires TLS on the token and userinfo endpoints.
    2. The discovery document's issuer is verified against the configured issuer
       before any endpoint is read from it (OIDC Discovery §4.3, a MUST).

  Each breakable case names, in a comment, the one-line change that makes it
  fail.
*/

require_once WALLOS_ROOT . '/includes/ssrf_helper.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * Configures a stored issuer with blank endpoints and primes the discovery
 * cache, so wallos_get_effective_oidc_configuration reads a document without a
 * socket. prepare + bare bindValue + execute: portable across both backends and,
 * unlike scalar(), it does not re-step the statement (which would run the insert
 * twice); no SQLite type constants, so the boundary audit stays clean.
 *
 * @param WallosDatabase $db
 * @param string $storedIssuer the issuer as configured (may carry a slash)
 * @param string $cacheIssuer  the normalised key discovery looks the cache up by
 * @param array  $document     the discovery document to serve
 */
function tls_prime_discovery($db, $storedIssuer, $cacheIssuer, $document)
{
    $settings = $db->prepare(
        "INSERT INTO oauth_settings
            (id, name, client_id, client_secret, authorization_url, token_url, user_info_url, redirect_url, issuer)
         VALUES (1, 'p', 'c', 's', '', '', '', 'https://wallos.example.com/login.php', :issuer)"
    );
    $settings->bindValue(':issuer', $storedIssuer);
    if ($settings->execute() === false) {
        wallos_test_fail('the fixture could not store the issuer');
    }

    $cache = $db->prepare(
        "INSERT INTO oidc_discovery_cache (issuer, document, fetched_at)
         VALUES (:issuer, :document, :fetchedAt)"
    );
    $cache->bindValue(':issuer', $cacheIssuer);
    $cache->bindValue(':document', json_encode($document));
    $cache->bindValue(':fetchedAt', time());
    if ($cache->execute() === false) {
        wallos_test_fail('the fixture could not prime the discovery cache');
    }
}

/**
 * A well-formed discovery document with a settable issuer.
 */
function tls_discovery_document($issuer)
{
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => 'https://auth.example.com/authorize',
        'token_endpoint' => 'https://auth.example.com/token',
        'userinfo_endpoint' => 'https://auth.example.com/userinfo',
        'jwks_uri' => 'https://auth.example.com/jwks',
    ];
}

// ================================================ 1. https on the endpoints

wallos_test('the loopback helper recognises only literal loopback hosts', function () {
    foreach (['localhost', '127.0.0.1', '127.9.9.9', '::1', '[::1]'] as $local) {
        assert_true(wallos_oidc_endpoint_host_is_loopback($local), $local . ' is loopback');
    }
    foreach (['93.184.216.34', 'idp.example', 'auth.example.com', '0.0.0.0', '169.254.169.254'] as $remote) {
        assert_true(!wallos_oidc_endpoint_host_is_loopback($remote), $remote . ' is not loopback');
    }
});

wallos_test('a plaintext OIDC endpoint is refused, https is accepted, loopback is the dev exception', function () {
    // Break: delete the "$scheme !== 'https' ..." guard in validate_oidc_endpoint_url
    // and the http public endpoint is newly accepted, so the first assertion fails.
    $db = wallos_test_open_database();

    assert_true(validate_oidc_endpoint_url('http://93.184.216.34/token', $db) === false,
        'a plaintext token endpoint on a public host is refused');
    assert_true(validate_oidc_endpoint_url('http://93.184.216.34/userinfo', $db) === false,
        'and so is a plaintext userinfo endpoint');
    assert_true(validate_oidc_endpoint_url('https://93.184.216.34/token', $db) !== false,
        'the same endpoint over TLS is accepted');

    // The dev exception: a developer's loopback provider without a certificate.
    // Loopback is still a reserved address, so it also has to be allowlisted —
    // the scheme exception only stops the TLS rule adding a second barrier.
    putenv('SSRF_ALLOWLIST=127.0.0.1');
    $loopback = validate_oidc_endpoint_url('http://127.0.0.1:8080/token', $db);
    assert_true($loopback !== false, 'a plaintext loopback endpoint is allowed for dev');
    assert_same('127.0.0.1', $loopback['host'], 'and resolves to the loopback host');
    assert_same(8080, $loopback['port'], 'on its port');
    putenv('SSRF_ALLOWLIST');

    $db->close();
});

wallos_test('https does not bypass the reserved-range check', function () {
    // The TLS exception for loopback is about the scheme only. An https loopback
    // endpoint that is not allowlisted is still refused as a reserved address —
    // the SSRF guard is intact, not loosened.
    $db = wallos_test_open_database();

    assert_true(validate_oidc_endpoint_url('https://127.0.0.1/token', $db) === false,
        'https to loopback is still refused unless allowlisted');
    assert_true(validate_oidc_endpoint_url('https://169.254.169.254/token', $db) === false,
        'and the cloud metadata address stays refused over TLS too');

    $db->close();
});

// ============================================= 2. discovery issuer (§4.3)

wallos_test('a discovery document whose issuer matches the configured one is used', function () {
    $db = wallos_test_open_database();
    tls_prime_discovery($db, 'https://auth.example.com', 'https://auth.example.com',
        tls_discovery_document('https://auth.example.com'));

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true(is_array($configuration['discovery_document']), 'the document is accepted');
    assert_same('https://auth.example.com', $configuration['discovery_document']['issuer'], 'issuer as published');
    assert_same('https://auth.example.com/authorize', $configuration['settings']['authorization_url'],
        'and its endpoints fill the blank fields');

    $db->close();
});

wallos_test('a discovery document whose issuer differs is refused', function () {
    // OIDC Discovery §4.3. Break: delete the "if ($documentIssuer !== $configuredIssuer)"
    // branch in oidc_settings.php (read the document unconditionally) and the
    // mismatched endpoints are filled and the document is kept — these fail.
    $db = wallos_test_open_database();
    tls_prime_discovery($db, 'https://auth.example.com', 'https://auth.example.com',
        tls_discovery_document('https://evil.example.com'));

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true($configuration['discovery_document'] === null, 'the document is refused whole');
    assert_same('', $configuration['settings']['authorization_url'],
        'so none of its endpoints are trusted');

    $rejected = false;
    foreach ($configuration['notes'] as $note) {
        if (strpos($note, 'issuer does not match') !== false) {
            $rejected = true;
        }
    }
    assert_true($rejected, 'and the mismatch is reported in the notes');

    $db->close();
});

wallos_test('a trailing slash on the issuer is not counted as a mismatch', function () {
    // Authentik publishes its issuer with a trailing slash; the fork already
    // normalises that when it builds the well-known URL and keys the cache, so
    // the issuer check normalises it too rather than rejecting a correct setup.
    $db = wallos_test_open_database();
    tls_prime_discovery($db, 'https://auth.example.com/', 'https://auth.example.com',
        tls_discovery_document('https://auth.example.com'));

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true(is_array($configuration['discovery_document']),
        'a trailing-slash issuer still matches its slash-free document');
    assert_same('https://auth.example.com/token', $configuration['settings']['token_url'], 'endpoints filled');

    $db->close();
});
