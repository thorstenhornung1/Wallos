<?php
/*
  The Silent Resume Guard — the authoritative recovery path (OIDC Session
  Authority v2, Phase 2: WP5, WP1, §8/§10/§13/§22/§23).

  When a session is past its back-channel coverage boundary the guard resolves
  REVALIDATION_REQUIRED. Rather than force an interactive re-login, the browser
  makes a silent prompt=none Authorization Code round-trip. These cases prove the
  five hard behaviours of that round-trip and the transaction machinery under it:

    I  idle user, provider session still alive: prompt=none succeeds, the same
       (iss, sub) comes back, and the session is VALID again.
    K  a different subject comes back: no account switch, no access, the session
       is revoked and the user logs in normally.
    L  the provider is unreachable during revalidation: SUSPENDED, state
       preserved, nothing spent, nothing revoked.
    C  the ID token's nonce does not match this transaction: rejected.
    S  two transactions (two tabs / two sessions) complete independently.

  The one network touch is wallos_oidc_token_endpoint_post(), guarded by
  function_exists; the child processes define their own before loading the code,
  so no case here makes a request.
*/

require_once WALLOS_ROOT . '/includes/auth_lifetime.php';
require_once WALLOS_ROOT . '/includes/oidc/transactions.php';
require_once WALLOS_ROOT . '/includes/oidc/resume.php';
require_once WALLOS_ROOT . '/includes/oidc/backchannel.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * Runs a PHP snippet as its own process against the fixture database (its path
 * travels in WALLOS_DB_PATH, set by wallos_test_open_database()).
 *
 * Local to this file so the runner can load it alone.
 *
 * @param string $body PHP without the opening tag.
 * @return string
 */
function resume_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/resume-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    $runner = 'php ' . escapeshellarg($script) . ' 2>&1';
    exec($runner, $output, $status);
    unlink($script);

    return implode("\n", $output);
}

/**
 * A child that stands in for the provider's token endpoint and drives the resume
 * code against the fixture database. The stub is defined before the code is
 * loaded, so the function_exists guard in refresh.php leaves it in place.
 *
 * @param array  $responses queued transport answers, one per attempt
 * @param string $body      PHP that drives the code under test
 * @return string
 */
function resume_provider_child($responses, $body)
{
    $keypair = resume_keypair();

    return resume_run_php(
        // A throwaway session store keeps a stale sess_<id> from a prior run out
        // of the way (#148); each case also uses a unique session id.
        '$sp = session_save_path(); if ($sp === "") { $sp = sys_get_temp_dir(); }' . "\n"
        . 'foreach (glob($sp . "/sess_*") as $f) { @unlink($f); }' . "\n"
        . '$GLOBALS["responses"] = ' . var_export($responses, true) . ';' . "\n"
        . 'function wallos_oidc_token_endpoint_post($url, $fields, $resolve = null) {' . "\n"
        . '    $next = array_shift($GLOBALS["responses"]);' . "\n"
        . '    return $next === null' . "\n"
        . '        ? ["body" => false, "status" => 0, "error" => "no answer queued"]' . "\n"
        . '        : $next;' . "\n"
        . '}' . "\n"
        // The JWKS the WP2 validator verifies the returned ID token against. Stubbed
        // before the code loads (function_exists guard), so no case makes a request;
        // the token was signed by resume_keypair() in the parent.
        . '$GLOBALS["jwks"] = ' . var_export(json_encode($keypair['jwks']), true) . ';' . "\n"
        . 'function wallos_oidc_jwks_http_get($jwksUri, $resolve = null) {' . "\n"
        . '    return ["body" => $GLOBALS["jwks"], "status" => 200];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/database/connection.php', true) . ';' . "\n"
        . '$db = wallos_database_connect();' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/resume.php', true) . ';' . "\n"
        . $body
    );
}

