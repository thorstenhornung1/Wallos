<?php
/*
  Test N — the OIDC remember-me cookie is a RESUME HANDLE, not an authenticator
  (OIDC Session Authority v2, Phase 3b: WP6 / §14).

  A remember-me cookie restores a session after PHP's own session state has been
  garbage-collected. For a LOCAL login that restore authenticates directly, and
  must keep doing so, unchanged. For an OIDC login it must NOT: the cookie only
  LOCATES the prior OIDC session and re-enters the Phase 2 silent-revalidation
  flow. Access is created only when the provider confirms a live browser session
  for the same (iss, sub) — so a stolen cookie ALONE grants nothing.

  These cases prove that contract end to end at the code's own seams:

    - an OIDC cookie restores the session (from_oidc, the row moved onto the new
      id) but the guard resolves REVALIDATION_REQUIRED, so NO protected access is
      served on the cookie by itself — even when the session was still inside its
      back-channel coverage window;
    - the token is stored HASHED at rest: login_tokens and the oidc_sessions row
      keep SHA-256(secret), never the raw cookie secret;
    - a raw token that matches no stored hash is refused — and so is the stored
      hash itself, presented as a cookie (a database-read attacker's only prize);
    - an OIDC cookie WITH a live provider session (prompt=none success, same
      iss/sub) revalidates back to VALID and access returns;
    - WITHOUT a live provider session (prompt=none → login_required) the flow
      falls to interactive login and never grants access;
    - a LOCAL remember-me still authenticates directly, unchanged.

  The two network touches — the token endpoint and the JWKS — are replaced in the
  child through the same wallos_oidc_token_endpoint_post / wallos_oidc_jwks_http_get
  seams the resume tests use, so no case here makes a request. Each subprocess
  case uses its own session id; the harness clears the session store itself.
*/

require_once WALLOS_ROOT . '/includes/auth_lifetime.php';
require_once WALLOS_ROOT . '/includes/oidc/resume.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/remember_me.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * Runs a PHP snippet as its own process against the fixture database (its path
 * travels in WALLOS_DB_PATH, set by wallos_test_open_database()).
 *
 * @param string $body PHP without the opening tag.
 * @return string
 */
function rm_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/rm-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    $runner = 'php ' . escapeshellarg($script) . ' 2>&1';
    exec($runner, $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/**
 * The RSA keypair the remember-me resume cases sign ID tokens with, generated
 * once per run. The children verify the returned token against its published
 * JWKS, so the resume path's signature check runs for real.
 *
 * @return array
 */
function rm_keypair()
{
    static $keypair = null;
    if ($keypair === null) {
        $keypair = wallos_test_rsa_keypair('remember-resume-key');
    }

    return $keypair;
}

/**
 * A child that drives the remember-me restore and the guard against the fixture
 * database, with the token endpoint and JWKS replaced before the code is loaded
 * (the function_exists / seam guards leave the stubs in place).
 *
 * @param array  $responses queued token-endpoint answers, one per attempt
 * @param string $body      PHP that drives the code under test
 * @return string
 */
function rm_child($responses, $body)
{
    $keypair = rm_keypair();

    return rm_run_php(
        '$GLOBALS["responses"] = ' . var_export($responses, true) . ';' . "\n"
        . 'function wallos_oidc_token_endpoint_post($url, $fields, $resolve = null) {' . "\n"
        . '    $next = array_shift($GLOBALS["responses"]);' . "\n"
        . '    return $next === null' . "\n"
        . '        ? ["body" => false, "status" => 0, "error" => "no answer queued"]' . "\n"
        . '        : $next;' . "\n"
        . '}' . "\n"
        . '$GLOBALS["jwks"] = ' . var_export(json_encode($keypair['jwks']), true) . ';' . "\n"
        . 'function wallos_oidc_jwks_http_get($jwksUri, $resolve = null) {' . "\n"
        . '    return ["body" => $GLOBALS["jwks"], "status" => 200];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/resume.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/remember_me.php', true) . ';' . "\n"
        . $body
    );
}

