<?php
/*
  The same callback arriving twice.

  Measured on the production instance on 2026-09-17: a browser on an unstable
  connection sent the OIDC callback, abandoned it after 61 ms, and sent it
  again. PHP-FPM finished the first request anyway - it checked the state,
  consumed it, redeemed the authorization code and signed the person in - and
  the second request then redeemed the same code, which the provider refuses
  because an authorization code is single-use. What the person saw was a failed
  login for a login that had in fact succeeded.

  Two things had to be true for that:

    1. the consumption lived only in memory while the token exchange ran, and
       the login then regenerated the session id, which deleted the file it
       would have been written to, so the waiting request was handed the
       pre-consumption state;
    2. the session the login established had an id the browser never received,
       because the response carrying it went to a connection that was gone.

  These cases drive both in separate processes against one session directory,
  because the mechanism is the file, and a single process cannot show it.
*/

require_once WALLOS_ROOT . '/includes/oidc/transactions.php';

/**
 * Runs PHP in its own process against a fixed session directory and id.
 *
 * @param string $sessionDir
 * @param string $sessionId
 * @param string $body PHP to run once the session is open.
 * @return string everything the process printed
 */
function oidc_double_callback_run($sessionDir, $sessionId, $body)
{
    $script = tempnam(sys_get_temp_dir(), 'wallos_oidc_');

    file_put_contents($script,
        '<?php' . "\n"
        . 'ini_set("session.save_path", ' . var_export($sessionDir, true) . ');' . "\n"
        . 'ini_set("session.use_cookies", "0");' . "\n"
        . 'session_id(' . var_export($sessionId, true) . ');' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/transactions.php', true) . ';' . "\n"
        . $body . "\n");

    $output = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output);
    unlink($script);

    return implode("\n", $output);
}

/**
 * Starts PHP in its own process without waiting for it.
 *
 * @return string the file its output will appear in
 */
function oidc_double_callback_spawn($sessionDir, $sessionId, $body)
{
    $script = tempnam(sys_get_temp_dir(), 'wallos_oidc_');
    $out = $script . '.out';

    file_put_contents($script,
        '<?php' . "\n"
        . 'ini_set("session.save_path", ' . var_export($sessionDir, true) . ');' . "\n"
        . 'ini_set("session.use_cookies", "0");' . "\n"
        . 'session_id(' . var_export($sessionId, true) . ');' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/transactions.php', true) . ';' . "\n"
        . $body . "\n");

    exec('php ' . escapeshellarg($script) . ' > ' . escapeshellarg($out) . ' 2>&1 &');

    return $out;
}

