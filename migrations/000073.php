<?php
// Adds the admin opt-in that lets standard users reach the hosts on the
// Webhook Allowlist (upstream 5.5.0, closes upstream #1153 and #1138).
//
// Upstream numbers this 000056; that number is taken here by the subscription
// indexes this fork contributed, so it moves to the end of the chain. It is
// the same schema either way — a column on "admin" — so an installation that
// ever switches between the two trees ends up with one column, not two.
//
// Through the boundary rather than a pragma: the 5.8.0 PostgreSQL baseline
// records the chain up to 000063, so everything after it has to run on both
// backends.
//
// The default is 0, which is the behaviour every installation had before the
// column existed: a standard account is refused a private target regardless
// of the allowlist. Turning it on is the administrator's decision and is made
// in Security Settings.
if (!$db->columnExists('admin', 'allow_standard_users_local_webhooks')) {
    $db->exec('ALTER TABLE admin ADD COLUMN allow_standard_users_local_webhooks INTEGER DEFAULT 0');
}

// The file-backed backend does not physically store ALTER TABLE defaults in
// existing rows, and its PHP extension may hand back NULL for them, so the
// default is written out rather than assumed.
$db->exec('UPDATE admin SET allow_standard_users_local_webhooks = 0
           WHERE allow_standard_users_local_webhooks IS NULL');
