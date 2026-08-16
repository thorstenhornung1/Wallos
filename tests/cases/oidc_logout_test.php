<?php
/*
  RP-initiated logout.

  What Wallos sent before: the configured logout URL with the OIDC *callback*
  URI as post_logout_redirect_uri, and no id_token_hint. A user who signed out
  was pointed back at a sign-in endpoint, and the provider was given nothing to
  identify which session to end.

  The rule that matters most is not in any of the URL building: local logout has
  to finish before the redirect, so an unreachable provider can never leave
  somebody logged in here.
*/

require_once WALLOS_ROOT . '/includes/oidc/logout.php';

// ------------------------------------------------------- end-session endpoint

wallos_test('an explicitly configured logout URL wins', function () {
    $url = wallos_oidc_end_session_url(
        ['logout_url' => 'https://auth.example.com/custom-end'],
        ['end_session_endpoint' => 'https://auth.example.com/discovered']
    );

    assert_same('https://auth.example.com/custom-end', $url, 'the operator knows their provider');
});

wallos_test('discovery supplies the endpoint when nothing is configured', function () {
    // So an operator using OIDC_ISSUER never has to paste a provider-specific
    // URL that the provider already publishes.
    $url = wallos_oidc_end_session_url(
        ['logout_url' => ''],
        ['end_session_endpoint' => 'https://auth.example.com/application/o/wallos/end-session/']
    );

    assert_same('https://auth.example.com/application/o/wallos/end-session/', $url, 'discovered');
});

wallos_test('no endpoint anywhere means local logout only', function () {
    assert_true(wallos_oidc_end_session_url([], null) === null, 'nothing configured, nothing discovered');
    assert_true(wallos_oidc_end_session_url(['logout_url' => '   '], []) === null, 'blank does not count');
    assert_true(
        wallos_oidc_end_session_url([], ['end_session_endpoint' => '']) === null,
        'an empty discovery value does not count either'
    );
});

// ------------------------------------------------------- post-logout redirect

wallos_test('the return target is not the callback URI', function () {
    // The callback exists to complete a sign-in. Sending a returning logout
    // through it is how a logout turns back into a login.
    $url = wallos_oidc_post_logout_redirect_url(['redirect_url' => 'https://wallos.example.com/login.php']);

    assert_same('https://wallos.example.com/login.php?logged_out=1', $url, 'marked as a logout return');
    assert_true($url !== 'https://wallos.example.com/login.php', 'and distinguishable from the callback');
});

wallos_test('the return target is derived from a document-root callback too', function () {
    $url = wallos_oidc_post_logout_redirect_url(['redirect_url' => 'https://wallos.example.com/']);

    assert_same('https://wallos.example.com/login.php?logged_out=1', $url, 'same result');
});

wallos_test('a subdirectory installation keeps its subdirectory', function () {
    $url = wallos_oidc_post_logout_redirect_url(['redirect_url' => 'https://example.com/wallos/login.php']);

    assert_same('https://example.com/wallos/login.php?logged_out=1', $url, 'path preserved');
});

wallos_test('a non-default port is preserved', function () {
    $url = wallos_oidc_post_logout_redirect_url(['redirect_url' => 'http://localhost:8383/login.php']);

    assert_same('http://localhost:8383/login.php?logged_out=1', $url, 'port preserved');
});

wallos_test('an explicit post-logout URL overrides the derived one', function () {
    $url = wallos_oidc_post_logout_redirect_url([
        'redirect_url' => 'https://wallos.example.com/login.php',
        'post_logout_redirect_url' => 'https://wallos.example.com/goodbye',
    ]);

    assert_same('https://wallos.example.com/goodbye', $url, 'the operator decides');
});

wallos_test('nothing to derive from yields nothing', function () {
    assert_true(wallos_oidc_post_logout_redirect_url([]) === null, 'no redirect url');
    assert_true(wallos_oidc_post_logout_redirect_url(['redirect_url' => 'not a url']) === null, 'unparseable');
});

// -------------------------------------------------------------- request shape

