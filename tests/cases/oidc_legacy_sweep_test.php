<?php
/*
  The one-time revoke of pre-v2 OIDC sessions (OIDC Session Authority v2,
  Phase 4 / WP10 / §25), migration 000086.

  The v2 trust model cannot prove that a session created before it validated the
  provider's iss/sub/nonce or ever confirmed its authority, so those sessions are
  revoked once and their users re-authenticate. The guard already reads a pre-v2
  row (no status) AS revoked, so it grants no access — but the row lingers inert,
  still holding a refresh token, an id token and a remember-me login_token. The
  live QA flagged exactly these "zombie" rows. This migration makes the cleanup
  explicit and leaves nothing pre-v2 behind.

  The rules it is measured against:
    * a pre-v2 OIDC row (status NULL or empty) is marked revoked, its reason
      recorded, and every credential on it cleared, and its remember-me row is
      deleted;
    * a post-v2 session (status 'valid') is untouched;
    * a local remember-me token — one no oidc_sessions row names — is untouched;
    * running it again changes nothing (idempotent).

  Run against both backends: the migration is required with $db in scope, exactly
  as migration_test.php runs a single migration, and it goes through the database
  boundary so it is backend-agnostic.
*/

require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';

/**
 * Inserts a pre-v2 OIDC session: a row with NO authority status, the way one
 * looked before migration 000082 existed. status is omitted so it reads back
 * NULL — the legacy marker the guard treats as revoked.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $sid
 * @param string         $sessionId
 * @param string         $loginToken
 * @return void
 */
function legacy_sweep_pre_v2_session($db, $userId, $sid, $sessionId, $loginToken)
{
    $stmt = $db->prepare('INSERT INTO oidc_sessions
                              (user_id, sid, session_id, login_token, id_token, refresh_token)
                          VALUES (:userId, :sid, :sessionId, :loginToken, :idToken, :refreshToken)');
    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':sid', $sid);
    $stmt->bindValue(':sessionId', $sessionId);
    $stmt->bindValue(':loginToken', $loginToken);
    $stmt->bindValue(':idToken', 'legacy.id.token');
    $stmt->bindValue(':refreshToken', 'legacy-refresh-token');
    $stmt->execute();
}

/**
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $token
 * @return void
 */
function legacy_sweep_login_token($db, $userId, $token)
{
    $stmt = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)');
    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':token', $token);
    $stmt->execute();
}

/**
 * @param WallosDatabase $db
 * @param string         $token
 * @return bool
 */
function legacy_sweep_token_exists($db, $token)
{
    return ((int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t', [':t' => $token])) > 0;
}

wallos_test('the sweep revokes a pre-v2 session and clears everything it held (WP10)',
    function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');

        // A pre-v2 OIDC session with a remember-me token, plus the login_tokens
        // row that token names.
        legacy_sweep_pre_v2_session($db, 1, 'sid-legacy', 'php-legacy', 'tok-legacy');
        legacy_sweep_login_token($db, 1, 'tok-legacy');

        require WALLOS_ROOT . '/migrations/000086.php';

        $row = $db->scalar('SELECT status FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']);
        assert_same('revoked', $row, 'the pre-v2 row is marked revoked');
        assert_same('legacy_pre_v2',
            $db->scalar('SELECT revocation_reason FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'with the auditable reason');
        assert_same('',
            (string) $db->scalar('SELECT refresh_token FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'the refresh token is cleared');
        assert_same('',
            (string) $db->scalar('SELECT id_token FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'the id token is cleared');
        assert_same('',
            (string) $db->scalar('SELECT login_token FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'the login token reference is cleared');
        assert_true((int) $db->scalar('SELECT revoked_at FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']) > 0,
            'and the revocation moment is recorded');

        assert_true(!legacy_sweep_token_exists($db, 'tok-legacy'),
            'the remember-me row the session named is gone');

        $db->close();
    }
);

wallos_test('the sweep leaves a post-v2 session and local remember-me tokens alone (WP10)',
    function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');

        // A post-v2 session, born 'valid' the way wallos_oidc_register_session
        // makes one, carrying its own remember-me token.
        wallos_oidc_register_session($db, 1, 'sid-valid', 'php-valid', 'tok-valid');
        legacy_sweep_login_token($db, 1, 'tok-valid');

        // A purely local remember-me token: no oidc_sessions row names it, so the
        // sweep must never reach it.
        legacy_sweep_login_token($db, 1, 'tok-local');

        // And a pre-v2 row alongside, so the sweep has something to do and the
        // "untouched" assertions are not vacuously true.
        legacy_sweep_pre_v2_session($db, 1, 'sid-legacy', 'php-legacy', 'tok-legacy');
        legacy_sweep_login_token($db, 1, 'tok-legacy');

        require WALLOS_ROOT . '/migrations/000086.php';

        assert_same('valid',
            $db->scalar('SELECT status FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-valid']),
            'the post-v2 session is still valid');
        assert_same('tok-valid',
            $db->scalar('SELECT login_token FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-valid']),
            'its login token reference is intact');
        assert_true(legacy_sweep_token_exists($db, 'tok-valid'),
            'and its remember-me row survives');

        assert_true(legacy_sweep_token_exists($db, 'tok-local'),
            'the local remember-me token is untouched');

        // The pre-v2 one still went.
        assert_true(!legacy_sweep_token_exists($db, 'tok-legacy'),
            'while the pre-v2 remember-me row was swept');

        $db->close();
    }
);

wallos_test('the sweep is idempotent — a second run changes nothing (WP10)',
    function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');

        legacy_sweep_pre_v2_session($db, 1, 'sid-legacy', 'php-legacy', 'tok-legacy');
        legacy_sweep_login_token($db, 1, 'tok-legacy');
        wallos_oidc_register_session($db, 1, 'sid-valid', 'php-valid', 'tok-valid');
        legacy_sweep_login_token($db, 1, 'tok-valid');

        require WALLOS_ROOT . '/migrations/000086.php';

        // The state after the first run, captured so the second run can be shown
        // to leave it identical.
        $revokedAt = (int) $db->scalar('SELECT revoked_at FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']);
        $sessionCount = (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions');
        $tokenCount = (int) $db->scalar('SELECT COUNT(*) FROM login_tokens');

        // A second run: the pre-v2 row now carries 'revoked', so nothing matches
        // status IS NULL OR status = '' and no row is touched a second time.
        require WALLOS_ROOT . '/migrations/000086.php';

        assert_same('revoked',
            $db->scalar('SELECT status FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'the revoked row stays revoked');
        assert_same($revokedAt,
            (int) $db->scalar('SELECT revoked_at FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-legacy']),
            'the revocation moment is not rewritten');
        assert_same('valid',
            $db->scalar('SELECT status FROM oidc_sessions WHERE session_id = :s', [':s' => 'php-valid']),
            'the valid session is still valid after a re-run');
        assert_same($sessionCount, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions'),
            'no session row appeared or vanished');
        assert_same($tokenCount, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens'),
            'and no remember-me row was deleted the second time');

        $db->close();
    }
);
