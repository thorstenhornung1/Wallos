<?php

/**
 * OIDC transactions — one per authorization round-trip, keyed by its state
 * (OIDC Session Authority v2, WP1 / §9).
 *
 * The login flow used to keep a single global `$_SESSION['oidc_state']` and a
 * matching `oidc_code_verifier`. Two browser tabs that each start an OIDC flow
 * overwrite each other's state, so the tab that finishes second fails the state
 * check even though nothing went wrong. And there is no room for a second KIND
 * of flow: the Silent Resume Guard (WP5) starts its own authorization request
 * while an ordinary login may also be in flight.
 *
 * So a flow is a transaction now, stored under its own state:
 *
 *   $_SESSION['oidc_transactions'][$state] = [
 *       'mode'                   => 'login' | 'resume',
 *       'state'                  => the CSRF/lookup token (256 bits),
 *       'nonce'                  => bound into the ID token the provider returns,
 *       'pkce_verifier'          => a fresh S256 verifier for this flow only,
 *       'return_to'              => a validated local relative path,
 *       'target_oidc_session_id' => the session a resume re-establishes (resume),
 *       'created_at'             => unix seconds, for the TTL,
 *   ]
 *
 * Each transaction is single-use (consumed on the callback), short-lived (a TTL
 * of minutes), and the map is bounded so a run of login-page views cannot grow
 * it without limit. The state is 256 bits of entropy — well past the 128-bit
 * floor §9 sets — and doubles as the CSRF token: an attacker returning a forged
 * state matches no key, and the lookup compares in constant time regardless.
 */

require_once __DIR__ . '/pkce.php';

/**
 * How long a transaction may sit unfinished. Minutes, because a real
 * authorization round-trip is seconds and anything older is abandoned.
 *
 * @return int seconds
 */
function wallos_oidc_transaction_ttl()
{
    return 600;
}

/**
 * The most transactions kept at once. A login page rendered repeatedly mints one
 * per view; this bounds that without needing a per-request quota (which §9's own
 * notes call gold-plating for a single-user box). Generous enough that a handful
 * of real tabs never evict each other.
 *
 * @return int
 */
function wallos_oidc_transaction_cap()
{
    return 12;
}

/**
 * A fresh state: 32 random bytes as hex, i.e. 256 bits. Past §9's 128-bit floor.
 *
 * @return string
 */
function wallos_oidc_generate_state()
{
    return bin2hex(random_bytes(32));
}

/**
 * A fresh nonce, bound into the authorization request and checked back out of
 * the ID token so a token minted for another request cannot be replayed here.
 *
 * @return string
 */
function wallos_oidc_generate_nonce()
{
    return bin2hex(random_bytes(32));
}

/**
 * Reduces a return-to target to a safe LOCAL relative path, or the default when
 * it is anything else.
 *
 * The value reaches Wallos from a query parameter and is later handed to a
 * redirect, so an unchecked one is an open redirect: `//evil.example`,
 * `https://evil.example`, `javascript:…`. Only a same-origin relative path is
 * allowed through — no scheme, no authority, no backslashes, no control bytes —
 * and everything else falls back to the application root.
 *
 * @param mixed  $returnTo
 * @param string $default
 * @return string
 */
function wallos_oidc_sanitize_return_to($returnTo, $default = 'index.php')
{
    if (!is_string($returnTo) || $returnTo === '') {
        return $default;
    }

    // No control characters (a newline here is a header-splitting redirect).
    if (preg_match('/[\x00-\x1f\x7f]/', $returnTo) === 1) {
        return $default;
    }

    // A scheme (http:, javascript:, data:) makes it non-local.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $returnTo) === 1) {
        return $default;
    }

    // A leading `//` (or `/\`) is scheme-relative — the browser reads the next
    // segment as a host. A backslash anywhere is folded to `/` by some browsers,
    // so `/\evil.example` would escape too.
    if (strpos($returnTo, '//') === 0 || strpos($returnTo, '/\\') === 0
        || strpos($returnTo, '\\') !== false) {
        return $default;
    }

    // Keep it to a bounded length; a legitimate in-app path is short.
    if (strlen($returnTo) > 2000) {
        return $default;
    }

    return $returnTo;
}

