<?php
/*
  The "set a Fixer API key" warning on Statistics and Calendar (#168).

  The warning used to be gated on whether a fixer row merely existed, which is
  neither necessary nor sufficient: a keyless Frankfurter instance converts
  perfectly with no per-user row, while a fixer row with no key cannot convert
  at all. wallos_show_cant_convert_warning() follows the one signal that already
  knows the difference — the effective currency configuration's validity, the
  same signal the refresh cron gates on — so the warning appears exactly when
  conversion is genuinely unavailable, and never for Frankfurter.

  The helper takes usesMultipleCurrencies as a parameter, so these cases seed
  only the fixer table (and, for the instance case, the currency instance
  settings) and never need subscriptions in more than one currency.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/**
 * Stores one fixer row for a user, the shape the settings page writes.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $provider 0 = fixer.io, 1 = apilayer, 2 = Frankfurter
 * @param string  $apiKey
 */
function cantconvert_fixer_row($db, $userId, $provider, $apiKey)
{
    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                          VALUES (:key, :provider, 'custom', :userId)");
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $stmt->bindValue(':provider', $provider, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

wallos_test('keyless Frankfurter with no fixer row does not warn (#168)', function () {
    // The exact case the old code got wrong: the instance runs Frankfurter,
    // which needs no account, and the user has no fixer row of their own. The
    // old "no row -> warn" check warned here; conversion works, so it must not.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_set_instance_setting($db, 'currency', 'provider', 'frankfurter');

    assert_same(false, wallos_show_cant_convert_warning($db, 1, true),
        'a multi-currency account on keyless Frankfurter is not warned, even with no fixer row');

    $db->close();
});

wallos_test('an explicit Frankfurter row without a key does not warn (#168)', function () {
    // Frankfurter selected outright, its key column empty because it has none.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 2, 'bob');
    cantconvert_fixer_row($db, 2, 2, '');

    assert_same(false, wallos_show_cant_convert_warning($db, 2, true),
        'an explicit keyless Frankfurter provider needs no warning');

    $db->close();
});

wallos_test('a fixer provider with no key warns (#168)', function () {
    // fixer.io needs a key; without one, rates cannot be fetched and a
    // multi-currency account genuinely cannot be converted.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 3, 'carol');
    cantconvert_fixer_row($db, 3, 0, '');

    assert_same(true, wallos_show_cant_convert_warning($db, 3, true),
        'a key-needing provider with no key is warned about');

    $db->close();
});

wallos_test('a fixer provider with a key does not warn (#168)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'dave');
    cantconvert_fixer_row($db, 4, 0, 'abc');

    assert_same(false, wallos_show_cant_convert_warning($db, 4, true),
        'a configured fixer key converts, so nothing is warned about');

    $db->close();
});

wallos_test('a single-currency account is never warned (#168)', function () {
    // The guard the helper keeps from the original: with one currency there is
    // nothing to convert, so an unusable provider is beside the point.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5, 'erin');
    cantconvert_fixer_row($db, 5, 0, ''); // an unusable provider on purpose

    assert_same(false, wallos_show_cant_convert_warning($db, 5, false),
        'a single-currency account is never warned, whatever the provider state');

    $db->close();
});