/** The effective OIDC settings the resume exchange reads. Literal IPs, so nothing resolves a name. */
function resume_settings($issuer = '')
{
    return [
        'client_id' => 'wallos',
        'client_secret' => 'confidential',
        'token_url' => 'https://93.184.216.34/token',
        'redirect_url' => 'https://wallos.example.com/login.php',
        'authorization_url' => 'https://93.184.216.34/authorize',
        'user_info_url' => 'https://93.184.216.34/userinfo',
        'scopes' => 'openid email profile',
        'issuer' => $issuer,
        // The full ID-token validator (WP2) verifies the returned token's
        // signature against these keys; a literal IP so nothing resolves a name.
        'jwks_uri' => 'https://93.184.216.34/jwks',
    ];
}

/**
 * The RSA keypair the resume tests sign ID tokens with, generated once per run.
 * The children verify against its published JWKS, so the resume path's signature
 * check is exercised for real (WP2) rather than waved through.
 *
 * @return array
 */
function resume_keypair()
{
    static $keypair = null;
    if ($keypair === null) {
        $keypair = wallos_test_rsa_keypair('resume-test-key');
    }

    return $keypair;
}

/** A resume transaction with a chosen nonce and verifier. */
function resume_transaction($nonce, $verifier, $targetSessionId, $returnTo = 'subscriptions.php')
{
    return [
        'mode' => 'resume',
        'state' => 'state-' . $nonce,
        'nonce' => $nonce,
        'pkce_verifier' => $verifier,
        'return_to' => $returnTo,
        'target_oidc_session_id' => $targetSessionId,
        'created_at' => time(),
    ];
}

/**
 * A real RS256-signed ID token carrying the given claims. The audience, expiry
 * and issued-at are filled with defaults the validator accepts unless the case
 * overrides them, so a case names only the claims it cares about (sub, nonce, …)
 * and still gets a token that passes the full WP2 validation.
 */
function resume_id_token($claims)
{
    $claims = array_merge([
        'aud' => 'wallos',
        'exp' => time() + 300,
        'iat' => time(),
    ], $claims);

    $keypair = resume_keypair();

    return wallos_test_sign_jwt($keypair['private'], $claims, ['kid' => $keypair['kid']]);
}

/** A provider token answer carrying a fresh access token and the given ID token. */
function resume_success_answer($idToken, $refreshToken = 'rotated-refresh-token', $expiresIn = 300)
{
    return [
        'body' => json_encode([
            'access_token' => 'fresh-access-token',
            'refresh_token' => $refreshToken,
            'id_token' => $idToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]),
        'status' => 200,
        'error' => null,
    ];
}

/**
 * An account with a provider subject, and a recorded OIDC session parked in
 * REVALIDATION_REQUIRED with its coverage boundary in the past — the state the
 * guard leaves an idle session in before a resume.
 *
 * @param WallosDatabase $db
 * @param string         $sessionId
 * @param string         $subject   the account's provider subject (oidc_sub)
 * @return void
 */
