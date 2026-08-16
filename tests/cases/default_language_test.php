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
