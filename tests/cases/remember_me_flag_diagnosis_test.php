<?php
/*
  What the remember-me token skip actually depends on — proven, not argued.

  A first reading of this called it an "OIDC-only account takeover": disable
  password login, and a forged cookie logs anyone in. That reading was wrong,
  and the error was mistaking two different settings for one:

    * oauth_settings.password_login_disabled — the OIDC "disable password
      login" toggle, which only hides the password form. This is what an
      OIDC-only installation sets.
    * admin.login_disabled — Wallos's single-user, no-login mode. login.php
      authenticates user 1 with no password at all when this is on, and it can
      only be enabled with exactly one user and registrations closed.

  restoreSessionFromRememberMeCookie() branched on admin.login_disabled and
  never looked at password_login_disabled. So an OIDC-only install
  (password_login_disabled = 1, admin.login_disabled = 0) took the else branch
  and required the token correctly. The skip was reachable only in the
  single-user mode that is already passwordless — where the token adding
  nothing is a redundant weakness, not a takeover.

  These cases run the original upstream function and the fixed one against a
  forged cookie under both flag states, so the severity is a measurement.
*/

/**
 * Runs a given remember_me implementation in its own process and reports
 * whether a cookie restored a session.
 *
 * @param string $rememberMePath the file defining restoreSessionFromRememberMeCookie
 * @param string $cookie         the wallos_login cookie value to present
 * @return string 'yes' or 'no'
 */
function diagnosis_run($rememberMePath, $cookie)
{
    $script = WALLOS_TEST_TMP . '/diag-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n"
        . '$_COOKIE["wallos_login"] = ' . var_export($cookie, true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export($rememberMePath, true) . ';' . "\n"
        . 'echo restoreSessionFromRememberMeCookie($db) !== false ? "yes" : "no";');

    // Only stdout: this helper's verdict is the "yes"/"no" the script echoes,
    // and the restore now writes a rejection line to stderr for the forgery
    // shape (a real username, a token that matches nothing). That line is
    // asserted on its own below, through a helper that keeps stderr; here it is
    // noise that would turn an exact "no" into "…refused.\nno".
    $output = [];
    $command = 'php ' . escapeshellarg($script) . ' 2>/dev/null';
    exec($command, $output);
    unlink($script);

    return trim(implode("\n", $output));
}

/**
 * @param object $db
 * @param int    $value
 */
function diagnosis_set_login_disabled($db, $value)
{
    $stmt = $db->prepare('UPDATE admin SET login_disabled = :value WHERE id = 1');
    $stmt->bindValue(':value', $value);
    $stmt->execute();
}

$original = WALLOS_ROOT . '/tests/fixtures/remember_me_original_upstream.php';
$fixed = WALLOS_ROOT . '/includes/remember_me.php';
$forged = 'alice|WRONG-TOKEN-ENTIRELY-MADE-UP|1';
$genuine = 'alice|the-real-token|1';

wallos_test('the original code required the token whenever login was not the no-auth mode', function () use ($original, $forged) {
    // The upstream original is SQLite-only: it queries `FROM user` unquoted,
    // which PostgreSQL rejects as a reserved word (our fork quotes "user").
    // So the specimen cannot run there — it is what upstream does, faithfully.
    if (wallos_test_skip_unless_sqlite('reproduces upstream, whose remember_me is SQLite-only')) {
        return;
    }

    // This is the state an OIDC-only installation is in: password login is
    // disabled through the OIDC setting, which this function never reads, so
    // admin.login_disabled stays 0. The claim to disprove is that such an
    // installation is exploitable. It is not — even before the fix.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $insert = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :t)');
    $insert->bindValue(':t', 'the-real-token');
    $insert->execute();

    diagnosis_set_login_disabled($db, 0);

    assert_same('no', diagnosis_run($original, $forged),
        'a forged cookie is refused with admin.login_disabled = 0 — the OIDC-only state was never exposed');

    $db->close();
});

wallos_test('the original code skipped the token only in the single-user no-auth mode', function () use ($original, $forged) {
    if (wallos_test_skip_unless_sqlite('reproduces upstream, whose remember_me is SQLite-only')) {
        return;
    }

    // admin.login_disabled = 1 is where the skip lived. It is the mode in which
    // login.php already signs user 1 in with no password, so a redundant
    // weakness in an already-passwordless configuration — not a bypass of any
    // authentication that was otherwise in force.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $insert = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :t)');
    $insert->bindValue(':t', 'the-real-token');
    $insert->execute();

    diagnosis_set_login_disabled($db, 1);

    assert_same('yes', diagnosis_run($original, $forged),
        'the original code accepts a forged cookie only here — this is the real, narrow defect');

    $db->close();
});