function resume_fixture($db, $sessionId, $subject = 'subject-A')
{
    wallos_test_create_user($db, 1, 'alice');

    $link = $db->prepare('UPDATE "user" SET oidc_sub = :sub WHERE id = 1');
    $link->bindValue(':sub', $subject);
    $link->execute();

    wallos_oidc_register_session($db, 1, 'sid-old', $sessionId, 'remember-token', 'old.id.token');

    $addToken = $db->prepare('INSERT INTO login_tokens (user_id, token, from_oidc) VALUES (1, :t, 1)');
    $addToken->bindValue(':t', 'remember-token');
    $addToken->execute();

    // Move the row into REVALIDATION_REQUIRED with an expired coverage boundary
    // and a still-usable refresh token — exactly what the idle gap produces.
    $park = $db->prepare('UPDATE oidc_sessions
                             SET status = :status,
                                 refresh_token = :refresh,
                                 access_token_issued_at = :issued,
                                 access_token_expires_at = :expires,
                                 backchannel_coverage_until = :expires
                           WHERE session_id = :sid');
    $park->bindValue(':status', 'revalidation_required');
    $park->bindValue(':refresh', 'stored-refresh-token');
    $park->bindValue(':issued', time() - 600);
    $park->bindValue(':expires', time() - 300);
    $park->bindValue(':sid', $sessionId);
    $park->execute();
}

/** One column of the session row now, or null when the row is gone. */
function resume_session_column($db, $column, $sessionId)
{
    return $db->scalar('SELECT ' . $column . ' FROM oidc_sessions WHERE session_id = :s', [':s' => $sessionId]);
}

// --------------------------------------------------- WP1: the transaction map

wallos_test('a transaction is single-use: consumed once, then gone', function () {
    $_SESSION = [];
    $tx = wallos_oidc_create_transaction('login', 'subscriptions.php');

    assert_same(64, strlen($tx['state']), 'the state is 256 bits (64 hex chars), past the 128-bit floor');
    assert_true($tx['nonce'] !== '' && $tx['nonce'] !== $tx['state'], 'a distinct nonce is issued');
    assert_true($tx['pkce_verifier'] !== '', 'a PKCE verifier is issued');

    $first = wallos_oidc_consume_transaction($tx['state']);
    assert_true($first !== null, 'the transaction is found for its state');
    assert_same('login', $first['mode'], 'and it is the one that was created');

    $second = wallos_oidc_consume_transaction($tx['state']);
    assert_true($second === null, 'a second consume finds nothing — single-use, so a callback cannot replay');
});

wallos_test('Test S: two transactions complete independently', function () {
    // Two tabs, or a login and a resume, each under its own state. Consuming one
    // must not disturb the other, and each carries its own verifier, nonce and
    // (for a resume) target session.
    $_SESSION = [];
    $login = wallos_oidc_create_transaction('login', 'index.php');
    $resume = wallos_oidc_create_transaction('resume', 'stats.php', 'php-session-B');

    assert_true($login['state'] !== $resume['state'], 'the two have different states');
    assert_true($login['pkce_verifier'] !== $resume['pkce_verifier'], 'and different PKCE verifiers');

    // Consume the resume first; the login must survive untouched.
    $gotResume = wallos_oidc_consume_transaction($resume['state']);
    assert_true($gotResume !== null, 'the resume transaction is found');
    assert_same('resume', $gotResume['mode'], 'with its own mode');
    assert_same('php-session-B', $gotResume['target_oidc_session_id'], 'and its own target session');

    $gotLogin = wallos_oidc_consume_transaction($login['state']);
    assert_true($gotLogin !== null, 'the login transaction still completes independently');
    assert_same('index.php', $gotLogin['return_to'], 'with its own return target');
});

wallos_test('an expired transaction is refused', function () {
    $_SESSION = [];
    $tx = wallos_oidc_create_transaction('resume', 'index.php', 'sid-x');
    // Backdate it past the TTL.
    $_SESSION['oidc_transactions'][$tx['state']]['created_at'] = time() - wallos_oidc_transaction_ttl() - 5;

    assert_true(wallos_oidc_consume_transaction($tx['state']) === null,
        'a transaction older than the TTL is not honoured');
});

wallos_test('return_to is reduced to a safe local path', function () {
    assert_same('index.php', wallos_oidc_sanitize_return_to('https://evil.example/steal'),
        'an absolute URL is rejected');
    assert_same('index.php', wallos_oidc_sanitize_return_to('//evil.example'),
        'a scheme-relative URL is rejected');
    assert_same('index.php', wallos_oidc_sanitize_return_to('javascript:alert(1)'),
        'a javascript: URL is rejected');
    assert_same('/subscriptions.php?x=1', wallos_oidc_sanitize_return_to('/subscriptions.php?x=1'),
        'a same-origin path is kept');
    assert_same('subscriptions.php', wallos_oidc_sanitize_return_to('subscriptions.php'),
        'a relative path is kept');
});

// ------------------------------------------- §22: provider-error classification

wallos_test('prompt=none errors are classified per §22', function () {
    foreach (['login_required', 'interaction_required', 'consent_required', 'account_selection_required'] as $error) {
        assert_same('interactive', wallos_oidc_classify_prompt_none_error($error),
            $error . ' cannot be satisfied silently, so it means interactive login');
    }
    foreach (['temporarily_unavailable', 'server_error'] as $error) {
        assert_same('suspended', wallos_oidc_classify_prompt_none_error($error),
            $error . ' is the provider being unavailable, so it means SUSPENDED');
    }
    assert_same('interactive', wallos_oidc_classify_prompt_none_error('some_unknown_error'),
        'an unknown error fails safe to interactive login, never to access');
});

// ------------------------------------------- §8: the resume authorization URL

wallos_test('the resume authorize URL carries prompt=none, id_token_hint and fresh S256', function () {
    $tx = resume_transaction('nonce-url', 'verifier-url', 'sid');
    $url = wallos_oidc_build_resume_authorize_url(resume_settings(), $tx, 'previous.id.token');

    assert_contains('response_type=code', $url, 'it is an Authorization Code request');
    assert_contains('prompt=none', $url, 'made silently');
    assert_contains('id_token_hint=previous.id.token', $url, 'with the previous ID token as the hint');
    assert_contains('state=' . $tx['state'], $url, 'carrying this transaction state');
    assert_contains('nonce=nonce-url', $url, 'and its nonce');
    assert_contains('code_challenge_method=S256', $url, 'with a fresh S256 challenge');
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', 'verifier-url', true)), '+/', '-_'), '=');
    assert_contains('code_challenge=' . $expectedChallenge, $url, 'derived from this transaction verifier');
});

wallos_test('the resume authorize URL omits the hint when there is none', function () {
    $tx = resume_transaction('n', 'v', 'sid');
    $url = wallos_oidc_build_resume_authorize_url(resume_settings(), $tx, null);
    assert_not_contains('id_token_hint', $url, 'a session that carried no ID token sends no hint');
});

// ------------------------------------------------------------ Test I: success

wallos_test('Test I: an idle session whose provider session is still alive resumes to VALID', function () {
    $db = wallos_test_open_database();
    resume_fixture($db, 'res-i', 'subject-A');

    $tx = resume_transaction('nonce-i', 'verifier-i', 'res-i');
    $idToken = resume_id_token(['iss' => 'https://issuer.example', 'sub' => 'subject-A', 'nonce' => 'nonce-i', 'sid' => 'sid-new']);

    $out = resume_provider_child([resume_success_answer($idToken)],
        '$tx = ' . var_export($tx, true) . ';' . "\n"
        . '$settings = ' . var_export(resume_settings(), true) . ';' . "\n"
        . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", "res-i");' . "\n"
        // The session guard is checked AFTER the module writes the row and BEFORE
        // any echo, so session_start() does not see output already sent.
        . 'session_id("res-i"); session_start(); $_SESSION["from_oidc"] = true;' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . '$guard = wallos_oidc_session_authority($db);' . "\n"
        . 'echo "outcome=" . $result["outcome"] . "\n";' . "\n"
        . 'echo "guard=" . $guard;');

    assert_contains('outcome=revalidated', $out, 'the same (iss, sub) re-establishes authority (' . $out . ')');
    assert_contains('guard=valid', $out, 'and the session is VALID again on the next request');

    assert_same('valid', resume_session_column($db, 'status', 'res-i'), 'the row moved back to valid');
    assert_true((int) resume_session_column($db, 'authority_confirmed_at', 'res-i') > 0,
        'authority was confirmed by the browser round-trip');
    assert_true((int) resume_session_column($db, 'backchannel_coverage_until', 'res-i') > time(),
        'and coverage is fresh again');
    assert_same('sid-new', resume_session_column($db, 'sid', 'res-i'), 'the new provider sid is stored');
    assert_same('rotated-refresh-token', resume_session_column($db, 'refresh_token', 'res-i'),
        'the rotated refresh token is recorded');
    $db->close();
});

// ------------------------------------------------ Test K: different subject

wallos_test('Test K: a silent auth returning a different subject switches nothing and grants nothing', function () {
    $db = wallos_test_open_database();
    resume_fixture($db, 'res-k', 'subject-A');

    $tx = resume_transaction('nonce-k', 'verifier-k', 'res-k');
    // The browser's provider session is a DIFFERENT account.
    $idToken = resume_id_token(['iss' => 'https://issuer.example', 'sub' => 'subject-B', 'nonce' => 'nonce-k', 'sid' => 'sid-b']);

    $out = resume_provider_child([resume_success_answer($idToken)],
        '$tx = ' . var_export($tx, true) . ';' . "\n"
        . '$settings = ' . var_export(resume_settings(), true) . ';' . "\n"
        . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", "res-k");' . "\n"
        . 'session_id("res-k"); session_start(); $_SESSION["from_oidc"] = true;' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . '$guard = wallos_oidc_session_authority($db);' . "\n"
        . 'echo "outcome=" . $result["outcome"] . " reason=" . $result["reason"] . "\n";' . "\n"
        . 'echo "guard=" . $guard;');

    assert_contains('outcome=account_mismatch', $out, 'a different subject is refused, never switched to (' . $out . ')');
    assert_contains('reason=subject', $out, 'and the reason is the subject mismatch');
    assert_contains('guard=revoked', $out, 'the local session is revoked — no access to the original account');

    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s', [':s' => 'res-k']),
        'the session row is gone');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM login_tokens WHERE token = :t', [':t' => 'remember-token']),
        'and its remember-me token with it');
    $db->close();
});

