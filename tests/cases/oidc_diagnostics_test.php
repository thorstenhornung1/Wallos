<?php
/*
  What Wallos can say about its OIDC configuration before anyone logs in.

  From the 2026-08-16 test run: five distinct misconfigurations, every one
  diagnosed by reading authentik's database, none of them visible from Wallos.
*/

require_once WALLOS_ROOT . '/includes/oidc/diagnostics.php';

/**
 * A configuration in the shape wallos_get_effective_oidc_configuration() returns.
 *
 * @param array $settings
 * @param array $managed
 * @param array $notes
 * @return array
 */
function diagnostics_configuration($settings = [], $managed = [], $notes = [])
{
    return [
        'enabled' => 1,
        'settings' => array_merge([
            'name' => 'Authentik',
            'client_id' => 'wallos-test',
            'client_secret' => 'a-secret-that-must-not-appear',
            'authorization_url' => 'https://auth.example.com/authorize',
            'token_url' => 'https://auth.example.com/token',
            'user_info_url' => 'https://auth.example.com/userinfo',
            'redirect_url' => 'https://wallos.example.com/login.php',
            'user_identifier_field' => 'sub',
            'scopes' => 'openid email profile',
            'auto_create_user' => 1,
            'require_email_verified' => 0,
        ], $settings),
        'managed_fields' => $managed,
        'notes' => $notes,
        'discovery_document' => null,
        'is_configured' => true,
    ];
}

/**
 * @param array  $checks
 * @param string $label
 * @return array|null
 */
function diagnostics_find($checks, $label)
{
    foreach ($checks as $check) {
        if ($check['label'] === $label) {
            return $check;
        }
    }

    return null;
}

wallos_test('a complete configuration reports no problem', function () {
    $checks = wallos_oidc_checks(diagnostics_configuration());

    assert_same(WALLOS_OIDC_OK, wallos_oidc_worst_status($checks), 'nothing is wrong');
    assert_same(WALLOS_OIDC_OK, diagnostics_find($checks, 'Client secret')['status'], 'the secret is present');
});

wallos_test('the client secret is never in the output', function () {
    // The whole point of a diagnostics page is that it can be shared in a bug
    // report or a screenshot.
    $checks = wallos_oidc_checks(diagnostics_configuration());

    assert_not_contains('a-secret-that-must-not-appear', json_encode($checks),
        'the secret value does not appear anywhere');
    assert_contains('Configured', diagnostics_find($checks, 'Client secret')['detail'],
        'only its state is reported');
});

wallos_test('an unreadable secret file is named as such', function () {
    $checks = wallos_oidc_checks(diagnostics_configuration(
        ['client_secret' => ''],
        [],
        ['OIDC client secret file is not readable: /run/secrets/oidc']
    ));

    $check = diagnostics_find($checks, 'Client secret');
    assert_same(WALLOS_OIDC_ERROR, $check['status'], 'it is an error');
    assert_contains('cannot be read', $check['detail'], 'and says why');
});

wallos_test('a failed discovery is reported with its reason', function () {
    // Cause 2 and 3 from the test run: the provider was unreachable or the
    // application had no provider assigned. Both surface here.
    $checks = wallos_oidc_checks(
        diagnostics_configuration(),
        null,
        'OIDC discovery failed for https://auth.example.com/.well-known/openid-configuration: HTTP 404'
    );

    $check = diagnostics_find($checks, 'Discovery');
    assert_same(WALLOS_OIDC_ERROR, $check['status'], 'it is an error');
    assert_contains('HTTP 404', $check['detail'], 'the provider response is passed through');
});

wallos_test('required email verification is called out before it rejects anyone', function () {
    // Cause 4: the provider's default scope mapping reports email_verified
    // false. The configuration is already wrong; Wallos knows it and stayed
    // quiet until someone attempted a login.
    $checks = wallos_oidc_checks(diagnostics_configuration(['require_email_verified' => 1]));

    $check = diagnostics_find($checks, 'Verified email required');
    assert_same(WALLOS_OIDC_WARNING, $check['status'], 'it is worth warning about');
    assert_contains('OIDC_REQUIRE_EMAIL_VERIFIED=false', $check['detail'],
        'and the fix is named');
});

wallos_test('missing endpoints are individually identified', function () {
    $checks = wallos_oidc_checks(diagnostics_configuration([
        'token_url' => '',
        'user_info_url' => '',
    ]));

    assert_same(WALLOS_OIDC_ERROR, diagnostics_find($checks, 'Token URL')['status'], 'token URL');
    assert_same(WALLOS_OIDC_ERROR, diagnostics_find($checks, 'User info URL')['status'], 'user info URL');
    assert_same(WALLOS_OIDC_OK, diagnostics_find($checks, 'Authorization URL')['status'],
        'the one that is set is not flagged');
});

