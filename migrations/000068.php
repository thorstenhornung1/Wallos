<?php
// Adds id_token to oidc_sessions.
//
// The end-session request needs id_token_hint, and the token lived only in
// the PHP session — which every container restart destroys. The remember-me
// restore brought from_oidc back but not the token, so the first logout after
// a deploy sent post_logout_redirect_uri with no hint, and a certified
// provider answers that with 400 (#123). Stored with the session row, the
// token survives what the PHP session does not.
//
// Existing rows get an empty string: their token is genuinely gone, and the
// logout builder degrades to a bare end-session request for them. The column
// fills as those sessions sign in again.

// Through the boundary, not a pragma: the 5.8.0 PostgreSQL baseline records
// the chain up to 000063, so everything after it runs on both backends when
// an installation upgrades.
if (!$db->columnExists('oidc_sessions', 'id_token')) {
    $db->exec("ALTER TABLE oidc_sessions ADD COLUMN id_token TEXT DEFAULT ''");
}

// SQLite does not physically store ALTER TABLE defaults in existing rows, so
// PHP's SQLite3 extension may return NULL for them. Backfill explicitly.
$db->exec("UPDATE oidc_sessions SET id_token = '' WHERE id_token IS NULL");
