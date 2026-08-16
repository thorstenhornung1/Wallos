<?php

/**
 * Make the administrator role explicit.
 *
 * Until now "administrator" meant `id == 1` — whoever happened to land in the
 * database first. That made the role impossible to grant or revoke, capped the
 * install at one administrator, and, with OIDC auto-provisioning, handed the
 * role to whoever logged in first.
 *
 * The role becomes a row. `source` separates a locally granted role from one
 * derived from an identity provider, so that synchronising the OIDC side can
 * never remove a local recovery administrator.
 */

$db->exec("CREATE TABLE IF NOT EXISTS user_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    source TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, role, source),
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE CASCADE
)");

$db->exec("CREATE INDEX IF NOT EXISTS idx_user_roles_user_role ON user_roles (user_id, role)");

// The existing administrator keeps the role. `source = local` because it was
// granted by this installation, not by an identity provider — an OIDC sync must
// never be able to take it away.
//
// INSERT OR IGNORE against the unique constraint makes this idempotent, and the
// EXISTS guard means a fresh install with no user 1 yet does not get a role row
// pointing at nobody.
$db->exec("INSERT OR IGNORE INTO user_roles (user_id, role, source)
           SELECT 1, 'admin', 'local'
           WHERE EXISTS (SELECT 1 FROM user WHERE id = 1)");