// -------------------------------------------- Test L: provider unavailable

wallos_test('Test L: a provider unreachable during revalidation is SUSPENDED, state preserved', function () {
    $db = wallos_test_open_database();
    resume_fixture($db, 'res-l', 'subject-A');

    $tx = resume_transaction('nonce-l', 'verifier-l', 'res-l');
    $timeout = ['body' => false, 'status' => 0, 'error' => 'Operation timed out'];

    $out = resume_provider_child([$timeout],
        '$tx = ' . var_export($tx, true) . ';' . "\n"
        . '$settings = ' . var_export(resume_settings(), true) . ';' . "\n"
        . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", "res-l");' . "\n"
        . 'session_id("res-l"); session_start(); $_SESSION["from_oidc"] = true;' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . '$guard = wallos_oidc_session_authority($db);' . "\n"
        . 'echo "outcome=" . $result["outcome"] . "\n";' . "\n"
        . 'echo "guard=" . $guard;');

    assert_contains('outcome=suspended', $out, 'an unreachable provider is SUSPENDED, not revocation (' . $out . ')');
    assert_contains('guard=revalidation_required', $out,
        'the session is still merely awaiting revalidation, so no protected access is served');

    assert_same('revalidation_required', resume_session_column($db, 'status', 'res-l'),
        'the row status is unchanged — state preserved');
    assert_same('stored-refresh-token', resume_session_column($db, 'refresh_token', 'res-l'),
        'the refresh credential was neither spent nor rotated');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s', [':s' => 'res-l']),
        'and the row is not deleted');
    $db->close();
});

