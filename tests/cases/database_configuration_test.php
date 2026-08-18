<?php
/*
  Choosing a backend.

  Declarative and environment-only: which database Wallos talks to is a
  deployment decision, not something an administrator changes through the web
  interface while the application is running on top of it.

  The rule these cases hold in place: a broken PostgreSQL configuration is an
  error, never a quiet fallback to SQLite. An instance that comes up on the
  wrong database comes up empty, and that is indistinguishable from data loss
  until somebody goes looking.
*/

require_once WALLOS_ROOT . '/includes/database/configuration.php';

wallos_test('the default is SQLite, unchanged', function () {
    // What every existing installation gets after upgrading.
    $configuration = wallos_database_configuration();

    assert_same('sqlite', $configuration['driver'], 'SQLite');
    assert_true($configuration['error'] === null, 'and no complaint');
    assert_true(strpos($configuration['sqlite']['path'], '/db/wallos.db') !== false, 'at the usual place');
});

wallos_test('an unknown driver is refused rather than ignored', function () {
    putenv('WALLOS_DB_DRIVER=mysql');

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] !== null, 'refused');
    assert_true(strpos($configuration['error'], 'mysql') !== false, 'and names what was asked for');
    assert_true(strpos($configuration['error'], 'sqlite, pgsql') !== false, 'and what is available');
});

wallos_test('a complete PostgreSQL configuration resolves', function () {
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');
    putenv('WALLOS_DB_NAME=wallos');
    putenv('WALLOS_DB_USER=wallos');
    putenv('WALLOS_DB_PASSWORD=secret');

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] === null, 'accepted: ' . (string) $configuration['error']);
    assert_same('pgsql', $configuration['driver'], 'the driver');
    assert_same('db.example.com', $configuration['pgsql']['host'], 'the host');
    assert_same(5432, $configuration['pgsql']['port'], 'the default port');
    assert_same('prefer', $configuration['pgsql']['sslmode'], 'the default ssl mode');
});

wallos_test('an incomplete PostgreSQL configuration names what is missing', function () {
    // Not "connection failed" three restarts later.
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] !== null, 'refused');
    assert_true(strpos($configuration['error'], 'WALLOS_DB_NAME') !== false, 'names the database');
    assert_true(strpos($configuration['error'], 'WALLOS_DB_USER') !== false, 'and the user');
    assert_true(strpos($configuration['error'], 'WALLOS_DB_HOST') === false, 'but not what is set');
});

wallos_test('a broken configuration never becomes a silent SQLite fallback', function () {
    // The failure mode this whole file is about: an instance that comes up on
    // the wrong database comes up empty, and looks exactly like data loss.
    putenv('WALLOS_DB_DRIVER=pgsql');

    $configuration = wallos_database_configuration();

    assert_same('pgsql', $configuration['driver'], 'the driver stays what was asked for');
    assert_true($configuration['error'] !== null, 'and the error stands');
});

wallos_test('a port that is not a number is refused', function () {
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');
    putenv('WALLOS_DB_NAME=wallos');
    putenv('WALLOS_DB_USER=wallos');

    foreach (['5432a', '', 'default', '0', '99999'] as $port) {
        putenv('WALLOS_DB_PORT=' . $port);
        $configuration = wallos_database_configuration();
        assert_true($configuration['error'] !== null, 'refused: "' . $port . '"');
    }

    putenv('WALLOS_DB_PORT=6543');
    assert_same(6543, wallos_database_configuration()['pgsql']['port'], 'and a real one is taken');
});

wallos_test('a misspelt ssl mode is refused, not silently downgraded', function () {
    // 'require' typed as 'required' quietly becoming 'prefer' is an
    // unencrypted connection that nobody knows about.
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');
    putenv('WALLOS_DB_NAME=wallos');
    putenv('WALLOS_DB_USER=wallos');
    putenv('WALLOS_DB_SSLMODE=required');

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] !== null, 'refused');
    assert_true(strpos($configuration['error'], 'verify-full') !== false, 'and lists the valid modes');

    putenv('WALLOS_DB_SSLMODE=verify-full');
    assert_same('verify-full', wallos_database_configuration()['pgsql']['sslmode'], 'a valid one is taken');
});

wallos_test('the password can come from a file', function () {
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');
    putenv('WALLOS_DB_NAME=wallos');
    putenv('WALLOS_DB_USER=wallos');
    putenv('WALLOS_DB_PASSWORD_FILE=' . wallos_test_secret_file('db-password', "from-a-file\n"));

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] === null, 'accepted');
    assert_same('from-a-file', $configuration['pgsql']['password'], 'read and trimmed');
    assert_same('WALLOS_DB_PASSWORD_FILE', $configuration['managed']['password'] ?? null, 'and marked managed');
});

wallos_test('an unreadable password file invalidates the configuration', function () {
    // It does not connect without a password. The same rule as every other
    // secret in Wallos, and the place where getting it wrong matters most.
    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=db.example.com');
    putenv('WALLOS_DB_NAME=wallos');
    putenv('WALLOS_DB_USER=wallos');
    putenv('WALLOS_DB_PASSWORD_FILE=/nonexistent/secret');

    $configuration = wallos_database_configuration();

    assert_true($configuration['error'] !== null, 'refused');
    assert_true(strpos($configuration['error'], 'WALLOS_DB_PASSWORD_FILE') !== false, 'names the variable');
    assert_same('', $configuration['pgsql']['password'], 'and no password was invented');
});

wallos_test('the DSN carries no password', function () {
    // DSNs turn up in exception messages and stack traces.
    $dsn = wallos_database_pgsql_dsn([
        'host' => 'db.example.com',
        'port' => 6543,
        'name' => 'wallos',
        'sslmode' => 'require',
        'password' => 'do-not-leak-me',
    ]);

    assert_true(strpos($dsn, 'do-not-leak-me') === false, 'the password is not in it');
    assert_true(strpos($dsn, 'host=db.example.com') !== false, 'the host is');
    assert_true(strpos($dsn, 'port=6543') !== false, 'and the port');
    assert_true(strpos($dsn, 'sslmode=require') !== false, 'and the ssl mode');
});
