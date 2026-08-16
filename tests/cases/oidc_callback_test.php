<?php
/*
  An OIDC authorization response must be consumed wherever it can land.

  The redirect URI is configured in the identity provider, and login.php is the
  obvious choice for it — it is the page the flow starts from. Before this was
  shared, only the document root consumed the callback, and a response arriving
  at login.php was discarded without a trace: the provider logged a successful
  authorization, Wallos rendered the login form again, and nothing said why.
*/

wallos_test('every redirect target consumes the callback', function () {
    $entryPoints = [
        'login.php' => 'the page the login flow starts from',
        'includes/checksession.php' => 'the document root, through header.php',
    ];

    foreach ($entryPoints as $path => $description) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_contains('consume_oidc_callback.php', $source,
            $path . ' (' . $description . ') consumes an OIDC callback');
    }
});

wallos_test('the callback is only consumed when one is present', function () {
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    // Returning early keeps an ordinary page load untouched: login.php includes
    // this on every request, not just on the way back from the provider.
    assert_contains("if (!isset(\$_GET['code']) || !isset(\$_GET['state'])) {", $source,
        'an ordinary request returns before anything else happens');
    assert_contains('return;', $source, 'and returns rather than exiting');
});

wallos_test('the state is compared in constant time and consumed once', function () {
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    assert_contains('hash_equals($expectedState, $state)', $source,
        'the state comparison is not vulnerable to timing');
    assert_contains("unset(\$_SESSION['oidc_state'])", $source,
        'the state is cleared so it cannot be replayed');
    assert_contains('oidc_invalid_state', $source,
        'a mismatch is reported rather than ignored');
});