/** The effective OIDC settings the resume exchange reads. Literal IPs, so nothing resolves a name. */
function rm_settings()
{
    return [
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'token_url' => 'https://93.184.216.34/token',
        'redirect_url' => 'https://wallos.example.com/login.php',
        'authorization_url' => 'https://93.184.216.34/authorize',
        'user_info_url' => 'https://93.184.216.34/userinfo',
        'scopes' => 'openid email profile',
        'issuer' => '',
        'jwks_uri' => 'https://93.184.216.34/jwks',
    ];
}

/** A resume transaction with a chosen nonce and verifier. */
function rm_transaction($nonce, $verifier, $targetSessionId)
{
    return [
        'mode' => 'resume',
        'state' => 'state-' . $nonce,
        'nonce' => $nonce,
        'pkce_verifier' => $verifier,
        'return_to' => 'subscriptions.php',
        'target_oidc_session_id' => $targetSessionId,
        'created_at' => time(),
    ];
}

/** A real RS256-signed ID token that passes the full WP2 validation. */
function rm_id_token($claims)
{
    $claims = array_merge([
        'aud' => 'wallos',
        'exp' => time() + 300,
        'iat' => time(),
    ], $claims);

    $keypair = rm_keypair();

    return wallos_test_sign_jwt($keypair['private'], $claims, ['kid' => $keypair['kid']]);
}

/** A provider token answer carrying a fresh access token and the given ID token. */
function rm_success_answer($idToken)
{
    return [
        'body' => json_encode([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'id_token' => $idToken,
            'token_type' => 'Bearer',
            'expires_in' => 300,
        ]),
        'status' => 200,
        'error' => null,
    ];
}

/**
 * An account linked to a provider subject, an OIDC remember-me token stored the
 * way oidc_login.php now mints it — HASHED at rest, the raw secret only in the
 * cookie — and a recorded OIDC session that is perfectly VALID and still inside
 * its back-channel coverage window. That last part is the point of WP6: even a
 * still-valid, in-coverage session must be forced to revalidate when it is
 * reached through a remember-me cookie.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId the PHP session id the row is registered under
 * @param string         $rawSecret the raw cookie secret (hashed at rest)
 * @param string         $subject   the account's provider subject (oidc_sub)
 * @return void
 */
function rm_fixture($db, $sessionId, $rawSecret, $subject = 'subject-A')
{
    wallos_test_create_user($db, 1, 'alice');

    $link = $db->prepare('UPDATE "user" SET oidc_sub = :sub WHERE id = 1');
    $link->bindValue(':sub', $subject);
    $link->execute();

    $stored = hash('sha256', $rawSecret);

    $addToken = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :t, 1)');
    $addToken->bindValue(':t', $stored);
    $addToken->execute();

    wallos_oidc_register_session($db, 1, 'sid-old', $sessionId, $stored, 'old.id.token');

    $park = $db->prepare('UPDATE oidc_sessions
                             SET status = :status,
                                 refresh_token = :refresh,
                                 access_token_issued_at = :issued,
                                 access_token_expires_at = :expires,
                                 backchannel_coverage_until = :expires
                           WHERE session_id = :sid');
    $park->bindValue(':status', 'valid');
    $park->bindValue(':refresh', 'stored-refresh-token');
    $park->bindValue(':issued', time());
    $park->bindValue(':expires', time() + 3600);
    $park->bindValue(':sid', $sessionId);
    $park->execute();
}

// -------- resume-only: restored, but NOT authenticated, and stored hashed -----