wallos_test('the end-session request carries all three parameters', function () {
    $url = wallos_oidc_build_end_session_url(
        'https://auth.example.com/end-session/',
        'the.id.token',
        'https://wallos.example.com/login.php?logged_out=1',
        'abc123'
    );

    assert_true(strpos($url, 'id_token_hint=the.id.token') !== false, 'the provider is told which session');
    assert_true(strpos($url, 'post_logout_redirect_uri=') !== false, 'and where to return');
    assert_true(strpos($url, 'state=abc123') !== false, 'and can be recognised on return');
    assert_true(strpos($url, 'https%3A%2F%2Fwallos.example.com') !== false, 'the return URI is encoded');
});

wallos_test('an existing query string on the endpoint is kept', function () {
    $url = wallos_oidc_build_end_session_url(
        'https://auth.example.com/end?tenant=main',
        'tok',
        null,
        'st'
    );

    assert_true(strpos($url, 'tenant=main') !== false, 'the configured parameter survives');
    assert_true(strpos($url, '?tenant=main&') === 0 || strpos($url, '&id_token_hint=') !== false,
        'and the new ones are appended, not started with a second ?');
});

wallos_test('a missing ID token does not produce an empty hint', function () {
    // Sending id_token_hint= with nothing after it is worse than omitting it:
    // some providers reject the request outright.
    $url = wallos_oidc_build_end_session_url('https://auth.example.com/end', null, null, 'st');

    assert_true(strpos($url, 'id_token_hint') === false, 'omitted entirely');
    assert_true(strpos($url, 'state=st') !== false, 'the rest is still sent');
});

wallos_test('an endpoint with nothing to add is left alone', function () {
    $url = wallos_oidc_build_end_session_url('https://auth.example.com/end', null, null, null);

    assert_same('https://auth.example.com/end', $url, 'unchanged');
});

// --------------------------------------------------------------- return state

wallos_test('a matching state is accepted', function () {
    assert_true(wallos_oidc_logout_state_is_valid('abc', 'abc'), 'ours');
});

wallos_test('a state that is present and wrong is refused', function () {
    // A response to somebody else's request.
    assert_true(!wallos_oidc_logout_state_is_valid('abc', 'xyz'), 'not ours');
    assert_true(!wallos_oidc_logout_state_is_valid('abc', null), 'we issued none');
    assert_true(!wallos_oidc_logout_state_is_valid('abc', ''), 'we issued none');
});

wallos_test('an absent state is accepted', function () {
    // Providers are not required to return state. Refusing would turn a correct
    // logout against a conforming provider into an error page.
    assert_true(wallos_oidc_logout_state_is_valid(null, 'abc'), 'nothing returned');
    assert_true(wallos_oidc_logout_state_is_valid('', 'abc'), 'empty is nothing');
});

// ------------------------------------------------------------- the local part

wallos_test('local logout completes before any redirect', function () {
    // The ordering that matters: a provider that is unreachable, slow or
    // misconfigured must not be able to leave somebody logged in here.
    $source = file_get_contents(WALLOS_ROOT . '/logout.php');

    $revoke = strpos($source, 'wallos_revoke_login_token');
    $destroy = strpos($source, 'session_destroy');
    $redirect = strpos($source, "header('Location: ' . wallos_oidc_build_end_session_url");

    assert_true($revoke !== false && $destroy !== false && $redirect !== false,
        'all three steps are present');
    assert_true($revoke < $redirect, 'the token is revoked before the redirect');
    assert_true($destroy < $redirect, 'the session is destroyed before the redirect');
});

wallos_test('the ID token stays server side', function () {
    // It is a bearer credential. It belongs in the PHP session and nowhere a
    // browser, a log or a template can reach.
    foreach (['logout.php', 'login.php', 'includes/oidc/oidc_login.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(strpos($source, "setcookie('oidc_id_token") === false, $path . ': not a cookie');
        assert_true(strpos($source, 'echo $idToken') === false, $path . ': not rendered');
        assert_true(strpos($source, 'error_log($idToken') === false, $path . ': not logged');
    }

    $login = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_login.php');
    assert_true(strpos($login, "\$_SESSION['oidc_id_token']") !== false,
        'it is kept in the server-side session for id_token_hint');
});

wallos_test('the callback URI is no longer the logout return target', function () {
    $source = file_get_contents(WALLOS_ROOT . '/logout.php');

    assert_true(
        strpos($source, "post_logout_redirect_uri=\$returnTo") === false,
        'the old hand-built redirect is gone'
    );
    assert_true(
        strpos($source, 'wallos_oidc_post_logout_redirect_url') !== false,
        'the return target is resolved properly'
    );
});
