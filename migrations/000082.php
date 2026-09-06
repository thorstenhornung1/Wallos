<?php
// Turns oidc_sessions into an authority record, for the session state machine
// that replaces the boolean guard (OIDC Session Authority v2, Phase 1).
//
// The hole this closes: a successful refresh in the idle gap used to make a
// session VALID. A refresh token is an OAuth credential, not proof the
// provider's browser session still lives — authentik's refresh tokens are
// configured to outlive the AuthenticatedSession they were minted for — so a
// killed provider session left the refresh token usable and the guard trusted
// it. The guard now runs a four-state machine (valid / revalidation_required /
// suspended / revoked) instead, and these columns are what it reads.
//
//   status                     the resolved authority state. A row with no
//                              status is a legacy session that predates the
//                              model and cannot prove it validated iss/sub/
//                              nonce; the guard reads a NULL/empty status as
//                              revoked, so a legacy session signs in once more
//                              and comes back with a real status (§25/WP10).
//                              Set to 'valid' when a session is registered.
//   backchannel_coverage_until the moment past which the provider may have
//                              ended the session without Wallos hearing. Equal
//                              to access_token_expires_at: while a live access
//                              token exists the provider can still build a
//                              back-channel logout from it; once it expires that
//                              guarantee is gone and the browser must revalidate
//                              before any protected work. 0 means "no boundary
//                              known", which reads as covered — a session with
//                              no access-token timing behaves as it did before.
//   authority_confirmed_at     when authority was last established by something
//                              the model trusts: an initial login, or (Phase 2)
//                              a browser revalidation. A refresh never sets it.
//   revoked_at / revocation_reason  filled when a session is marked revoked
//                              rather than deleted (the mark-not-delete path a
//                              later phase moves revocation onto).
//
// Through the database boundary rather than a backend-specific schema query, so
// it runs on both SQLite and the PostgreSQL baseline: the 5.8.0 PostgreSQL
// baseline records the chain up to 000063, so everything after it has to run on
// both. columnExists/tableExists rather than a pragma, for the same reason the
// #144 and #123 migrations use it — the file-backed backend refuses to prepare
// against a missing column while PostgreSQL fails at execute, so the two are
// asked explicitly instead of inferred from a failed statement.

// status is added WITHOUT a default on purpose, and is NOT backfilled: an
// existing row must read back NULL so the guard treats it as a legacy session
// to be revalidated once, rather than silently blessing it as valid. Every new
// session sets status = 'valid' explicitly when it is registered.
if (!$db->columnExists('oidc_sessions', 'status')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN status TEXT');
}

// The coverage boundary and the confirmation moment default to 0 ("unknown"),
// which the guard reads as "no boundary to enforce" — the pre-#144 behaviour
// for a session that carries no access-token timing.
if (!$db->columnExists('oidc_sessions', 'backchannel_coverage_until')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN backchannel_coverage_until INTEGER DEFAULT 0');
}

if (!$db->columnExists('oidc_sessions', 'authority_confirmed_at')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN authority_confirmed_at INTEGER DEFAULT 0');
}

if (!$db->columnExists('oidc_sessions', 'revoked_at')) {
    $db->exec('ALTER TABLE oidc_sessions ADD COLUMN revoked_at INTEGER DEFAULT 0');
}

if (!$db->columnExists('oidc_sessions', 'revocation_reason')) {
    $db->exec("ALTER TABLE oidc_sessions ADD COLUMN revocation_reason TEXT DEFAULT ''");
}

// The file-backed backend does not physically store an ALTER TABLE default into
// rows that already exist and may hand back NULL for them, so the defaults are
// written out rather than assumed — except status, which is meant to stay NULL
// for legacy rows so the guard revalidates them.
$db->exec('UPDATE oidc_sessions SET backchannel_coverage_until = 0 WHERE backchannel_coverage_until IS NULL');
$db->exec('UPDATE oidc_sessions SET authority_confirmed_at = 0 WHERE authority_confirmed_at IS NULL');
$db->exec('UPDATE oidc_sessions SET revoked_at = 0 WHERE revoked_at IS NULL');
$db->exec("UPDATE oidc_sessions SET revocation_reason = '' WHERE revocation_reason IS NULL");
