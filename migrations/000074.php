<?php
// Adds the refresh token and the access token's own timings to oidc_sessions
// (issue #144).
//
// Back-channel logout reached a session for about five minutes after login and
// then stopped. The identity provider builds the notification for a deleted
// session out of the access tokens that belong to it, and Wallos never asked
// for a refresh token: the one access token it was handed at login expired,
// nothing replaced it, and the session stayed signed in for thirty days with no
// way to end it from the outside. Both ends reported success while nothing was
// sent.
//
// Four facts per session, deliberately facts rather than a schedule: when the
// current access token was issued, when it expires, the credential that can
// replace it, and whether the last attempt to do so failed. The moment to
// refresh is derived from the first two at read time, so an installation whose
// provider issues five-minute tokens and one whose provider issues hour-long
// ones both work without a number written down anywhere.
//
// refresh_token is the most dangerous value in this table, and more dangerous
// than the id_token beside it. An id token is a spent assertion: it says a
// login happened and can be replayed at the end-session endpoint, nothing more.
// A refresh token mints new access tokens for as long as the provider's session
// lives — hours to days, not the five minutes an access token gets. It is
// handled like the id token in every mechanical respect (server side only,
// never in a cookie, never rendered, never written to a log, removed with the
// row at logout and at revocation), and the difference is worth stating out
// loud: whoever reads this table now holds something that still works, not only
// something that once did.
//
// Through the database boundary rather than a backend-specific schema query:
// the 5.8.0 PostgreSQL baseline records the chain up to 000063, so everything
// after it has to run on both backends.
if (!$db->columnExists('oidc_sessions', 'refresh_token')) {
    $db->exec("ALTER TABLE oidc_sessions ADD COLUMN refresh_token TEXT DEFAULT ''");
}

if (!$db->columnExists('oidc_sessions', 'access_token_issued_at')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN access_token_issued_at INTEGER DEFAULT 0');
}

if (!$db->columnExists('oidc_sessions', 'access_token_expires_at')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN access_token_expires_at INTEGER DEFAULT 0');
}

// A failed refresh does not sign anybody out — a provider having a bad minute
// must not become a logout storm — so the failure has to be visible somewhere.
// These two columns are that somewhere: a session with refresh_failed_at set is
// one the provider can no longer reach by back-channel logout, and it says so
// without anybody having to read a log.
if (!$db->columnExists('oidc_sessions', 'refresh_failed_at')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN refresh_failed_at INTEGER DEFAULT 0');
}

if (!$db->columnExists('oidc_sessions', 'refresh_error')) {
    $db->exec("ALTER TABLE oidc_sessions ADD COLUMN refresh_error TEXT DEFAULT ''");
}

// The file-backed backend does not physically store ALTER TABLE defaults in
// existing rows, and its extension may hand back NULL for them, so the defaults
// are written out rather than assumed. Sessions that signed in before this
// migration carry no refresh token: they keep working and stay unreachable by
// back-channel logout until their next sign-in, which is the state they were
// already in.
$db->exec("UPDATE oidc_sessions SET refresh_token = '' WHERE refresh_token IS NULL");
$db->exec('UPDATE oidc_sessions SET access_token_issued_at = 0 WHERE access_token_issued_at IS NULL');
$db->exec('UPDATE oidc_sessions SET access_token_expires_at = 0 WHERE access_token_expires_at IS NULL');
$db->exec('UPDATE oidc_sessions SET refresh_failed_at = 0 WHERE refresh_failed_at IS NULL');
$db->exec("UPDATE oidc_sessions SET refresh_error = '' WHERE refresh_error IS NULL");
