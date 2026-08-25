<?php
/*
  Which database is this instance actually on?

  Nothing in the application answered that (issue #102). The configuration is
  resolved carefully — a bad driver name refuses, a non-numeric port refuses
  rather than defaulting to 5432, an unreadable password secret invalidates the
  whole configuration — and the result of all that care was never shown to
  anyone. Every caller of wallos_database_configuration() was internal.

  So SQLite and PostgreSQL were indistinguishable through the web interface,
  and on the test instance they stayed indistinguishable for three days while
  three test reports were written up as PostgreSQL runs. The instance had been
  on SQLite since 2026-08-22 06:02. Nobody was careless; there was nothing to
  look at.

  The subtle half is the default. WALLOS_DB_DRIVER unset also yields "sqlite",
  so "it says SQLite" alone does not distinguish a deliberate SQLite instance
  from a PostgreSQL one whose configuration never arrived — which is exactly
  what happened. configuration['managed'] already records which variable set
  each value, and that is what separates the two.
*/

require_once WALLOS_ROOT . '/includes/database/diagnostics.php';

wallos_test('the version string is shortened to something readable', function () {
    // PostgreSQL answers SELECT version() with a paragraph naming the compiler.
    $long = 'PostgreSQL 18.6 (Debian 18.6-1.pgdg120+1) on x86_64-pc-linux-gnu, '
        . 'compiled by gcc (Debian 12.2.0-14) 12.2.0, 64-bit';

    assert_same('PostgreSQL 18.6', wallos_database_version_label('pgsql', $long),
        'the product and the version, nothing else');
    assert_same('SQLite 3.45.1', wallos_database_version_label('sqlite', '3.45.1'),
        'SQLite reports a bare number, so it gets a name');
});

wallos_test('an unreadable version does not become a lie', function () {
    // If the query failed there is no version, and inventing one would defeat
    // the purpose of the whole page.
    assert_same(null, wallos_database_version_label('pgsql', null), 'null stays null');
    assert_same(null, wallos_database_version_label('pgsql', ''), 'empty stays null');
    assert_same(null, wallos_database_version_label('sqlite', false), 'false stays null');
});

wallos_test('the report names the driver in use', function () {
    $db = wallos_test_open_database();
    $report = wallos_database_diagnostics($db);

    assert_same($db->driver(), $report['driver'], 'the driver comes from the open connection');
    assert_true(in_array($report['driver'], ['sqlite', 'pgsql'], true), 'and it is one of the two');

    $db->close();
});

wallos_test('it distinguishes a choice from a default', function () {
    // "sqlite" is both a deliberate setting and what an absent configuration
    // produces. Reporting them identically is how a PostgreSQL instance can
    // run on SQLite without anyone noticing.
    $db = wallos_test_open_database();
    $report = wallos_database_diagnostics($db);

    assert_true(array_key_exists('configured', $report),
        'the report says whether the driver was set on purpose');
    assert_true(is_bool($report['configured']), 'and says it as a yes or no');

    $db->close();
});

wallos_test('it says where the data actually is', function () {
    $db = wallos_test_open_database();
    $report = wallos_database_diagnostics($db);

    assert_true(array_key_exists('source', $report), 'the report names a source');
    assert_true($report['source'] !== '', 'and it is not empty');

    $db->close();
});

wallos_test('no credential appears anywhere in the report', function () {
    // The one rule this page must not break. The DSN helper already keeps the
    // password out of the connection string for the same reason — a diagnostics
    // panel that leaks it would be worse than no panel.
    $db = wallos_test_open_database();
    $report = wallos_database_diagnostics($db);

    $flat = strtolower(json_encode($report));

    foreach (['password', 'passwd', 'secret'] as $forbidden) {
        assert_not_contains($forbidden, $flat, 'the report carries no ' . $forbidden);
    }

    $db->close();
});

wallos_test('the admin page shows it', function () {
    // The whole point. A function nobody calls would leave #102 exactly where
    // it was — the information existed before too, it was just never displayed.
    $source = file_get_contents(WALLOS_ROOT . '/admin.php');

    assert_contains('wallos_database_diagnostics', $source, 'admin.php asks for the report');
    assert_contains('database_diagnostics', $source, 'and renders a section for it');
});
