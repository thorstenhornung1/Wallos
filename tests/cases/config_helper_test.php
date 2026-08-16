<?php
/*
  The environment and secret-file primitives.
*/

require_once WALLOS_ROOT . '/includes/config_helper.php';

wallos_test('environment lookup reads getenv, $_ENV and $_SERVER', function () {
    assert_same(null, wallos_env('WALLOS_SMTP_HOST'), 'unset variable resolves to null');

    putenv('WALLOS_SMTP_HOST=from-getenv');
    assert_same('from-getenv', wallos_env('WALLOS_SMTP_HOST'), 'getenv is read');
    putenv('WALLOS_SMTP_HOST');

    $_ENV['WALLOS_SMTP_HOST'] = 'from-env-array';
    assert_same('from-env-array', wallos_env('WALLOS_SMTP_HOST'), '$_ENV is read');
    unset($_ENV['WALLOS_SMTP_HOST']);

    $_SERVER['WALLOS_SMTP_HOST'] = 'from-server-array';
    assert_same('from-server-array', wallos_env('WALLOS_SMTP_HOST'), '$_SERVER is read');
    unset($_SERVER['WALLOS_SMTP_HOST']);
});

wallos_test('boolean parsing accepts the documented spellings', function () {
    foreach (['1', 'true', 'TRUE', 'yes', 'on', 'On'] as $value) {
        assert_same(1, wallos_parse_boolean($value), $value . ' parses as true');
    }

    foreach (['0', 'false', 'FALSE', 'no', 'off'] as $value) {
        assert_same(0, wallos_parse_boolean($value), $value . ' parses as false');
    }

    assert_same(null, wallos_parse_boolean('maybe'), 'an invalid value is reported, not silently false');
    assert_same(null, wallos_parse_boolean(''), 'an empty value is reported, not silently false');
});

wallos_test('secret files strip trailing newlines and keep inner spaces', function () {
    $path = wallos_test_secret_file('plain', "a secret with spaces\n");
    assert_same('a secret with spaces', wallos_read_secret_file($path)['value'], 'trailing LF removed');

    $path = wallos_test_secret_file('crlf', "windows secret\r\n");
    assert_same('windows secret', wallos_read_secret_file($path)['value'], 'trailing CRLF removed');

    $path = wallos_test_secret_file('inner', "  padded  \n");
    assert_same('  padded  ', wallos_read_secret_file($path)['value'], 'inner and leading spaces preserved');
});

wallos_test('an unreadable secret file is an error, not an empty value', function () {
    $result = wallos_read_secret_file('/nonexistent/secret');
    assert_same(null, $result['value'], 'no value is produced');
    assert_contains('not readable', $result['error'], 'the error names the problem');

    $result = wallos_read_secret_file('   ');
    assert_contains('empty', $result['error'], 'an empty path is an error of its own');
});

wallos_test('the *_FILE variant wins over the plain variable', function () {
    $path = wallos_test_secret_file('precedence', "from-file\n");

    putenv('WALLOS_SMTP_PASSWORD=from-variable');
    $secret = wallos_env_secret('WALLOS_SMTP_PASSWORD');
    assert_same('from-variable', $secret['value'], 'plain variable is used when no file is configured');
    assert_same('environment', $secret['source'], 'source is reported');

    putenv('WALLOS_SMTP_PASSWORD_FILE=' . $path);
    $secret = wallos_env_secret('WALLOS_SMTP_PASSWORD');
    assert_same('from-file', $secret['value'], 'the file wins');
    assert_same('environment_file', $secret['source'], 'source is reported');
    assert_same('WALLOS_SMTP_PASSWORD_FILE', $secret['variable'], 'the managing variable is reported');
});

wallos_test('secret status never carries the value', function () {
    $config = wallos_config_result();
    wallos_config_set($config, 'password', 'top-secret', 'environment_file', 'WALLOS_SMTP_PASSWORD_FILE');

    $status = wallos_secret_status($config, 'password');
    assert_same(true, $status['configured'], 'a configured secret is reported as configured');
    assert_same(true, $status['managed'], 'environment ownership is reported');
    assert_not_contains('top-secret', json_encode($status), 'the value is absent from the status');
});

wallos_test('managed input attributes disable the field and name the variable', function () {
    $config = wallos_config_result();
    wallos_config_set($config, 'host', 'smtp.example.com', 'environment', 'WALLOS_SMTP_HOST');
    wallos_config_set($config, 'port', 587, 'admin');

    assert_contains('disabled', wallos_managed_input_attrs($config, 'host'), 'a managed field is disabled');
    assert_contains('WALLOS_SMTP_HOST', wallos_managed_input_attrs($config, 'host'), 'the variable is named');
    assert_same('', wallos_managed_input_attrs($config, 'port'), 'a database field stays editable');
});