wallos_test('Test N: an OIDC remember-me cookie is a resume handle — restored but not '
    . 'authenticated, and stored hashed', function () {
    $db = wallos_test_open_database();
    rm_fixture($db, 'rm-n', 'raw-secret-N', 'subject-A');

    // A perfectly valid, in-coverage OIDC session presented ONLY as a cookie.
    $out = rm_child([],
        '$_COOKIE["wallos_login"] = "alice|raw-secret-N|1";' . "\n"
        . 'session_id("rm-n-start"); session_start();' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no") . "\n";' . "\n"
        . 'echo "from_oidc=" . (isset($_SESSION["from_oidc"]) && $_SESSION["from_oidc"] === true ? "yes" : "no") . "\n";' . "\n"
        . '$guard = wallos_oidc_session_authority($db);' . "\n"
        . 'echo "guard=" . $guard . "\n";' . "\n"
        . 'echo "valid=" . (wallos_oidc_current_session_is_valid($db) ? "yes" : "no") . "\n";' . "\n"
        . 'echo "movedstatus=" . $db->scalar("SELECT status FROM oidc_sessions WHERE session_id = :s", [":s" => session_id()]);');

    assert_contains('restored=yes', $out, 'the cookie is recognised and its OIDC session located (' . $out . ')');
    assert_contains('from_oidc=yes', $out, 'and the session is rebuilt as an OIDC one, not a local one');
    assert_contains('guard=revalidation_required', $out,
        'but the cookie ALONE does not authenticate — the session must be revalidated first');
    assert_contains('valid=no', $out,
        'so no protected request proceeds from the cookie by itself (test N)');
    assert_contains('movedstatus=revalidation_required', $out,
        'the moved authority row is parked in revalidation, even though it was still inside coverage');

    // At rest it is the hash, never the raw secret.
    $storedLoginToken = (string) $db->scalar('SELECT token FROM login_tokens WHERE user_id = 1');
    assert_same(hash('sha256', 'raw-secret-N'), $storedLoginToken,
        'login_tokens stores SHA-256(secret), not the raw token');
    assert_true($storedLoginToken !== 'raw-secret-N', 'the raw secret is never at rest');
    $storedRowToken = (string) $db->scalar('SELECT login_token FROM oidc_sessions WHERE sid = :s', [':s' => 'sid-old']);
    assert_same(hash('sha256', 'raw-secret-N'), $storedRowToken,
        'the oidc_sessions row keeps the same hash, so revocation still matches it');
    $db->close();
});

wallos_test('Test N: a token that matches no stored hash is refused, and so is the stored hash itself',
    function () {
        $db = wallos_test_open_database();
        rm_fixture($db, 'rm-guess', 'raw-secret-guess', 'subject-A');

        // A cookie whose token is neither the raw secret nor any stored hash.
        $guess = rm_child([],
            '$_COOKIE["wallos_login"] = "alice|totally-wrong-token|1";' . "\n"
            . 'session_id("rm-guess-start"); session_start();' . "\n"
            . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
            . 'echo "restored=" . ($r !== false ? "yes" : "no");');
        assert_contains('restored=no', $guess, 'a guessed token grants nothing (' . $guess . ')');

        // The STORED HASH presented as the cookie token — the only thing a
        // database-read attacker holds — is refused too, because its OWN hash is
        // not the stored hash. Hashing at rest is not reversible into access.
        $stored = hash('sha256', 'raw-secret-guess');
        $replayHash = rm_child([],
            '$_COOKIE["wallos_login"] = "alice|' . $stored . '|1";' . "\n"
            . 'session_id("rm-hash-start"); session_start();' . "\n"
            . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
            . 'echo "restored=" . ($r !== false ? "yes" : "no");');
        assert_contains('restored=no', $replayHash,
            'the stored hash presented as a cookie is refused (' . $replayHash . ')');
        $db->close();
    });

// ------------------- WITH a live provider session: revalidate to access -------

