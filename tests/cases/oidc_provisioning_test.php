<?php
/*
  Which language an OIDC-provisioned account starts with.

  From the 2026-08-16 test run: an account created through authentik came out
  as English on an otherwise German instance, because the provisioning code
  hardcoded 'en'.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/**
 * The resolution the provisioning code performs, isolated so it can be checked
 * without an identity provider: the claim first, the instance default when the
 * claim is absent or unsupported.
 *
 * @param SQLite3     $db
 * @param string|null $localeClaim
 * @return string
 */
function oidc_provisioning_language($db, $localeClaim)
{
    return wallos_resolve_language($localeClaim, wallos_instance_default_language($db));
}

wallos_test('the provider locale decides the language of a new account', function () {
    $db = wallos_test_open_database();

    assert_same('de', oidc_provisioning_language($db, 'de-DE'), 'de-DE becomes de');
    assert_same('pt-BR', oidc_provisioning_language($db, 'pt-BR'), 'pt-BR is kept');
    assert_same('fr', oidc_provisioning_language($db, 'fr-CA'), 'fr-CA becomes fr');
    assert_same('ja', oidc_provisioning_language($db, 'ja-JP'), 'ja-JP becomes ja');

    $db->close();
});

wallos_test('without a usable claim the instance default applies', function () {
    $db = wallos_test_open_database();
    putenv('WALLOS_DEFAULT_LANGUAGE=de');

    assert_same('de', oidc_provisioning_language($db, null), 'no claim at all');
    assert_same('de', oidc_provisioning_language($db, ''), 'an empty claim');
    assert_same('de', oidc_provisioning_language($db, 'kl-GL'), 'a language Wallos does not have');

    $db->close();
});

wallos_test('with neither claim nor default the account is English', function () {
    $db = wallos_test_open_database();

    assert_same('en', oidc_provisioning_language($db, null), 'the final fallback');

    $db->close();
});

wallos_test('the locale is read once, at creation', function () {
    // The claim must not reach the update path: after the account exists, the
    // language belongs to the Wallos user, and a provider that reports a
    // different locale on the next login must not silently change it.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_create_user.php');
    assert_contains("\$userInfo['locale']", $source, 'the creation path reads the claim');

    $login = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_login.php');
    assert_not_contains("locale", $login, 'the login path does not touch it');

    $callback = file_get_contents(WALLOS_ROOT . '/includes/oidc/handle_oidc_callback.php');
    assert_not_contains("UPDATE user SET language", $callback, 'nothing updates the language on login');
});
