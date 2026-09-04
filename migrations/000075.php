<?php
// Marks the remember-me tokens that belong to an OIDC session.
//
// A remember-me token restores a session after PHP's own session state has been
// collected. For an OIDC login the restore has to stay an OIDC restore: the
// rebuilt session must carry from_oidc so back-channel logout keeps reaching it,
// and if the identity provider has already ended the session the cookie must not
// resurrect it as an ordinary local one the provider can no longer touch.
//
// The link that says "this token is an OIDC token" was the oidc_sessions row
// alone. That is enough while the row exists, and says nothing once it is gone:
// a revoked OIDC token and a plain local token are then indistinguishable, and
// the restore would read the missing row as "not OIDC" and hand out exactly the
// independent local session the provider revoked to prevent. This column is the
// durable half of that link — set when the token is minted for an OIDC login,
// so a restore that finds the mark but no row knows the session was revoked and
// refuses, rather than guessing from an absence.
//
// Through the database boundary rather than a backend-specific schema query, so
// it runs on both SQLite and the PostgreSQL baseline.
if (!$db->columnExists('login_tokens', 'from_oidc')) {
    $db->exec("ALTER TABLE login_tokens ADD COLUMN from_oidc INTEGER DEFAULT 0");
}

// The file-backed backend does not store an ALTER TABLE default into rows that
// already exist and may hand back NULL for them, so the default is written out
// rather than assumed. Tokens minted before this migration are treated as local
// (0): the ones that were OIDC will be re-marked at the account's next OIDC
// sign-in, and until then they behave as they did before this column existed.
$db->exec("UPDATE login_tokens SET from_oidc = 0 WHERE from_oidc IS NULL");
