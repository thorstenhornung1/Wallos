<?php
// Records signed-in OIDC sessions so the identity provider can end them.
//
// Back-channel logout has to reach a session that is already running. Deleting
// the remember-me token is not enough — the browser holding a live PHP session
// would stay signed in until it expired. A row here is what makes a session
// current; deleting the row is what revokes it.
//
// sid is the provider's session identifier when it sends one, which allows
// ending exactly the session meant rather than every session of that person.

$db->exec("CREATE TABLE IF NOT EXISTS oidc_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    sid TEXT NOT NULL DEFAULT '',
    session_id TEXT NOT NULL,
    login_token TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE CASCADE
)");

$db->exec("CREATE INDEX IF NOT EXISTS idx_oidc_sessions_session ON oidc_sessions (session_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_oidc_sessions_sid ON oidc_sessions (sid)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_oidc_sessions_user ON oidc_sessions (user_id)");