/**
 * Creates a transaction, stores it under its state, and returns it.
 *
 * @param string      $mode                 'login' or 'resume'
 * @param mixed       $returnTo             where to send the browser afterwards
 * @param string|null $targetOidcSessionId  the session a resume re-establishes
 * @return array the transaction
 */
function wallos_oidc_create_transaction($mode, $returnTo, $targetOidcSessionId = null)
{
    wallos_oidc_prune_transactions();

    $mode = $mode === 'resume' ? 'resume' : 'login';
    $state = wallos_oidc_generate_state();

    $transaction = [
        'mode' => $mode,
        'state' => $state,
        'nonce' => wallos_oidc_generate_nonce(),
        'pkce_verifier' => wallos_oidc_generate_code_verifier(),
        'return_to' => wallos_oidc_sanitize_return_to($returnTo),
        'target_oidc_session_id' => $mode === 'resume' ? (string) $targetOidcSessionId : null,
        'created_at' => time(),
    ];

    if (!isset($_SESSION['oidc_transactions']) || !is_array($_SESSION['oidc_transactions'])) {
        $_SESSION['oidc_transactions'] = [];
    }

    $_SESSION['oidc_transactions'][$state] = $transaction;
    wallos_oidc_bound_transactions();

    return $transaction;
}

/**
 * Drops transactions older than the TTL.
 *
 * @return void
 */
function wallos_oidc_prune_transactions()
{
    if (!isset($_SESSION['oidc_transactions']) || !is_array($_SESSION['oidc_transactions'])) {
        return;
    }

    $cutoff = time() - wallos_oidc_transaction_ttl();
    foreach ($_SESSION['oidc_transactions'] as $key => $transaction) {
        $createdAt = isset($transaction['created_at']) ? (int) $transaction['created_at'] : 0;
        if ($createdAt < $cutoff) {
            unset($_SESSION['oidc_transactions'][$key]);
        }
    }
}

/**
 * Enforces the cap, dropping the oldest transactions first.
 *
 * @return void
 */
function wallos_oidc_bound_transactions()
{
    if (!isset($_SESSION['oidc_transactions']) || !is_array($_SESSION['oidc_transactions'])) {
        return;
    }

    $cap = wallos_oidc_transaction_cap();
    if (count($_SESSION['oidc_transactions']) <= $cap) {
        return;
    }

    uasort($_SESSION['oidc_transactions'], function ($a, $b) {
        return ((int) ($a['created_at'] ?? 0)) <=> ((int) ($b['created_at'] ?? 0));
    });

    while (count($_SESSION['oidc_transactions']) > $cap) {
        array_shift($_SESSION['oidc_transactions']);
    }
}

/**
 * Locates and consumes the transaction for a state — single-use, so the same
 * callback cannot be replayed.
 *
 * The state is compared with hash_equals against every stored key, so a present
 * / absent decision never short-circuits on a shared prefix. An expired
 * transaction is removed and treated as absent.
 *
 * @param mixed $state
 * @return array|null the transaction, or null when there is none to consume
 */
function wallos_oidc_consume_transaction($state)
{
    if (!is_string($state) || $state === '') {
        return null;
    }

    if (!isset($_SESSION['oidc_transactions']) || !is_array($_SESSION['oidc_transactions'])) {
        return null;
    }

    $found = null;
    foreach ($_SESSION['oidc_transactions'] as $key => $transaction) {
        if (hash_equals((string) $key, $state)) {
            $found = $transaction;
            unset($_SESSION['oidc_transactions'][$key]);
        }
    }

    if ($found === null) {
        return null;
    }

    $createdAt = isset($found['created_at']) ? (int) $found['created_at'] : 0;
    if ($createdAt < time() - wallos_oidc_transaction_ttl()) {
        // Present but stale: consumed above so it cannot linger, and refused.
        return null;
    }

    return $found;
}
