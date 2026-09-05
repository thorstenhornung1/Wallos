<?php
/*
  The login page's OIDC "Sign in with <provider>" button (#155).

  The button is a labelled entry point to the provider's authorization URL. Its
  label is built from the configured provider name — interpolated into a
  translated string, never hard-wired to "Authentik" or any other provider. With
  no name configured it falls back to a neutral translated label, and with OIDC
  disabled the button is absent entirely.

  login.php renders it through includes/login_oidc_button.php, which reads the
  page's variables from the enclosing scope. That partial is exactly what makes
  this testable without standing up the whole login page: each case sets the
  variables the page would set, renders the partial, and asserts on its output —
  no database, session or redirect. Presentation only, so the cases behave
  identically on both backends.
*/

require_once WALLOS_ROOT . '/includes/i18n/languages.php';
require_once WALLOS_ROOT . '/includes/i18n/getlang.php';

/**
 * Renders the OIDC button partial with a given set of login-page variables and
 * returns the HTML it produced. include shares this function's scope, so the
 * partial reads $oidcEnabled, $oidc_name and the rest straight from here — the
 * same way it reads them from login.php's own scope.
 *
 * @param array<string, mixed> $vars
 * @return string
 */
function login_oidc_button_render(array $vars)
{
    // A real translation table, so the label goes through the application's i18n
    // rather than a stand-in — the point of the feature is a translated string
    // with the provider name interpolated into it.
    $i18n = wallos_translations('en');

    $oidcEnabled = $vars['oidcEnabled'] ?? false;
    $password_login_disabled = $vars['password_login_disabled'] ?? false;
    $oidc_auth_url = $vars['oidc_auth_url'] ?? '';
    $oidc_name = $vars['oidc_name'] ?? '';

    ob_start();
    include WALLOS_ROOT . '/includes/login_oidc_button.php';

    return ob_get_clean();
}

wallos_test('the button is labelled with the configured provider name when OIDC is enabled', function () {
    $html = login_oidc_button_render([
        'oidcEnabled' => true,
        'password_login_disabled' => false,
        'oidc_auth_url' => 'https://idp.example.com/authorize?client_id=wallos&state=abc',
        'oidc_name' => 'Acme SSO',
    ]);

    assert_contains('Login with Acme SSO', $html, 'the label carries the configured provider name');
    assert_contains('<a ', $html, 'it is a real anchor element, focusable and with a screen-reader label');
    assert_contains('class="button secondary-button"', $html, 'styled consistently with the login form');
    assert_contains(
        'href="https://idp.example.com/authorize?client_id=wallos&amp;state=abc"',
        $html,
        'it links to the OIDC authorization URL, HTML-escaped'
    );
    assert_contains('or-separator', $html, 'and sits beside the password form with a separator');
    assert_not_contains('Authentik', $html, 'the provider name is data, never a hard-wired "Authentik"');
});

wallos_test('the label falls back to a neutral string when no provider name is configured', function () {
    // An unset name and a whitespace-only name both count as "no name": the
    // label must not degrade to a dangling "Login with " with nothing after it.
    foreach (['', '   '] as $blank) {
        $html = login_oidc_button_render([
            'oidcEnabled' => true,
            'password_login_disabled' => false,
            'oidc_auth_url' => 'https://idp.example.com/authorize',
            'oidc_name' => $blank,
        ]);

        assert_contains('Login with your login provider', $html,
            'a neutral, translated label stands in for the missing provider name');
        assert_contains('<a ', $html, 'the button is still rendered and usable');
        assert_contains('href="https://idp.example.com/authorize"', $html,
            'still linking to the authorization URL');
        assert_not_contains('Authentik', $html, 'and it names no particular provider');
    }
});

wallos_test('with password login disabled the OIDC button is the sole path, with no separator', function () {
    $html = login_oidc_button_render([
        'oidcEnabled' => true,
        'password_login_disabled' => true,
        'oidc_auth_url' => 'https://idp.example.com/authorize',
        'oidc_name' => 'Acme SSO',
    ]);

    assert_contains('Login with Acme SSO', $html, 'the button is present as the primary path');
    assert_contains('<a ', $html, 'as a real anchor element');
    assert_not_contains('or-separator', $html,
        'and there is no "or" separator, because there is no password form to divide it from');
});

wallos_test('the button is absent entirely when OIDC is disabled', function () {
    $html = login_oidc_button_render([
        'oidcEnabled' => false,
        'password_login_disabled' => false,
        'oidc_auth_url' => 'https://idp.example.com/authorize',
        'oidc_name' => 'Acme SSO',
    ]);

    assert_same('', trim($html), 'nothing is rendered when OIDC is disabled');
    assert_not_contains('<a', $html, 'no stray anchor is emitted');
    assert_not_contains('or-separator', $html, 'and no separator either');
});

wallos_test('login.php renders the button through the partial and hard-wires no provider name', function () {
    // The page-level ties: login.php delegates to the partial, and neither the
    // page nor the partial contains the literal "Authentik" in markup or code.
    $login = file_get_contents(WALLOS_ROOT . '/login.php');
    assert_contains("include __DIR__ . '/includes/login_oidc_button.php'", $login,
        'the login page renders the OIDC entry through the partial');
    assert_not_contains('Authentik', $login, 'the login page hard-wires no provider name');

    $partial = file_get_contents(WALLOS_ROOT . '/includes/login_oidc_button.php');
    assert_not_contains('Authentik', $partial, 'nor does the partial');

    // The neutral fallback is a real translation key, and it too names no
    // particular provider.
    $i18n = wallos_translations('en');
    assert_true(isset($i18n['login_with_provider']), 'the neutral fallback label is a translation key');
    assert_not_contains('Authentik', $i18n['login_with_provider'], 'and it names no provider');
});
