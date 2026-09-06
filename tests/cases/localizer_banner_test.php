<?php
/*
  Issue #165: the dashboard discovery banner that points a still-default account
  at the Settings localizer #164 added.

  The banner carries no detection of its own. Its whole decision is a thin
  predicate over #164's candidate helper -- show it only while the account still
  has rows the localizer could rename and has not dismissed the offer -- so these
  cases pin exactly the four states the issue names: a still-default non-English
  account sees it, an English account does not, a fully-renamed (no-candidate)
  account does not, and a dismissed account does not. Both backends, because the
  candidate check reads the account's rows through the boundary.
*/

require_once WALLOS_ROOT . '/includes/user_provisioning.php';

wallos_test('the banner is offered to a still-default non-English account', function () {
    // User 1 is the createdatabase.php / PostgreSQL-baseline first admin: its
    // currency and payment-method rows are the untouched English seed, which is
    // exactly the account #164 localizes. In German there are candidates, so the
    // banner is offered.
    $db = wallos_test_open_database();

    assert_true(wallos_default_name_localization_candidates($db, 1, 'de') !== [],
        'the account has localization candidates (precondition)');
    assert_true(wallos_should_offer_default_localization_banner($db, 1, 'de', false),
        'the banner is offered when candidates exist and it is not dismissed');

    $db->close();
});

wallos_test('the banner is not offered to an English account', function () {
    // Localizing to English is meaningless, so the candidate helper returns
    // nothing and the banner never appears -- the "English sees nothing" case.
    $db = wallos_test_open_database();

    assert_same([], wallos_default_name_localization_candidates($db, 1, 'en'),
        'an English account has no candidates (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 1, 'en', false),
        'the banner is not offered to an English account');

    $db->close();
});

wallos_test('the banner is not offered once the account has no candidates left', function () {
    // A fresh account seeded with EUR (a German no-op) and USD ("US Dollar").
    // Rename the one remaining candidate the way a user would, and nothing is
    // left to localize -- the fully-renamed account sees no banner, and it
    // self-suppresses because detection returns none, with no cookie involved.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');
    $stmt = $db->prepare('UPDATE currencies SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', 'My Dollar');
    $stmt->bindValue(':id', $usdId);
    $stmt->execute();

    assert_same([], wallos_default_name_localization_candidates($db, 5001, 'de'),
        'a fully-renamed account has no candidates (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 5001, 'de', false),
        'the banner is not offered when no candidates remain');

    $db->close();
});

wallos_test('a dismissed banner is not offered even while candidates remain', function () {
    // The candidates are still there -- dismissal is the only thing suppressing
    // the offer, and it is remembered in a cookie, not by changing the data.
    $db = wallos_test_open_database();

    assert_true(wallos_default_name_localization_candidates($db, 1, 'de') !== [],
        'candidates still exist (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 1, 'de', true),
        'a dismissed banner is not offered');

    $db->close();
});

wallos_test('the dashboard wires the banner to #164 detection', function () {
    // The banner must actually call the decision helper (which itself calls the
    // #164 candidate detector) and must not have grown detection of its own.
    assert_true(wallos_test_file_calls('index.php', 'wallos_should_offer_default_localization_banner'),
        'index.php calls the banner decision helper');
    assert_true(wallos_test_file_calls('includes/user_provisioning.php', 'wallos_default_name_localization_candidates'),
        'the decision helper reuses the #164 candidate detection');
});
