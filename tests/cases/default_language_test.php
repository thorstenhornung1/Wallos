<?php
/*
  The language an account is created with when nothing more specific applies.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

wallos_test('without configuration the default is English', function () {
    $db = wallos_test_open_database();

    $config = wallos_get_instance_language_config($db);
    assert_same('en', $config['values']['language'], 'English is the final fallback');
    assert_same('default', $config['source']['language'], 'and it is reported as a default');

    $db->close();
});

wallos_test('the environment sets the default language', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_DEFAULT_LANGUAGE=de');
    $config = wallos_get_instance_language_config($db);

    assert_same('de', $config['values']['language'], 'the configured language is used');
    assert_same('environment', $config['source']['language'], 'its source is reported');
    assert_same('WALLOS_DEFAULT_LANGUAGE', $config['managed_by']['language'], 'and the variable named');

    $db->close();
});

wallos_test('a locale-shaped default resolves to a supported language', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_DEFAULT_LANGUAGE=de-DE');
    assert_same('de', wallos_instance_default_language($db), 'de-DE resolves to de');

    $db->close();
});

wallos_test('an unsupported default is reported, not silently English', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_DEFAULT_LANGUAGE=kl-GL');
    $config = wallos_get_instance_language_config($db);

    assert_same('en', $config['values']['language'], 'it falls back');
    assert_contains('does not support', implode(' ', $config['notes']),
        'and says so, instead of leaving the administrator to wonder');

    $db->close();
});

wallos_test('the database provides the default when the environment does not', function () {
    $db = wallos_test_open_database();

    wallos_set_instance_setting($db, 'instance', 'default_language', 'pt_br');

    $config = wallos_get_instance_language_config($db);
    assert_same('pt-BR', $config['values']['language'], 'a legacy value is canonicalised on read');
    assert_same('admin', $config['source']['language'], 'its source is the database');

    $db->close();
});

wallos_test('the environment wins over the database', function () {
    $db = wallos_test_open_database();

    wallos_set_instance_setting($db, 'instance', 'default_language', 'fr');
    putenv('WALLOS_DEFAULT_LANGUAGE=de');

    assert_same('de', wallos_instance_default_language($db), 'the environment takes precedence');

    $db->close();
});

wallos_test('an account that changes its language takes its default categories with it', function () {
    if (wallos_test_skip_unless_sqlite('renames stored rows')) {
        return;
    }

    require_once WALLOS_ROOT . '/includes/user_provisioning.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_create_default_categories($db, 1, 'en');

    // One she named herself, which no rename may touch.
    $stmt = $db->prepare('UPDATE categories SET name = :name WHERE user_id = 1 AND name = :english');
    $stmt->bindValue(':name', 'Kinder', SQLITE3_TEXT);
    $stmt->bindValue(':english', 'Gaming', SQLITE3_TEXT);
    $stmt->execute();

    $renamed = wallos_localize_default_categories_on_language_change($db, 1, 'en', 'de');
    assert_true($renamed > 0, 'the switch renames the seeded categories');

    $names = [];
    $result = $db->query('SELECT name FROM categories WHERE user_id = 1');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $names[] = $row['name'];
    }

    assert_true(in_array('Unterhaltung', $names, true), 'a seeded category now reads in German');
    assert_true(in_array('Kinder', $names, true), 'and the one she named herself is untouched');
    assert_true(!in_array('Entertainment', $names, true), 'nothing seeded is left in English');

    $db->close();
});

wallos_test('a save that leaves the language alone renames nothing', function () {
    if (wallos_test_skip_unless_sqlite('renames stored rows')) {
        return;
    }

    require_once WALLOS_ROOT . '/includes/user_provisioning.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_create_default_categories($db, 1, 'en');

    assert_same(0, wallos_localize_default_categories_on_language_change($db, 1, 'de', 'de'),
        'the same language twice is not a change');
    assert_same(0, wallos_localize_default_categories_on_language_change($db, 1, 'en', 'en-GB'),
        'and neither is a spelling that resolves to the same file');

    $db->close();
});

wallos_test('the endpoint that saves a language is the one that applies it', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/user/save_user.php');

    assert_contains('wallos_localize_default_categories_on_language_change(', $source,
        'save_user.php applies the change through the guarded helper');
    assert_contains("(string) (\$user['language'] ?? 'en')", $source,
        'and compares against the language on the row, not the cookie');
});
