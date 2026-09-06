<?php
// Web Push: a user's browser/PWA subscriptions, one row per device.
//
// Unlike the other notification channels — one row per user in a *_notifications
// table with an enabled flag — a Web Push subscription is the opt-in itself: a
// user has as many as they have subscribed devices, and enabling the channel is
// subscribing a device (issue #162). A send that gets 404/410 Gone deletes the
// row.
//
// The endpoint is the primary key: it is unique per subscription by nature, and
// a natural key keeps this identical on both backends — the recent migrations
// (000063, 000081) do the same rather than introduce an integer id that is a
// rowid on one backend and needs SERIAL on the other. So a device re-subscribing
// replaces its own row. "user" is quoted because it is a reserved word on
// PostgreSQL, where this migration runs raw on the upgrade path.
//
// Created through the database boundary rather than a backend-specific schema
// query, so it runs on both backends. IF NOT EXISTS keeps it idempotent — the
// PostgreSQL install applies schema.sql, which already carries this table, and
// then finds every migration recorded as applied.

if ($db->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
    endpoint TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES "user"(id)
)') === false) {
    error_log('Wallos: migration 000082 could not create push_subscriptions: ' . $db->lastErrorMsg());

    return false;
}

if ($db->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user
               ON push_subscriptions (user_id)') === false) {
    error_log('Wallos: migration 000082 could not create the user index: ' . $db->lastErrorMsg());

    return false;
}