wallos_test('the fix requires the token in both modes, and still admits a genuine cookie', function () use ($fixed, $forged, $genuine) {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $insert = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :t)');
    $insert->bindValue(':t', 'the-real-token');
    $insert->execute();

    foreach ([0, 1] as $flag) {
        diagnosis_set_login_disabled($db, $flag);
        assert_same('no', diagnosis_run($fixed, $forged),
            'the fixed code refuses the forged cookie with login_disabled = ' . $flag);
    }

    diagnosis_set_login_disabled($db, 1);
    assert_same('yes', diagnosis_run($fixed, $genuine),
        'and a genuine token still restores, even in the no-auth mode — the fix is not "refuse everybody"');

    $db->close();
});

wallos_test('the shapes of a cookie that must never restore', function () use ($fixed, $forged) {
    // The rest of the negative surface the spec names. All against the fixed
    // code; the flag is left at its default because the point is that none of
    // these depends on it any more.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $insert = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :t)');
    $insert->bindValue(':t', 'alice-token');
    $insert->execute();

    // A cookie that is not three fields never parses.
    assert_same('no', diagnosis_run($fixed, 'only-one-field'),
        'a malformed cookie is refused');

    // A username no user has.
    assert_same('no', diagnosis_run($fixed, 'nobody|alice-token|1'),
        'an unknown username is refused even with a token that exists for someone');

    // A real user who has never been remembered — no row to match.
    assert_same('no', diagnosis_run($fixed, 'bob|alice-token|1'),
        'a real token belonging to another user does not restore this one');

    // And bob, who has no token at all.
    assert_same('no', diagnosis_run($fixed, 'bob|anything|1'),
        'a user with no login_tokens row is refused');

    $db->close();
});

wallos_test('the fixed function reads neither login flag: the token alone decides', function () use ($fixed) {
    // The structural proof behind the behaviour: once the branch is gone, no
    // configuration flag can reopen the skip. And it never read the OIDC flag,
    // which is the whole reason the OIDC-only reading was wrong.
    $source = file_get_contents($fixed);

    assert_true(strpos($source, 'login_disabled') === false,
        'the fixed lookup no longer branches on admin.login_disabled');
    assert_true(strpos($source, 'password_login_disabled') === false,
        'and it never consulted the OIDC password-login setting — the two were always separate');
});

// ---------------------------------------- the one rejection worth logging

/**
 * Runs the fixed restore in its own process with a given cookie and returns
 * everything it wrote to stdout and stderr — error_log goes to stderr in the
 * CLI, so the rejection line lands here.
 *
 * @param string $cookie
 * @return string
 */
function rejection_log_run($cookie)
{
    $script = WALLOS_TEST_TMP . '/reject-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n"
        . '$_COOKIE["wallos_login"] = ' . var_export($cookie, true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'ini_set("session.save_path", ' . var_export(WALLOS_TEST_TMP, true) . ');' . "\n"
        . 'session_start();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . 'echo "restored=" . (restoreSessionFromRememberMeCookie($db) !== false ? "yes" : "no") . "\n";');

    $output = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output);
    unlink($script);

    return implode("\n", $output);
}

wallos_test('a rejected remember-me cookie is logged only for the forgery shape, and never the token',
    function () {
        // The calibrated signal. A cookie whose username resolves to a real
        // account but whose token matches no row is the shape of a forged cookie
        // or a revoked credential being replayed — worth one line. The ordinary
        // miss (an unknown username, a malformed cookie, a cleared post-logout
        // cookie) is not, or the line drowns in every visit. Both directions are
        // asserted, or a log that fired for everything would pass the first.
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');
        $insert = $db->prepare('INSERT INTO login_tokens (user_id, token) VALUES (1, :t)');
        $insert->bindValue(':t', 'the-real-token');
        assert_true($insert->execute() !== false, 'alice has a remembered session');
        $db->close();

        $marker = 'matches no active session';

        // Forgery shape: alice is real, the token is not hers.
        $forged = rejection_log_run('alice|not-the-token|1');
        assert_contains('restored=no', $forged, 'the forged cookie is refused (' . $forged . ')');
        assert_contains($marker, $forged, 'and the refusal is logged (' . $forged . ')');
        assert_contains('user 1', $forged, 'by the account id, so an operator can find it');
        assert_true(strpos($forged, 'not-the-token') === false,
            'and never the token value — a token in the log is a credential in the log');

        // Ordinary misses: nothing forensic to say, so nothing is said.
        $unknownUser = rejection_log_run('nobody|not-the-token|1');
        assert_contains('restored=no', $unknownUser, 'an unknown username is refused');
        assert_true(strpos($unknownUser, $marker) === false,
            'but not logged — the username resolved to no account (' . $unknownUser . ')');

        $malformed = rejection_log_run('only-one-field');
        assert_true(strpos($malformed, $marker) === false,
            'a malformed cookie is not logged either (' . $malformed . ')');

        // And a genuine cookie restores and says nothing.
        $genuine = rejection_log_run('alice|the-real-token|1');
        assert_contains('restored=yes', $genuine, 'the real cookie restores (' . $genuine . ')');
        assert_true(strpos($genuine, $marker) === false,
            'and a successful restore logs no rejection');
    });