// ------------------------------------------------- Test C: nonce mismatch

wallos_test('Test C: an ID token whose nonce does not match this transaction is rejected', function () {
    $db = wallos_test_open_database();
    resume_fixture($db, 'res-c', 'subject-A');

    $tx = resume_transaction('nonce-c', 'verifier-c', 'res-c');
    // Right subject, WRONG nonce — a token minted for another request.
    $idToken = resume_id_token(['iss' => 'https://issuer.example', 'sub' => 'subject-A', 'nonce' => 'a-different-nonce', 'sid' => 'sid-x']);

    $out = resume_provider_child([resume_success_answer($idToken)],
        '$tx = ' . var_export($tx, true) . ';' . "\n"
        . '$settings = ' . var_export(resume_settings(), true) . ';' . "\n"
        . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", "res-c");' . "\n"
        . 'session_id("res-c"); session_start(); $_SESSION["from_oidc"] = true;' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/oidc/session_guard.php', true) . ';' . "\n"
        . '$guard = wallos_oidc_session_authority($db);' . "\n"
        . 'echo "outcome=" . $result["outcome"] . "\n";' . "\n"
        . 'echo "guard=" . $guard;');

    assert_contains('outcome=nonce_mismatch', $out, 'a nonce that does not match is rejected (' . $out . ')');
    assert_contains('guard=revoked', $out, 'and the session does not become valid — no access');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM oidc_sessions WHERE session_id = :s', [':s' => 'res-c']),
        'the session is ended rather than resumed');
    $db->close();
});