wallos_test('the consumed state is on disk before the token exchange, not after it', function () {
    $dir = sys_get_temp_dir() . '/wallos-oidc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // The session as it stands when the provider redirects back.
    oidc_double_callback_run($dir, $sessionId, '
        $_SESSION["oidc_transactions"] = ["state-1" => ["mode" => "login", "created_at" => time()]];
        echo "prepared\n";
    ');

    $file = $dir . '/sess_' . $sessionId;
    assert_contains('state-1', file_get_contents($file), 'the transaction is in the session file');

    // The first callback: it consumes the transaction and then spends a second
    // and a half on the token exchange, exactly as the real one does.
    $out = oidc_double_callback_spawn($dir, $sessionId, '
        $transaction = wallos_oidc_consume_transaction("state-1");
        echo "consumed=" . ($transaction !== null ? "yes" : "no") . "\n";
        usleep(1500000);
    ');

    // Read the file while that exchange is still running. This is what a second
    // callback carrying the same code is handed - and if the consumption is
    // still only in memory, what it is handed is a state it may consume again,
    // which redeems the authorization code a second time and fails.
    usleep(600000);
    $duringExchange = file_get_contents($file);

    sleep(2);
    $first = file_get_contents($out);
    @unlink($out);

    assert_contains('consumed=yes', $first, 'the first callback consumed the transaction');
    assert_true(strpos($duringExchange, 'state-1') === false,
        'and the state is gone from the session file while the exchange is still running');

    array_map('unlink', glob($dir . '/sess_*'));
    rmdir($dir);
});

wallos_test('the session a lost response established can still be reached', function () {
    $dir = sys_get_temp_dir() . '/wallos-oidc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $oldId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    // The login, the way oidc_login.php does it: regenerate without deleting
    // the old session, then leave it pointing at the new one. In production
    // the response carrying that new id went to a connection already gone.
    $login = oidc_double_callback_run($dir, $oldId, '
        $previous = session_id();
        session_regenerate_id(false);
        wallos_oidc_leave_handover($previous, session_id());
        $_SESSION["loggedin"] = true;
        $_SESSION["username"] = "alice";
        session_write_close();
        echo "new=" . session_id() . "\n";
    ');

    assert_contains('new=', $login, 'the login established a session under a new id');
    preg_match('/new=(\S+)/', $login, $matches);
    $newId = $matches[1] ?? '';
    assert_true($newId !== '' && $newId !== $oldId, 'and that id is a different one');

    // The browser retries with the only id it ever received.
    $retry = oidc_double_callback_run($dir, $oldId, '
        $followed = wallos_oidc_follow_handover();
        echo "followed=" . ($followed ?? "(none)") . "\n";
        echo "loggedin=" . (!empty($_SESSION["loggedin"]) ? "yes" : "no") . "\n";
        echo "username=" . ($_SESSION["username"] ?? "(none)") . "\n";
    ');

    assert_contains('followed=' . $newId, $retry, 'the retry follows the pointer to the new session');
    assert_contains('loggedin=yes', $retry, 'and lands signed in, rather than being sent back to the login page');
    assert_contains('username=alice', $retry, 'in the session the first attempt established');

    array_map('unlink', glob($dir . '/sess_*'));
    rmdir($dir);
});

wallos_test('the old session carries the pointer and nothing else', function () {
    $dir = sys_get_temp_dir() . '/wallos-oidc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $oldId = 'dddddddddddddddddddddddddddd';

    // Whatever the browser's session held before the login - including an id
    // somebody else planted - is worth no more afterwards than it was before.
    $login = oidc_double_callback_run($dir, $oldId, '
        $_SESSION["planted"] = "value";
        $previous = session_id();
        session_regenerate_id(false);
        wallos_oidc_leave_handover($previous, session_id());
        $_SESSION["loggedin"] = true;
        session_write_close();
        echo "new=" . session_id() . "\n";
    ');

    assert_contains('new=', $login, 'the login ran');

    $old = oidc_double_callback_run($dir, $oldId, '
        echo "loggedin=" . (!empty($_SESSION["loggedin"]) ? "yes" : "no") . "\n";
        echo "planted=" . ($_SESSION["planted"] ?? "(gone)") . "\n";
        echo "keys=" . implode(",", array_keys($_SESSION)) . "\n";
    ');

    assert_contains('loggedin=no', $old, 'the old id is not a signed-in session');
    assert_contains('planted=(gone)', $old, 'and nothing it held before survived');
    assert_contains('keys=oidc_handover', $old, 'it holds the pointer, and only that');

    array_map('unlink', glob($dir . '/sess_*'));
    rmdir($dir);
});

wallos_test('a pointer past its two minutes is dropped rather than followed', function () {
    $dir = sys_get_temp_dir() . '/wallos-oidc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $oldId = 'ffffffffffffffffffffffffffff';
    $newId = 'gggggggggggggggggggggggggggg';

    oidc_double_callback_run($dir, $newId, '
        $_SESSION["loggedin"] = true;
        echo "ready\n";
    ');

    $stale = oidc_double_callback_run($dir, $oldId, '
        $_SESSION["oidc_handover"] = ["session_id" => "' . $newId . '", "at" => time() - 3600];
        session_write_close();
        session_id("' . $oldId . '");
        session_start();
        $followed = wallos_oidc_follow_handover();
        echo "followed=" . ($followed ?? "(none)") . "\n";
        echo "pointer=" . (isset($_SESSION["oidc_handover"]) ? "kept" : "dropped") . "\n";
    ');

    assert_contains('followed=(none)', $stale, 'an hour-old pointer is not followed');
    assert_contains('pointer=dropped', $stale, 'and it is removed, so it cannot be tried again later');

    array_map('unlink', glob($dir . '/sess_*'));
    rmdir($dir);
});
