<?php
// Adds post_logout_redirect_url to oauth_settings.
//
// Where the identity provider returns the user after ending the session. Empty
// means Wallos derives it from the redirect URL, which is the sensible default
// and what every existing installation gets.
//
// It is deliberately not the OIDC callback URI: that endpoint exists to
// complete a sign-in, and sending a returning logout through it is how a logout
// turns back into a login.

$columnQuery = $db->query("SELECT * FROM pragma_table_info('oauth_settings') WHERE name='post_logout_redirect_url'");
$columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

if ($columnRequired) {
    $db->exec("ALTER TABLE oauth_settings ADD COLUMN post_logout_redirect_url TEXT DEFAULT ''");
}

// SQLite does not physically store ALTER TABLE defaults in existing rows, so
// PHP's SQLite3 extension may return NULL for them. Backfill explicitly.
$db->exec("UPDATE oauth_settings SET post_logout_redirect_url = '' WHERE post_logout_redirect_url IS NULL");
