<?php
/*
  Sessions at rest have a bounded lifetime.

  Nothing used to garbage-collect login_tokens or oidc_sessions: a session
  that died by PHP GC instead of an explicit logout left its remember-token —
  a working credential — at rest indefinitely, and since #123 its id_token
  beside it. The security review of the #123 architecture made bounded
  retention a condition: nothing in either table may outlive the 30-day
  remember-me cookie that is the only way back into it.
*/

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 * Local to this file: the runner loads only the case files the filter
 * matches. The script path is generated and quoted; nothing a request could
 * reach.
 *
 * @param string $body
 * @return array{output: string, status: int}
 */
function retention_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/retention-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('nothing in the session tables outlives the remember-me cookie', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // One row on each side of the boundary, in both tables. 31 and 29 days,
    // because the cookie lives 30: the older pair is unreachable by any
    // client and must go, the younger pair still answers a real cookie.
    $insertToken = $db->prepare('INSERT INTO login_tokens (user_id, token, timestamp) VALUES (1, :token, :ts)');
    foreach ([['stale-token', 31], ['live-token', 29]] as [$token, $age]) {
        $insertToken->bindValue(':token', $token, SQLITE3_TEXT);
        $insertToken->bindValue(':ts', gmdate('Y-m-d H:i:s', time() - $age * 86400), SQLITE3_TEXT);
        $insertToken->execute();
        $insertToken->reset();
    }

    $insertSession = $db->prepare('INSERT INTO oidc_sessions (user_id, sid, session_id, login_token, id_token, created_at)
                                   VALUES (1, :sid, :sessionId, :token, :idToken, :ts)');
    foreach ([['stale-sid', 'stale-token', 31], ['live-sid', 'live-token', 29]] as [$sid, $token, $age]) {
        $insertSession->bindValue(':sid', $sid, SQLITE3_TEXT);
        $insertSession->bindValue(':sessionId', 'php-' . $sid, SQLITE3_TEXT);
        $insertSession->bindValue(':token', $token, SQLITE3_TEXT);
        $insertSession->bindValue(':idToken', 'jwt-' . $sid, SQLITE3_TEXT);
        $insertSession->bindValue(':ts', gmdate('Y-m-d H:i:s', time() - $age * 86400), SQLITE3_TEXT);
        $insertSession->execute();
        $insertSession->reset();
    }

    $run = retention_run_php('require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/cleanupsessions.php', true) . ';');

    assert_same(0, $run['status'], 'the cleanup finishes cleanly (output: ' . $run['output'] . ')');

    $tokens = [];
    $result = $db->query('SELECT token FROM login_tokens ORDER BY token');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tokens[] = $row['token'];
    }
    assert_same(['live-token'], $tokens, 'the stale remember-token is gone, the live one stays');

    $sids = [];
    $result = $db->query('SELECT sid FROM oidc_sessions ORDER BY sid');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sids[] = $row['sid'];
    }
    assert_same(['live-sid'], $sids, 'the stale OIDC row and its id_token are gone, the live one stays');

    $db->close();
});