wallos_test('a relative redirect URL is an error', function () {
    $checks = wallos_oidc_checks(diagnostics_configuration(['redirect_url' => '/login.php']));

    $check = diagnostics_find($checks, 'Redirect URL');
    assert_same(WALLOS_OIDC_ERROR, $check['status'], 'it cannot work');
    assert_contains('absolute', $check['detail'], 'and says what is wrong');
});

wallos_test('the redirect URL states what the provider must match', function () {
    // Cause 1 and 2 were both redirect URI problems. Wallos cannot see the
    // provider's list, but it can state its own expectation precisely.
    $checks = wallos_oidc_checks(diagnostics_configuration());

    $check = diagnostics_find($checks, 'Redirect URL');
    assert_contains('https://wallos.example.com/login.php', $check['detail'], 'the value is shown');
    assert_contains('exactly this value', $check['detail'], 'and that it must match');
});

wallos_test('environment-managed fields name their variable', function () {
    $checks = wallos_oidc_checks(diagnostics_configuration([], [
        'client_id' => 'OIDC_CLIENT_ID',
        'client_secret' => 'OIDC_CLIENT_SECRET_FILE',
    ]));

    assert_contains('OIDC_CLIENT_ID', diagnostics_find($checks, 'Client ID')['detail'],
        'so it is clear where to change it');
    assert_contains('OIDC_CLIENT_SECRET_FILE', diagnostics_find($checks, 'Client secret')['detail'],
        'including for the secret');
});

wallos_test('disabled OIDC reports that and stops', function () {
    $configuration = diagnostics_configuration();
    $configuration['enabled'] = 0;

    $checks = wallos_oidc_checks($configuration);

    assert_same(1, count($checks), 'there is nothing else worth saying');
    assert_contains('Disabled', $checks[0]['detail'], 'and it says so');
});

wallos_test('the worst status summarises the set', function () {
    $ok = wallos_oidc_checks(diagnostics_configuration());
    assert_same(WALLOS_OIDC_OK, wallos_oidc_worst_status($ok), 'all fine');

    $warned = wallos_oidc_checks(diagnostics_configuration(['require_email_verified' => 1]));
    assert_same(WALLOS_OIDC_WARNING, wallos_oidc_worst_status($warned), 'a warning surfaces');

    $broken = wallos_oidc_checks(diagnostics_configuration(['client_id' => '', 'require_email_verified' => 1]));
    assert_same(WALLOS_OIDC_ERROR, wallos_oidc_worst_status($broken), 'an error outranks a warning');
});

wallos_test('the three state failures are told apart', function () {
    // They collapsed into one code, and each has a different fix: a malformed
    // response, a session that no longer exists, and a state that does not match.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    foreach (['oidc_invalid_response', 'oidc_session_expired', 'oidc_state_mismatch'] as $code) {
        assert_contains($code, $source, $code . ' is a distinct outcome');
    }

    // And the login page has to recognise them, or they render as nothing.
    $login = file_get_contents(WALLOS_ROOT . '/login.php');
    foreach (['oidc_session_expired', 'oidc_state_mismatch', 'oidc_token_exchange_failed', 'oidc_userinfo_failed'] as $code) {
        assert_contains($code, $login, $code . ' is handled on the login page');
    }
});

wallos_test('a failed token exchange keeps the provider explanation', function () {
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/handle_oidc_callback.php');

    assert_not_contains('die("OIDC token exchange failed.")', $source,
        'it no longer dies with a bare string');
    assert_contains('provider_error_description', $source,
        'the provider description is recorded');
    assert_contains("wallos_oidc_log_failure('token_exchange_failed'", $source,
        'and the failure is logged');
});

wallos_test('nothing secret is logged', function () {
    // The log is the one place an administrator will paste into a bug report.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/handle_oidc_callback.php');

    preg_match_all('/wallos_oidc_log_failure\((.*?)\]\);/s', $source, $matches);
    assert_true($matches[1] !== [], 'there are log calls to check');

    foreach ($matches[1] as $call) {
        foreach (['client_secret', 'access_token', 'id_token', "\$_GET['code']", '$code'] as $forbidden) {
            assert_not_contains($forbidden, $call, 'no credential in a log call');
        }
    }
});
