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
    }
});

wallos_test('the key list and the English translations agree', function () {
    // A key without a translation would seed the key itself as a category name.
    $translations = wallos_translations('en');

    foreach (WALLOS_DEFAULT_CATEGORY_KEYS as $key) {
        assert_true(isset($translations[$key]), $key . ' has an English translation');
    }
});