// ---------------------------------- §23: authorization re-sync on resume

wallos_test('a successful resume re-runs the provider role sync (§23)', function () {
    // A provider that has since dropped the admin group must not leave the role
    // standing. The resume passes the ID token claims through the same admin sync
    // login uses; here the claim is absent, so an admin role sourced from OIDC is
    // removed. A local admin role is left untouched (the sync writes only 'oidc').
    $db = wallos_test_open_database();
    resume_fixture($db, 'res-sync', 'subject-A');

    require_once WALLOS_ROOT . '/includes/user_roles.php';
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $tx = resume_transaction('nonce-sync', 'verifier-sync', 'res-sync');
    // admin_claim configured, but the token asserts no such group.
    $settings = resume_settings();
    $settings['admin_claim'] = 'groups';
    $settings['admin_value'] = 'wallos-admins';
    $idToken = resume_id_token(['iss' => 'https://issuer.example', 'sub' => 'subject-A', 'nonce' => 'nonce-sync', 'groups' => ['someone-else']]);

    $out = resume_provider_child([resume_success_answer($idToken)],
        '$tx = ' . var_export($tx, true) . ';' . "\n"
        . '$settings = ' . var_export($settings, true) . ';' . "\n"
        . '$result = wallos_oidc_resume_exchange_and_confirm($db, $settings, $tx, "auth-code", "res-sync");' . "\n"
        . 'echo "outcome=" . $result["outcome"];');

    assert_contains('outcome=revalidated', $out, 'the resume still succeeds (' . $out . ')');
    $sources = wallos_user_admin_sources($db, 1);
    assert_true(!in_array(WALLOS_ROLE_SOURCE_OIDC, $sources, true),
        'the provider-granted admin role is dropped because it is no longer asserted');
    assert_true(in_array(WALLOS_ROLE_SOURCE_LOCAL, $sources, true),
        'the local admin role is left untouched');
    $db->close();
});

// -------------------------- the entry points and #159 wiring are in place

wallos_test('the revalidate entry point starts a resume transaction and redirects silently', function () {
    $source = file_get_contents(WALLOS_ROOT . '/oidc/revalidate.php');

    assert_true(wallos_test_file_calls('oidc/revalidate.php', 'wallos_oidc_create_transaction'),
        'it creates a resume transaction');
    assert_contains("wallos_oidc_create_transaction('resume'", $source, 'in resume mode');
    assert_true(wallos_test_file_calls('oidc/revalidate.php', 'wallos_oidc_build_resume_authorize_url'),
        'and builds the prompt=none authorize URL');
});

wallos_test('the guard hands XHR callers the #159 revalidation contract', function () {
    // REVALIDATION_REQUIRED over XHR/REST answers a 401 whose JSON names the code
    // the one JS handler switches on and the URL it navigates to.
    $guard = file_get_contents(WALLOS_ROOT . '/includes/oidc/session_guard.php');
    assert_contains("'code' => 'oidc_revalidation_required'", $guard, 'the code is emitted');
    assert_contains("'revalidation_url'", $guard, 'with a revalidation URL beside it');
    assert_true(wallos_test_file_calls('includes/oidc/session_guard.php', 'wallos_oidc_revalidation_url'),
        'built from the caller context');
});

wallos_test('one centralized JS handler navigates to the revalidation URL', function () {
    // WP9/#159: exactly one place watches every fetch/XHR for the code and, on
    // seeing it, does window.location.assign(revalidation_url). It must not
    // replay the mutating request it interrupted.
    $js = file_get_contents(WALLOS_ROOT . '/scripts/oidc-reauth.js');
    assert_contains('oidc_revalidation_required', $js, 'it switches on the reauth code');
    assert_contains('window.location.assign', $js, 'and navigates to the revalidation URL');
    assert_contains('revalidation_url', $js, 'read from the response body');

    // And it is served on authenticated pages.
    $header = file_get_contents(WALLOS_ROOT . '/includes/header.php');
    assert_contains('scripts/oidc-reauth.js', $header, 'header.php serves the handler');
});

