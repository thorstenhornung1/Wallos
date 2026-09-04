<?php
// A cache for the provider's JWKS, keyed by its jwks_uri.
//
// backchannel-logout.php is unauthenticated by design — the token's signature
// is its authentication — so before this an anonymous POST forced an outbound
// fetch of the provider's signing keys on every request (finding F2): a
// denial-of-service lever, and an amplification path, through a public
// endpoint. The keys are cached here the same way the discovery document is
// (migration 000063), so a fresh copy is served without touching the network,
// and refreshed within the hour when the provider rotates them.
//
// Created through the database boundary rather than a backend-specific schema
// query, so it runs on both SQLite and the PostgreSQL baseline. IF NOT EXISTS
// keeps it idempotent — the PostgreSQL install applies schema.sql, which already
// carries this table, and then finds every migration recorded as applied.
$db->exec("CREATE TABLE IF NOT EXISTS oidc_jwks_cache (
    jwks_uri TEXT PRIMARY KEY,
    document TEXT NOT NULL,
    fetched_at INTEGER NOT NULL
)");
