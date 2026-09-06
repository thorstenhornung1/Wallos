<?php

/**
 * How long a Wallos login persists locally, defined in exactly one place.
 *
 * Thirty days used to be written out — 30 * 24 * 60 * 60, or 86400 * 30 — in
 * login.php, checksession.php, connect_endpoint.php, logout.php and
 * oidc_login.php, once for the PHP session cookie's lifetime and once for the
 * wallos_login cookie's expiry. Five copies of one policy is five chances for
 * them to drift, and drift here is not cosmetic: the number bounds how long a
 * browser stays signed in.
 *
 * The semantics matter more than the value. This is a MAXIMUM LOCAL PERSISTENCE
 * period, not an authentication grant. Expiry is one way a session ends; for an
 * OIDC session, revocation by the identity provider is another, and it takes
 * precedence — a cookie with twenty-nine days left on it does not keep a session
 * the provider has ended. The default is thirty days and an operator changing it
 * changes it here, not in five files that must agree.
 *
 * @return int seconds
 */
function wallos_auth_max_session_lifetime()
{
    return 30 * 24 * 60 * 60;
}

/**
 * Whether the current request reached Wallos over HTTPS (OIDC Session Authority
 * v2, §15).
 *
 * The auth cookies — the PHP session cookie and `wallos_login` — carry the
 * `Secure` attribute so a browser never sends them over plaintext, which stops a
 * network attacker on an http hop from reading a live session credential. But
 * `Secure` on a cookie set over plain http means the browser drops it entirely,
 * so a developer running Wallos on http://localhost would be unable to stay
 * signed in. The flag is therefore conditioned on the request actually being
 * secure.
 *
 * Direct TLS shows up as $_SERVER['HTTPS']; a reverse proxy that terminates TLS
 * and forwards http (the common self-hosted deployment the blueprint calls out)
 * says so in X-Forwarded-Proto. Either is accepted, because in both the browser
 * ↔ Wallos leg the cookie travels on is HTTPS. The forwarded header is only
 * meaningful behind a proxy the operator controls, which is exactly the
 * deployment it describes.
 *
 * @return bool
 */
function wallos_request_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        ? strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']))
        : '';
    // A proxy may list several protocols ("https, http"); the first is the one
    // the browser used.
    if ($forwardedProto !== '') {
        $first = trim(explode(',', $forwardedProto)[0]);
        if ($first === 'https') {
            return true;
        }
    }

    return false;
}