wallos_test('Test N: an OIDC remember-me WITH a live provider session revalidates to access',
    function () {
        $db = wallos_test_open_database();
        rm_fixture($db, 'rm-live', 'raw-secret-live', 'subject-A');

        // The browser DOES still have a session at the provider, so the silent
        // prompt=none round-trip returns the same (iss, sub).
        $idToken = rm_id_token([
            'iss' => 'https://issuer.example',
            'sub' => 'subject-A',
            'nonce' => 'nonce-live',
            'sid' => 'sid-new',
        ]);
        $tx = rm_transaction('nonce-live', 'verifier-live', 'placeholder');

        $out = rm_child([rm_success_answer($idToken)],
            '$_COOKIE["wallos_login"] = "alice|raw-secret-live|1";' . "\n"
            . 'session_id("rm-live-start"); session_start();' . "\n"
            . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
            . '$before = wallos_oidc_session_authority($db);' . "\n"
            // The resume exchange targets THIS regenerated session, which the
            // restore moved the authority row onto — exactly what the /oidc/callback
            // resume path (consume_resume_callback.php) does.
            . '$tx = ' . var_export($tx, true) . '; $tx["target_oidc_session_id"] = session_id();' . "\n"
            . '$settings = ' . var_export(rm_settings(), true) . ';' . "\n"
            . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", session_id());' . "\n"
            . '$after = wallos_oidc_session_authority($db);' . "\n"
            . 'echo "restored=" . ($r !== false ? "yes" : "no") . "\n";' . "\n"
            . 'echo "before=" . $before . "\n";' . "\n"
            . 'echo "outcome=" . $result["outcome"] . "\n";' . "\n"
            . 'echo "after=" . $after . "\n";' . "\n"
            . 'echo "valid=" . (wallos_oidc_current_session_is_valid($db) ? "yes" : "no");');

        assert_contains('restored=yes', $out, 'the cookie located the session (' . $out . ')');
        assert_contains('before=revalidation_required', $out,
            'the cookie alone left the session awaiting revalidation, not valid');
        assert_contains('outcome=revalidated', $out,
            'the live provider session for the same (iss, sub) confirms authority');
        assert_contains('after=valid', $out, 'and the session is VALID again');
        assert_contains('valid=yes', $out,
            'so access is restored — but only after the browser proved the OP session');
        $db->close();
    });

// ----------------- WITHOUT a live provider session: no access, ends cleanly ---

wallos_test('Test N: without a live provider session the silent revalidation cannot grant access',
    function () {
        // The cookie leaves the session in REVALIDATION_REQUIRED (proven above).
        // The browser's prompt=none round-trip then returns login_required because
        // there is no provider session; §22 classifies that as interactive login,
        // and the resume callback ENDS the local session rather than leaving it
        // half-open. The only thing that lifts revalidation_required to valid is a
        // successful exchange for the same (iss, sub) — never the cookie, never a
        // login_required.
        assert_same('interactive', wallos_oidc_classify_prompt_none_error('login_required'),
            'a provider that cannot authenticate silently means interactive login, never access');

        $callback = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_resume_callback.php');
        assert_contains('wallos_oidc_resume_end_session_and_login', $callback,
            'and the callback ends the local session on a prompt=none login_required');
        assert_contains("setcookie('wallos_login', ''", $callback,
            'clearing the remember-me cookie, so the browser is not signed straight back in');
    });

// --------------------------- the LOCAL path is untouched ----------------------

wallos_test('Test N: a LOCAL remember-me still authenticates directly, unchanged', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // A plain local token: stored verbatim, no OIDC marker, no oidc_sessions row.
    $local = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :t, 0)');
    $local->bindValue(':t', 'plain-local-secret');
    $local->execute();

    $out = rm_child([],
        '$_COOKIE["wallos_login"] = "alice|plain-local-secret|1";' . "\n"
        . 'session_id("rm-local-start"); session_start();' . "\n"
        . '$r = restoreSessionFromRememberMeCookie($db);' . "\n"
        . 'echo "restored=" . ($r !== false ? "yes" : "no") . "\n";' . "\n"
        . 'echo "from_oidc=" . (isset($_SESSION["from_oidc"]) ? "set" : "unset") . "\n";' . "\n"
        . 'echo "loggedin=" . (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true ? "yes" : "no") . "\n";' . "\n"
        . 'echo "valid=" . (wallos_oidc_current_session_is_valid($db) ? "yes" : "no");');

    assert_contains('restored=yes', $out, 'a local remember-me still restores directly (' . $out . ')');
    assert_contains('from_oidc=unset', $out, 'as a local session, not an OIDC one');
    assert_contains('loggedin=yes', $out, 'and it is authenticated on the spot');
    assert_contains('valid=yes', $out,
        'with immediate access — no revalidation, the local path is exactly as before');
    $db->close();
});
