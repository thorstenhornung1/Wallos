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
