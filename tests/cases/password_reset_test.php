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
    wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger');

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(wallos_issue_password_reset($db, 1, 'alice@example.com', 'still-valid'), 'a token exists');

    $db->exec('CREATE TRIGGER block_reset_insert BEFORE INSERT ON password_resets
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    assert_true(@wallos_issue_password_reset($db, 1, 'alice@example.com', 'never-stored') === false,
        'reported as not issued');

    $db->exec('DROP TRIGGER block_reset_insert');

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
