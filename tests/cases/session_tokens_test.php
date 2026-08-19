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

// ------------------------------------------ telling failure apart from absence

wallos_test('a delete that could not run is not reported as nothing to delete', function () {
    // Both remove no rows, but only one of them means a browser is still
    // holding a working credential. Reporting 0 for both is what let
    // back-channel logout count a live session as revoked and answer the
    // identity provider HTTP 200 — which no provider retries.
    wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger');

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (1, 'alice-token')");

    $db->exec('CREATE TRIGGER block_token_delete BEFORE DELETE ON login_tokens
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    assert_true(@wallos_revoke_login_token($db, 'alice-token') === false,
        'a failed delete says so');
    assert_true(@wallos_revoke_user_login_tokens($db, 1) === false,
        'and so does the per-user one');

    $db->exec('DROP TRIGGER block_token_delete');

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'),
        'the token is indeed still there');

    $db->close();
});

wallos_test('an absent token is still nothing, not a failure', function () {
    // The other half of the distinction: this must stay 0, or every ordinary
    // logout of a session without a remember-me token looks like an error.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_same(0, wallos_revoke_login_token($db, 'never-issued'), 'nothing to remove');
    assert_same(0, wallos_revoke_user_login_tokens($db, 1), 'none for this user');

    $db->close();
});

wallos_test('back-channel logout does not count a session whose token survived', function () {
    // The session row and the token are two writes. Deleting the row while the
    // token lives leaves the browser able to sign back in — into a session with
    // no oidc_sessions row, which no later back-channel logout can reach. The
    // provider, meanwhile, was told the session ended, and does not retry a
    // success.
    //
    // Driven rather than read: an earlier version of this case asserted that
    // the token check appeared before the counter in the source, which stayed
    // true when the `continue` was deleted and the session counted anyway.
    wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger');

    require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("UPDATE \"user\" SET oidc_sub = 'sub-alice' WHERE id = 1");
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (1, 'alice-token')");
    $db->exec("INSERT INTO oidc_sessions (user_id, sid, session_id, login_token)
               VALUES (1, 'sid-1', 'php-session-1', 'alice-token')");

    $db->exec('CREATE TRIGGER block_token_delete BEFORE DELETE ON login_tokens
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    $revoked = @wallos_oidc_revoke_sessions($db, null, 'sid-1');

    assert_same(0, $revoked, 'a session whose token survived is not counted as revoked');

    $db->exec('DROP TRIGGER block_token_delete');

    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'),
        'the token really did survive');

    $db->close();
});

wallos_test('back-channel logout counts a session it fully revoked', function () {
    // The other side, so the case above cannot pass by always returning 0.
    wallos_test_skip_unless_sqlite('driven through SQLite fixtures');

    require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("UPDATE \"user\" SET oidc_sub = 'sub-alice' WHERE id = 1");
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (1, 'alice-token')");
    $db->exec("INSERT INTO oidc_sessions (user_id, sid, session_id, login_token)
               VALUES (1, 'sid-1', 'php-session-1', 'alice-token')");

    $revoked = wallos_oidc_revoke_sessions($db, null, 'sid-1');

    assert_same(1, $revoked, 'counted');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'), 'the token is gone');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions'), 'and so is the session');

    $db->close();
});

wallos_test('logout notices a token it could not revoke', function () {
    $source = file_get_contents(WALLOS_ROOT . '/logout.php');

    assert_true(strpos($source, 'wallos_revoke_login_token($db, $sessionToken) === false') !== false,
        'the result is checked rather than discarded');
});
