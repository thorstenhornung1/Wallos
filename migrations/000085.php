<?php
// A replay cache for OIDC back-channel logout tokens, keyed (issuer, jti)
// (OIDC Session Authority v2, Phase 4 / WP8 / §18).
//
// Back-Channel Logout 1.0 requires every logout token to carry a jti and asks
// the RP to keep a short cache of the ones it has acted on, "until the token
// expires", so a token replayed to the unauthenticated endpoint has no second
// effect. A duplicate (issuer, jti) is treated as already-processed: the
// endpoint still answers 200 (the desired state — that session is not signed in
// — already holds) but performs no second revocation side-effect. The row is
// scoped by issuer as well as jti because jti is only unique within one issuer,
// and a Wallos install may be reconfigured from one provider to another over its
// life; the pair is the key the specification names.
//
//   issuer      the token's iss, so two providers that ever mint the same jti do
//               not collide.
//   jti         the token's jti, the per-token identifier.
//   expires_at  the token's own exp. The entry is useless once the token it
//               guards can no longer be presented (a token past exp is rejected
//               as expired before the replay check is ever reached), so the
//               endpoint prunes rows whose expires_at has passed on each valid
//               token — opportunistic, no cron, the same bargain the JWKS and
//               discovery caches strike.
//
// Created through the database boundary rather than a backend-specific schema
// query, so it runs on both SQLite and the PostgreSQL baseline. The composite
// PRIMARY KEY (issuer, jti) is what the endpoint's INSERT ... ON CONFLICT relies
// on to tell a first sighting from a replay. IF NOT EXISTS keeps it idempotent —
// the PostgreSQL install applies schema.sql, which already carries this table,
// and then finds every migration recorded as applied.
if ($db->exec("CREATE TABLE IF NOT EXISTS oidc_logout_replay (
    issuer TEXT NOT NULL,
    jti TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (issuer, jti)
)") === false) {
    error_log('Wallos: migration 000085 could not create oidc_logout_replay: ' . $db->lastErrorMsg());

    return false;
}
