<?php
// Adds admin_claim and admin_value to oauth_settings.
//
// These name the claim an identity provider sends, and the value in it that
// grants the Wallos administrator role. They sit in the table with the rest of
// the OIDC configuration rather than being environment-only: whoever can edit
// the issuer or the client id already controls authentication entirely, so
// singling these two out would have bought no safety and only split the
// configuration across two places.
//
// Empty means no admin role is derived from OIDC, which is what every existing
// installation gets.

foreach (['admin_claim', 'admin_value'] as $column) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('oauth_settings') WHERE name='" . $column . "'");
    $columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

    if ($columnRequired) {
        $db->exec("ALTER TABLE oauth_settings ADD COLUMN " . $column . " TEXT DEFAULT ''");
    }

    // SQLite does not physically store ALTER TABLE defaults in existing rows,
    // so PHP's SQLite3 extension may return NULL for them. Backfill explicitly.
    $db->exec("UPDATE oauth_settings SET " . $column . " = '' WHERE " . $column . " IS NULL");
}
