<?php
/*
  Discovery from a stored issuer, and the cache in front of it.

  Discovery used to run only when OIDC_ISSUER was an environment variable. An
  installation configured entirely through the admin interface therefore had no
  discovery document, which meant no JWKS — so back-channel logout refused every
  token — and no end_session_endpoint for RP-initiated logout. Nothing in the
  interface suggested a setting was missing.
*/

require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * @param SQLite3 $db
 * @param array   $settings
 */
function discovery_store_settings($db, $settings)
{
    $stmt = $db->prepare("INSERT INTO oauth_settings (id, name, client_id, client_secret,
        authorization_url, token_url, user_info_url, redirect_url, issuer)
        VALUES (1, 'p', 'c', 's', :auth, :token, :info, 'https://wallos.example.com/login.php', :issuer)");
    $stmt->bindValue(':auth', $settings['authorization_url'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':token', $settings['token_url'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':info', $settings['user_info_url'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':issuer', $settings['issuer'] ?? '', SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Puts a document in the cache so the test never makes a network request.
 *
 * @param SQLite3 $db
 * @param string  $issuer
 * @param array   $document
 * @param int     $age seconds old
 */
function discovery_prime_cache($db, $issuer, $document, $age = 0)
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO oidc_discovery_cache (issuer, document, fetched_at)
                          VALUES (:issuer, :document, :fetchedAt)');
    $stmt->bindValue(':issuer', $issuer, SQLITE3_TEXT);
    $stmt->bindValue(':document', json_encode($document), SQLITE3_TEXT);
    $stmt->bindValue(':fetchedAt', time() - $age, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * @return array
 */
function discovery_document()
{
    return [
        'issuer' => 'https://auth.example.com',
        'authorization_endpoint' => 'https://auth.example.com/authorize',
        'token_endpoint' => 'https://auth.example.com/token',
        'userinfo_endpoint' => 'https://auth.example.com/userinfo',
        'jwks_uri' => 'https://auth.example.com/jwks',
        'end_session_endpoint' => 'https://auth.example.com/end-session',
    ];
}

wallos_test('an issuer stored through the interface produces a discovery document', function () {
    // The whole point: the JWKS and the end-session endpoint have to be
    // reachable without an environment variable.
    $db = wallos_test_open_database();
    discovery_store_settings($db, ['issuer' => 'https://auth.example.com']);
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document());

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true(is_array($configuration['discovery_document']), 'a document is present');
    assert_same('https://auth.example.com/jwks', $configuration['discovery_document']['jwks_uri'],
        'so back-channel logout can verify tokens');
    assert_same('https://auth.example.com/end-session',
        $configuration['discovery_document']['end_session_endpoint'],
        'and RP-initiated logout has somewhere to send the user');

    $db->close();
});

wallos_test('a stored issuer fills in endpoints that were left blank', function () {
    $db = wallos_test_open_database();
    discovery_store_settings($db, ['issuer' => 'https://auth.example.com']);
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document());

    $settings = wallos_get_effective_oidc_configuration($db)['settings'];

    assert_same('https://auth.example.com/authorize', $settings['authorization_url'], 'filled in');
    assert_same('https://auth.example.com/token', $settings['token_url'], 'filled in');

    $db->close();
});

wallos_test('a stored issuer does not overwrite an endpoint somebody typed', function () {
    // Some providers need a hand-written endpoint. Discovering one and silently
    // replacing what an operator entered would be a setting that does not stay
    // set, which is worse than not offering it.
    $db = wallos_test_open_database();
    discovery_store_settings($db, [
        'issuer' => 'https://auth.example.com',
        'token_url' => 'https://auth.example.com/special-token',
    ]);
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document());

    $settings = wallos_get_effective_oidc_configuration($db)['settings'];

    assert_same('https://auth.example.com/special-token', $settings['token_url'], 'kept');
    assert_same('https://auth.example.com/authorize', $settings['authorization_url'],
        'while the blank one is still filled in');

    $db->close();
});

wallos_test('an issuer from the environment still wins and locks the fields', function () {
    $db = wallos_test_open_database();
    discovery_store_settings($db, ['issuer' => 'https://stored.example.com']);
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document());
    putenv('OIDC_ISSUER=https://auth.example.com');

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_same('https://auth.example.com', $configuration['settings']['issuer'], 'environment wins');
    assert_same('OIDC_ISSUER', $configuration['managed_fields']['issuer'] ?? null, 'and is marked managed');
    assert_same('OIDC_ISSUER', $configuration['managed_fields']['token_url'] ?? null,
        'as are the endpoints derived from it');

    $db->close();
});

wallos_test('no issuer anywhere means no discovery and no complaint', function () {
    $db = wallos_test_open_database();
    discovery_store_settings($db, ['issuer' => '']);

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true($configuration['discovery_document'] === null, 'nothing discovered');
    foreach ($configuration['notes'] as $note) {
        assert_true(strpos($note, 'Ignoring empty OIDC_ISSUER') === false,
            'and no complaint about a variable nobody set');
    }

    $db->close();
});

// ---------------------------------------------------------------------- cache

wallos_test('a fresh cache entry is used without a network request', function () {
    // If this reached the network the test would hang or fail; that it returns
    // the primed document is the assertion.
    $db = wallos_test_open_database();
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document(), 60);

    [$document, $error] = wallos_get_oidc_discovery_document($db, 'https://auth.example.com');

    assert_true($error === null, 'no error');
    assert_same('https://auth.example.com/jwks', $document['jwks_uri'], 'served from cache');

    $db->close();
});

wallos_test('a stale entry is still served when the provider cannot be reached', function () {
    // Stale beats absent. A provider having a bad minute must not take the login
    // page down with it, and yesterday's endpoints are almost certainly still
    // today's.
    $db = wallos_test_open_database();
    discovery_prime_cache($db, 'https://unreachable.invalid', discovery_document(), 99999);

    [$document, $error] = wallos_get_oidc_discovery_document($db, 'https://unreachable.invalid');

    assert_true($error === null, 'no error surfaced');
    assert_same('https://auth.example.com/jwks', $document['jwks_uri'], 'the old copy is used');

    $db->close();
});

wallos_test('a trailing slash is the same issuer', function () {
    $db = wallos_test_open_database();
    discovery_prime_cache($db, 'https://auth.example.com', discovery_document(), 60);

    [$document] = wallos_get_oidc_discovery_document($db, 'https://auth.example.com/');

    assert_true(is_array($document), 'the cache entry is found');

    $db->close();
});

wallos_test('an empty issuer is refused rather than fetched', function () {
    $db = wallos_test_open_database();

    [$document, $error] = wallos_get_oidc_discovery_document($db, '   ');

    assert_true($document === null, 'nothing');
    assert_true($error !== null, 'and it says so');

    $db->close();
});

wallos_test('the login page does not fetch discovery on every render', function () {
    // wallos_get_effective_oidc_configuration() runs on every login page render.
    // Before the cache, that meant every visitor waited on an HTTP request to
    // the provider, with a ten second timeout when it was unwell.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc_settings.php');

    assert_true(strpos($source, 'wallos_get_oidc_discovery_document($db, $issuer)') !== false,
        'configuration resolution goes through the cached reader');
    assert_true(strpos($source, 'oidc_discovery_cache') !== false, 'which is backed by a table');
});

// ------------------------------------------------------------------ interface

wallos_test('the issuer is editable in the admin interface', function () {
    $admin = file_get_contents(WALLOS_ROOT . '/admin.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/admin.js');
    $endpoint = file_get_contents(WALLOS_ROOT . '/endpoints/admin/saveoidcsettings.php');

    assert_true(strpos($admin, 'oidcIssuer') !== false, 'rendered');
    assert_true(strpos($admin, "oidc_input_attrs('issuer'") !== false,
        'and disabled when the environment manages it');
    assert_true(strpos($script, 'oidcIssuer') !== false, 'submitted');
    assert_true(strpos($endpoint, "'issuer' =>") !== false, 'and saved');
});
