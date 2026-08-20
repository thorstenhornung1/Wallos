<?php
/*
  A new account starts with categories in its own language, created through one
  helper rather than three copies of the same list.
*/

require_once WALLOS_ROOT . '/includes/user_provisioning.php';

wallos_test('categories are created in the language of the account', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // The seeded schema already contains categories; count only the new ones.
    $stmt = $db->prepare('DELETE FROM categories WHERE user_id = 1');
    $stmt->execute();

    assert_true(wallos_create_default_categories($db, 1, 'de'), 'creation succeeds');

    $names = [];
    $stmt = $db->prepare('SELECT name FROM categories WHERE user_id = 1 ORDER BY "order"');
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $names[] = $row['name'];
    }

    assert_same(17, count($names), 'all seventeen categories exist');
    assert_same('Keine Kategorie', $names[0], 'the first one is translated');
    assert_same('Unterhaltung', $names[1], 'and so is the second');
    assert_true(in_array('Cloud-Dienste', $names, true), 'and the last few too');

    $db->close();
});

wallos_test('English is used when a language has no translation yet', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // Japanese has the shared keys but not the category ones.
    $categories = wallos_default_categories('ja');

    assert_same('カテゴリなし', $categories[0], 'an existing key uses the language');
    assert_same('Entertainment', $categories[1], 'a missing one falls back to English, not to nothing');

    $db->close();
});

wallos_test('order is preserved', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare('DELETE FROM categories WHERE user_id = 1');
    $stmt->execute();
    wallos_create_default_categories($db, 1, 'en');

    $stmt = $db->prepare('SELECT name, "order" FROM categories WHERE user_id = 1 ORDER BY "order"');
    $result = $stmt->execute();

    $expectedOrder = 1;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        assert_same($expectedOrder, (int) $row['order'], 'category ' . $expectedOrder . ' keeps its position');
        $expectedOrder++;
    }

    $db->close();
});

wallos_test('every account-creating path uses the shared helper', function () {
    // Three copies of the same list drift; they already differed in shape.
    $paths = [
        'registration.php',
        'endpoints/admin/adduser.php',
        'includes/oidc/oidc_create_user.php',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_contains('wallos_create_default_categories', $source,
            $path . ' seeds categories through the helper');
        assert_not_contains("'Entertainment'", $source,
            $path . ' carries no copy of the list');

        // The currencies and the payment methods were the two lists still
        // written out three times, in the same file as the categories they had
        // already been merged out of. They were identical when merged — 34 and
        // 31 entries in the same order — which is the only comfortable moment
        // to do it.
        assert_contains('wallos_create_default_currencies', $source,
            $path . ' seeds currencies through the helper');
        assert_contains('wallos_create_default_payment_methods', $source,
            $path . ' seeds payment methods through the helper');
        assert_not_contains("'code' => 'BGN'", $source,
            $path . ' carries no copy of the currency list');
        assert_not_contains("icons/paypal.png'", $source,
            $path . ' carries no copy of the payment method list');
    }
});

wallos_test('the seeded lists are the ones the accounts get', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'provisioned');

    // Counted as a difference: the fixture gives every account a currency and a
    // payment method of its own, so an absolute count would be measuring that.
    $currenciesBefore = (int) $db->scalar('SELECT COUNT(*) FROM currencies WHERE user_id = 5001');
    $methodsBefore = (int) $db->scalar('SELECT COUNT(*) FROM payment_methods WHERE user_id = 5001');

    assert_true(wallos_create_default_currencies($db, 5001), 'the currencies are created');
    assert_true(wallos_create_default_payment_methods($db, 5001), 'the payment methods are created');
    assert_true(wallos_create_household_member($db, 5001, 'provisioned'), 'the household member is created');

    assert_same(count(wallos_default_currencies()),
        (int) $db->scalar('SELECT COUNT(*) FROM currencies WHERE user_id = 5001') - $currenciesBefore,
        'every currency in the list reached the account');
    assert_same(count(wallos_default_payment_methods()),
        (int) $db->scalar('SELECT COUNT(*) FROM payment_methods WHERE user_id = 5001') - $methodsBefore,
        'and every payment method');

    // The lookup the three paths use to move the account onto its own currency.
    // Scoped to the owner, because the same code exists once per account.
    $own = wallos_currency_id_for_code($db, 5001, 'CHF');
    assert_true($own > 0, 'the account has its own CHF row');
    assert_same(5001, (int) $db->scalar('SELECT user_id FROM currencies WHERE id = :id', [':id' => $own]),
        'and the lookup found that one rather than somebody else\'s');

    $db->close();
});

wallos_test('a failed write is reported rather than counted as done', function () {
    // The half of issue #87 this file is about: the loops used to discard every
    // execute() result, so an account holding eleven of its thirty-four
    // currencies was reported as fully created.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5002, 'blocked');
    wallos_test_block_writes($db, 'currencies', 'INSERT');

    assert_true(!wallos_create_default_currencies($db, 5002),
        'the helper says the write failed');

    wallos_test_unblock_writes($db, 'currencies');

    // The negative control: the same call succeeds once the block is gone, so
    // the false above is the write failing and not the helper refusing always.
    assert_true(wallos_create_default_currencies($db, 5002),
        'and succeeds when the write can happen');

    $db->close();
});

wallos_test('the key list and the English translations agree', function () {
    // A key without a translation would seed the key itself as a category name.
    $translations = wallos_translations('en');

    foreach (WALLOS_DEFAULT_CATEGORY_KEYS as $key) {
        assert_true(isset($translations[$key]), $key . ' has an English translation');
    }
});
