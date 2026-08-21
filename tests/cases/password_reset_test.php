<?php
/*
  Issuing a password reset token.

  The request half of passwordreset.php deleted any outstanding token, inserted
  a new one, checked neither, and then displayed "check your email" no matter
  what happened. If the insert failed, the account was left with the old token
  already deleted and no new one — no way to reset at all — while being told an
  email was on the way. Retrying reproduced it exactly: nothing left to delete,
  and the same insert failing again.

  On this flow that is as bad as it gets. It is the only route back in for
  somebody who cannot log in, and a user told an email is coming waits for it.
*/

require_once WALLOS_ROOT . '/includes/password_reset.php';

wallos_test('a token is issued and replaces the previous one', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(wallos_issue_password_reset($db, 1, 'alice@example.com', 'token-one'), 'issued');
    assert_true(wallos_issue_password_reset($db, 1, 'alice@example.com', 'token-two'), 'issued again');

    assert_same(1, (int) $db->scalar("SELECT COUNT(*) FROM password_resets WHERE email = 'alice@example.com'"),
        'one outstanding token, not two');
    assert_same('token-two', $db->scalar("SELECT token FROM password_resets WHERE email = 'alice@example.com'"),
        'the newest one');

    $db->close();
});

wallos_test('a failed issue leaves the previous token working', function () {
    // The point of the transaction. Without it the delete has already happened
    // when the insert fails, so the account is left with nothing — and the page
    // said an email was sent.

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(wallos_issue_password_reset($db, 1, 'alice@example.com', 'still-valid'), 'a token exists');

    wallos_test_block_writes($db, 'password_resets', 'INSERT');

    assert_true(@wallos_issue_password_reset($db, 1, 'alice@example.com', 'never-stored') === false,
        'reported as not issued');

    wallos_test_unblock_writes($db, 'password_resets');

    assert_same('still-valid', $db->scalar("SELECT token FROM password_resets WHERE email = 'alice@example.com'"),
        'and the token the user may already hold still works');

    $db->close();
});

wallos_test('the page reports a failed issue rather than promising an email', function () {
    $source = file_get_contents(WALLOS_ROOT . '/passwordreset.php');

    assert_true(wallos_test_file_calls('passwordreset.php', 'wallos_issue_password_reset'),
        'issuing goes through the checked path');
    assert_true(strpos($source, 'INSERT INTO password_resets') === false,
        'and the page has no inline copy of its own');

    // The success message must still be set for an address that is not
    // registered — that is what keeps the page from confirming which addresses
    // exist. Only a genuine write failure produces the error.
    $issued = strpos($source, '$issued = wallos_issue_password_reset');
    $success = strpos($source, '$hasSuccessMessage = true;');
    assert_true($issued !== false && $success !== false && $issued < $success,
        'the outcome is known before the message is chosen');
});

wallos_test('an unknown address still looks exactly like a successful request', function () {
    // Enumeration: the response may not differ between a registered address and
    // one that is not, so the success path must not depend on the user lookup.
    $source = file_get_contents(WALLOS_ROOT . '/passwordreset.php');

    $start = strpos($source, "if (isset(\$_POST['email'])");
    $end = strpos($source, "if (isset(\$_GET['token'])", $start);
    $block = substr($source, $start, $end - $start);

    assert_true(strpos($block, '$issued = true;') !== false,
        'the default is success, so an address with no account takes the same path');
    assert_true(substr_count($block, '$hasSuccessMessage = true;') === 1,
        'set in one place only');
});

wallos_test('an unconfigured instance says so instead of redirecting in silence', function () {
    // passwordreset.php needs a usable instance transport and a server_url to
    // build the link with. server_url is empty on a fresh installation, so on
    // an instance nobody configured for this the feature is inert — and it used
    // to redirect to the front page saying nothing, which is what a broken
    // feature looks like too. Two attempts in a test run were recorded as
    // failures before the cause was found by reading the source (issue #96).
    $source = file_get_contents(WALLOS_ROOT . '/passwordreset.php');

    // Asked of the configuration check specifically. The other redirect in this
    // file — somebody already signed in — goes to the front page and should:
    // they do not need a password reset and nothing needs explaining.
    $check = strpos($source, 'wallos_get_instance_smtp_config');
    assert_true($check !== false, 'the configuration check is still there');

    $afterCheck = substr($source, $check, 300);
    assert_contains('reset=unavailable', $afterCheck,
        'an unconfigured instance says why instead of redirecting in silence');

    // And the login page has to be able to say it. A parameter nothing renders
    // is the same silence with an extra step.
    $login = file_get_contents(WALLOS_ROOT . '/login.php');
    assert_contains("\$_GET['reset']", $login, 'login.php reads the reason');
    assert_contains('password_reset_unavailable', $login, 'and renders a message for it');

    require WALLOS_ROOT . '/includes/i18n/en.php';
    assert_true(array_key_exists('password_reset_unavailable', $i18n),
        'the message exists in the default language');
});
