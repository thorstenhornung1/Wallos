<?php
// Adds the OIDC issuer as a stored setting, and a cache for what it discovers.
//
// Discovery only ran when OIDC_ISSUER was set as an environment variable, so an
// installation configured entirely through the admin interface had no discovery
// document — and therefore no JWKS, which meant back-channel logout refused
// every token, and no end_session_endpoint for RP-initiated logout.
//
// The cache is not an optimisation added on principle. The discovery document
// is fetched inside wallos_get_effective_oidc_configuration(), which login.php
// calls on every render: without a cache, every visit to the login page waits
// on an HTTP request to the identity provider, with a ten second timeout if the
// provider is unwell.

$columnQuery = $db->query("SELECT * FROM pragma_table_info('oauth_settings') WHERE name='issuer'");
$columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

if ($columnRequired) {
    $db->exec("ALTER TABLE oauth_settings ADD COLUMN issuer TEXT DEFAULT ''");
}

// SQLite does not physically store ALTER TABLE defaults in existing rows.
$db->exec("UPDATE oauth_settings SET issuer = '' WHERE issuer IS NULL");

$db->exec("CREATE TABLE IF NOT EXISTS oidc_discovery_cache (
    issuer TEXT PRIMARY KEY,
    document TEXT NOT NULL,
    fetched_at INTEGER NOT NULL
)");
