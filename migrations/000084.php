<?php
// Adds an "issuer" column to the "user" table, so an OIDC identity is bound to
// the canonical (issuer, subject) pair rather than to the subject alone (OIDC
// Session Authority v2, Phase 3a / WP3 / §12).
//
// The immutable identity key for an OIDC account is (iss, sub): a subject is
// only unique WITHIN one issuer, so binding on sub alone lets a second provider
// — or an attacker who can mint a token at a different issuer carrying the same
// sub — collide with an existing account. The subject stays in oidc_sub; this
// records which issuer minted it.
//
// The scoped deliverable is the (iss, sub) SEMANTICS via this column, not the
// fully normalized oidc_identities(UNIQUE(iss,sub)) table the spec sketches for
// multi-provider installs — that is deferred (a household tracker has one
// provider). An existing account has oidc_sub but no issuer; the login path
// treats an empty issuer as a legacy row it may adopt, backfilling it on the
// next successful sign-in, so nobody is locked out by the stricter key.
//
// Through the columnExists boundary rather than a pragma, exactly as migrations
// 000082/000083 do, so it runs on both SQLite and the PostgreSQL baseline: the
// file-backed backend refuses to prepare against a missing column while
// PostgreSQL fails at execute, so the presence of the column is asked
// explicitly instead of inferred from a failed statement. "user" is quoted
// because it is a reserved word on PostgreSQL, where this migration runs raw on
// the upgrade path. Left NULL for existing rows on purpose (no backfill here):
// a NULL/empty issuer is exactly the legacy marker the login path adopts.
if (!$db->columnExists('user', 'issuer')) {
    $db->exec('ALTER TABLE "user" ADD COLUMN issuer TEXT');
}
