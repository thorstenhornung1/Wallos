<?php
/*
  Revoking persistent login tokens.

  logout.php used to bind an undefined $userId into the delete, so `user_id =
  NULL` matched nothing and every logout left a usable remember-me token in the
  database. The user looked logged out — session destroyed, cookie cleared — but
  the credential that could log them back in was still there.
*/

require_once WALLOS_ROOT . '/includes/session_tokens.php';

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $token
 */
function session_tokens_insert($db, $userId, $token)
{
    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * @param SQLite3 $db
 * @param string  $token
 * @return bool
 */
function session_tokens_exists($db, $token)
{
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM login_tokens WHERE token = :token');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return ((int) $row['total']) > 0;
}

wallos_test('logging out actually removes the token', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    session_tokens_insert($db, 1, 'alice-token');

    $removed = wallos_revoke_login_token($db, 'alice-token');

    assert_same(1, $removed, 'one token should have been removed');
    assert_true(!session_tokens_exists($db, 'alice-token'), 'the token must be gone after logout');

    $db->close();
});

wallos_test('revoking one token leaves other sessions alone', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    session_tokens_insert($db, 1, 'alice-phone');
    session_tokens_insert($db, 1, 'alice-laptop');

    wallos_revoke_login_token($db, 'alice-phone');

    assert_true(!session_tokens_exists($db, 'alice-phone'), 'the phone token was revoked');
    assert_true(session_tokens_exists($db, 'alice-laptop'), 'the laptop session must survive');

    $db->close();
});

wallos_test('revoking a user removes every device they are signed in on', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    session_tokens_insert($db, 1, 'alice-phone');
    session_tokens_insert($db, 1, 'alice-laptop');
    session_tokens_insert($db, 2, 'bob-laptop');

    $removed = wallos_revoke_user_login_tokens($db, 1);

    assert_same(2, $removed, 'both of alice\'s tokens should have been removed');
    assert_true(!session_tokens_exists($db, 'alice-phone'), 'phone revoked');
    assert_true(!session_tokens_exists($db, 'alice-laptop'), 'laptop revoked');
    assert_true(session_tokens_exists($db, 'bob-laptop'), 'another user must not be signed out');

    $db->close();
});

wallos_test('nothing is deleted for an empty or unknown token', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    session_tokens_insert($db, 1, 'alice-token');

    assert_same(0, wallos_revoke_login_token($db, ''), 'an empty token deletes nothing');
    assert_same(0, wallos_revoke_login_token($db, 'not-a-token'), 'an unknown token deletes nothing');
    assert_same(0, wallos_revoke_user_login_tokens($db, 0), 'user id 0 deletes nothing');
    assert_true(session_tokens_exists($db, 'alice-token'), 'the real token is untouched');

    $db->close();
});

wallos_test('logout.php no longer binds an undefined user id', function () {
    $source = file_get_contents(WALLOS_ROOT . '/logout.php');

    assert_true(
        strpos($source, 'AND user_id = :userId') === false,
        'the delete must not be scoped by a variable logout.php never assigns'
    );
    assert_true(
        strpos($source, 'wallos_revoke_login_token') !== false,
        'logout should revoke through the shared helper'
    );
});
