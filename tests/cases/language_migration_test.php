<?php
/*
  Stored language values are migrated to canonical identifiers, and the ones
  that were already fine are left alone.
*/

require_once WALLOS_ROOT . '/includes/i18n/languages.php';

wallos_test('legacy language values are migrated', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();

    $cases = [
        1 => ['pt_br', 'pt-BR'],
        2 => ['sr_lat', 'sr-Latn'],
        3 => ['zh_cn', 'zh-CN'],
        4 => ['zh_tw', 'zh-TW'],
        5 => ['jp', 'ja'],
        6 => ['de', 'de'],
        7 => ['pt-BR', 'pt-BR'],
    ];

    foreach ($cases as $id => $case) {
        wallos_test_create_user($db, $id, 'user' . $id);
        $stmt = $db->prepare('UPDATE "user" SET language = :language WHERE id = :id');
        $stmt->bindValue(':language', $case[0], SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    require WALLOS_ROOT . '/migrations/000057.php';

    foreach ($cases as $id => $case) {
        $stmt = $db->prepare('SELECT language FROM "user" WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stored = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['language'];

        assert_same($case[1], $stored, $case[0] . ' becomes ' . $case[1]);
    }

    $db->close();
});

wallos_test('every migrated value is one the application supports', function () {
    // The migration and the resolver have to agree, or a user ends up with a
    // language that cannot be loaded.
    $supported = wallos_languages();

    foreach (['pt-BR', 'sr-Latn', 'zh-CN', 'zh-TW', 'ja'] as $canonical) {
        assert_true(isset($supported[$canonical]), $canonical . ' is a supported language');
        assert_same($canonical, wallos_resolve_language($canonical),
            $canonical . ' resolves to itself');
    }
});

wallos_test('the migration can run twice', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare("UPDATE \"user\" SET language = 'zh_tw' WHERE id = 1");
    $stmt->execute();

    require WALLOS_ROOT . '/migrations/000057.php';
    require WALLOS_ROOT . '/migrations/000057.php';

    $stored = $db->querySingle('SELECT language FROM "user" WHERE id = 1');
    assert_same('zh-TW', $stored, 'a second run changes nothing');

    $db->close();
});
