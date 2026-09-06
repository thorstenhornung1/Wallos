<?php
// One-time revoke of every pre-v2 OIDC session (OIDC Session Authority v2,
// Phase 4 / WP10 / §25).
//
// The v2 trust model changed what an OIDC session must be able to prove. A
// session created before the authority model cannot show that it validated the
// provider's iss/sub/nonce or that its authority was ever confirmed, so the
// specification asks that all pre-v2 OIDC sessions be revoked ONCE and their
// users re-authenticate. Local/password sessions are a different concern and are
// untouched — they have no oidc_sessions row, so nothing here reaches them.
//
// The guard already reads a pre-v2 row (status NULL or empty) AS revoked, so
// those rows grant no access. But they linger inert, still holding a refresh
// token, an id token and a remember-me login_token — the "zombie" rows the live
// QA flagged. This migration makes the cleanup explicit: it marks them revoked,
// clears every credential on them, and deletes the remember-me rows they name, so
// nothing pre-v2 survives with unproven authority.
//
// Deterministic and idempotent. A pre-v2 row is exactly one with no authority
// status (status IS NULL OR status = ''); a post-v2 session carries 'valid' (or a
// later state) and is never selected. After the first run those rows carry
// 'revoked', so a second run selects nothing and changes nothing — safe to
// re-apply, which is also why a fresh PostgreSQL install (schema.sql records this
// migration as already applied, with no sessions to sweep) is unaffected.
//
// Ordered after 000082, which added the status column, so the column is always
// present on the upgrade path; the guard is defensive belt-and-braces. Runs
// entirely through the database boundary, so it is backend-agnostic.

if (!$db->columnExists('oidc_sessions', 'status')) {
    // The authority model is not in place yet, so there are no pre-v2 rows to
    // reconcile against it. Nothing to do.
    return;
}

// The remember-me tokens go FIRST, while the login_token values are still on the
// rows to match them by (the UPDATE below clears them). A login_token is matched
// only when it belongs to a pre-v2 OIDC session row, so a local remember-me token
// — which no oidc_sessions row names — is never deleted. Empty login_token values
// (the column default) match nothing and are excluded so an empty-string token in
// login_tokens, were one ever present, is left alone.
if ($db->exec("DELETE FROM login_tokens
                WHERE token IN (
                    SELECT login_token FROM oidc_sessions
                     WHERE (status IS NULL OR status = '')
                       AND login_token IS NOT NULL
                       AND login_token != ''
                )") === false) {
    error_log('Wallos: migration 000086 could not delete legacy OIDC remember-me tokens: '
        . $db->lastErrorMsg());

    return false;
}

// Then the rows themselves: marked revoked with an auditable reason, every secret
// and resume credential cleared, and the revocation moment recorded. The row is
// kept rather than deleted — the v2 model persists a revoked authority record
// rather than removing it — and status = 'revoked' is exactly what the guard
// already refuses.
if ($db->exec("UPDATE oidc_sessions
                  SET status = 'revoked',
                      revocation_reason = 'legacy_pre_v2',
                      refresh_token = '',
                      id_token = '',
                      login_token = '',
                      revoked_at = " . time() . "
                WHERE status IS NULL OR status = ''") === false) {
    error_log('Wallos: migration 000086 could not revoke legacy OIDC sessions: '
        . $db->lastErrorMsg());

    return false;
}
