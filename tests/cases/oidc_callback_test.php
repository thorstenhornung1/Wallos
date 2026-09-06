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
    // this on every request, not just on the way back from the provider. A
    // callback needs a state AND one of code or error (§10); anything else
    // returns rather than exiting.
    assert_contains("if (!isset(\$_GET['state']) || (!isset(\$_GET['code']) && !isset(\$_GET['error']))) {", $source,
        'an ordinary request returns before anything else happens');
    assert_contains('return;', $source, 'and returns rather than exiting');
});

wallos_test('the callback recognizes an error response, not only a code (§10)', function () {
    // A prompt=none request the provider cannot satisfy returns
    // ?error=login_required&state=..., with no code. The old callback required
    // BOTH a code and a state and silently ignored such a response; the resume
    // guard depends on it being handled.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    assert_contains("\$_GET['error']", $source, 'the callback reads the error parameter');
    assert_contains('consume_resume_callback.php', $source,
        'a resume callback is dispatched to its own handler');
});

wallos_test('the state is compared in constant time and the transaction consumed once', function () {
    // The state comparison and single-use now live in the per-state transaction
    // map (WP1): consume compares with hash_equals and removes the matched entry
    // so a callback cannot be replayed, and the callback reports a mismatch.
    $callback = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');
    $transactions = file_get_contents(WALLOS_ROOT . '/includes/oidc/transactions.php');

    assert_true(wallos_test_file_calls('includes/oidc/consume_oidc_callback.php', 'wallos_oidc_consume_transaction'),
        'the callback consumes the transaction for the returned state');
    assert_contains('hash_equals', $transactions,
        'the state comparison is not vulnerable to timing');
    assert_contains("unset(\$_SESSION['oidc_transactions'][\$key])", $transactions,
        'the matched transaction is removed, so it cannot be replayed');
    assert_contains('oidc_state_mismatch', $callback,
        'a mismatch is reported rather than ignored');
});