// ---------------- #166: a central logout gets its own honest login-page message

wallos_test('#166: a prompt=none central logout redirects with the distinct oidc_logged_out code', function () {
    // A prompt=none that comes back login_required (or a sibling) is the provider
    // having ended the session centrally — §22 classifies it 'interactive'. That
    // branch must carry its OWN code so the login page can say the provider signed
    // the user out, rather than reusing the generic timeout/state-lost code that
    // reads like a defect (the QA report behind #166).
    $callback = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_resume_callback.php');

    assert_contains('login.php?error=oidc_logged_out', $callback,
        'the central-logout branch redirects with the distinct code');
    assert_not_contains('oidc_session_expired', $callback,
        'and no longer reuses the generic timeout/state-lost code');
    // The classification the branch turns on is the one #22 already fixes for
    // login_required, so the distinct code lands exactly on the central logout.
    assert_same('interactive', wallos_oidc_classify_prompt_none_error('login_required'),
        'login_required is the central-logout case that reaches this redirect');
});

wallos_test('#166: the genuine session-lost path keeps the generic oidc_session_expired code', function () {
    // The real timeout/state-lost case — a callback whose session held no
    // transaction at all — must still show the generic message, unchanged.
    $callback = file_get_contents(WALLOS_ROOT . '/includes/oidc/consume_oidc_callback.php');

    assert_contains('oidc_session_expired', $callback,
        'a dropped session with no transaction still shows the generic timeout/state-lost message');
    assert_not_contains('oidc_logged_out', $callback,
        'the central-logout code belongs only to the prompt=none login_required path');
});

wallos_test('#166: login.php maps oidc_logged_out to its own message key, and keeps the generic one', function () {
    $login = file_get_contents(WALLOS_ROOT . '/login.php');

    assert_contains('"oidc_logged_out" => "oidc_logged_out"', $login,
        'the login page recognizes the central-logout code and resolves it to its own key');
    assert_contains('"oidc_session_expired" => "oidc_session_expired"', $login,
        'while the generic code stays mapped for the genuine timeout/state-lost case');
});

wallos_test('#166: the oidc_logged_out message resolves in en and de and falls back to English', function () {
    // Drive the real translate() exactly as login.php does: the per-locale array
    // for a hit, and en.php as the fallback for a locale that lacks the key.
    $out = resume_run_php(
        'chdir(' . var_export(WALLOS_ROOT, true) . ');' . "\n"
        . '$_COOKIE["language"] = "de";' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/i18n/languages.php', true) . ';' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/i18n/getlang.php', true) . ';' . "\n"
        . '$i18n = null; require ' . var_export(WALLOS_ROOT . '/includes/i18n/de.php', true) . '; $de = $i18n;' . "\n"
        . '$i18n = null; require ' . var_export(WALLOS_ROOT . '/includes/i18n/en.php', true) . '; $en = $i18n;' . "\n"
        . 'echo "de=" . translate("oidc_logged_out", $de) . "\n";' . "\n"
        . 'echo "en=" . translate("oidc_logged_out", $en) . "\n";' . "\n"
        . 'echo "generic=" . translate("oidc_session_expired", $en) . "\n";' . "\n"
        . 'echo "fallback=" . translate("oidc_logged_out", ["unrelated" => "x"]) . "\n";' . "\n"
        . 'echo "dehas=" . (array_key_exists("oidc_logged_out", $de) ? "yes" : "no");'
    );

    assert_contains('de=Du wurdest zentral abgemeldet. Bitte neu anmelden.', $out,
        'the German message resolves (' . $out . ')');
    assert_contains('en=You were signed out by your login provider. Please sign in again.', $out,
        'the English message resolves');
    assert_contains('generic=The login took too long or the session was lost. Please try again.', $out,
        'and the generic timeout/state-lost message is unchanged');
    assert_contains('fallback=You were signed out by your login provider. Please sign in again.', $out,
        'a locale without the key falls back to English via translate()');
    assert_contains('dehas=yes', $out, 'the German locale carries its own translation');
});
