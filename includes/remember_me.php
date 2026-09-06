<?php

/**
 * Attempts to restore a logged-in session from the persistent "remember me"
 * login cookie (set at login when "stay logged in" is checked).
 *
 * PHP's session data can be garbage-collected (default ~24 minutes of
 * inactivity) long before the remember-me cookie's 30-day lifetime expires.
 * Full page loads recover from this transparently; this function lets
 * AJAX/API endpoints (via connect_endpoint.php) do the same instead of
 * silently behaving as logged-out after an idle period.
 *
 * On success, populates $_SESSION (regenerating the session id) and
 * returns the user's row. On any failure, returns false and leaves
 * $_SESSION untouched.
 */
function restoreSessionFromRememberMeCookie($db)
{
    if (!isset($_COOKIE['wallos_login'])) {
        return false;
    }

    $cookie = explode('|', $_COOKIE['wallos_login'], 3);
    if (count($cookie) !== 3) {
        return false;
    }
    [$username, $token, $main_currency] = $cookie;

    $sql = "SELECT * FROM \"user\" WHERE username = :username";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();

    if (!$result) {
        return false;
    }

    $userData = $result->fetchArray(SQLITE3_ASSOC);
    if (!isset($userData['id'])) {
        return false;
    }

    $userId = $userData['id'];
    $main_currency = $userData['main_currency'];

    // The token is what the cookie proves. Both halves of the cookie are sent
    // by the client and neither is trustworthy on its own: the username says
    // who the bearer claims to be, and only the token says they may.
    //
    // This used to drop the token condition whenever the administrator had
    // disabled password login, matching on user_id alone — so any row in
    // login_tokens for that account satisfied the lookup, whatever the cookie
    // said. Disabling password login is a hardening step, usually taken when
    // an identity provider is the only intended way in, which made the setting
    // that tightens an installation the one that opened it.
    //
    // There is no case where the token may be skipped. If a caller ever needs
    // "is this account remembered at all", that is a different question and
    // needs its own function rather than a branch in this one.
    //
    // An OIDC remember-me token is stored HASHED at rest (WP6 / §14): the cookie
    // carries the raw 256-bit secret, the database keeps SHA-256(secret). A local
    // token is still stored verbatim. We do not yet know which kind this cookie
    // holds, so the lookup accepts either the raw value or its hash, and the check
    // just below confirms which column actually matched — a raw value must never
    // satisfy an OIDC row, or the stored hash itself (a database-read attacker's
    // only prize) would work as a cookie.
    $hashedToken = hash('sha256', $token);

    $sql = "SELECT * FROM login_tokens WHERE user_id = :userId AND (token = :token OR token = :hashedToken)";
    $stmt = $db->prepare($sql);

    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', (int) $userId);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':hashedToken', $hashedToken);

    $result = $stmt->execute();

    if ($result === false) {
        return false;
    }

    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row === false) {
        // The one rejection worth a line. The username resolved to a real
        // account, yet the token in the cookie matches no row for it — the shape
        // of a forged cookie, or of a credential that has since been revoked and
        // is being replayed. The ordinary stale-cookie miss never reaches here:
        // a post-logout cookie is cleared, and an unknown username returned above
        // — so this fires on the meaningful case rather than on every visit.
        //
        // The user id, never the token. A token in the log is a credential in
        // the log, and this line exists to notice the credential, not to leak it.
        error_log('Wallos: a remember-me cookie for user ' . (int) $userId
            . ' presented a token that matches no active session; it was refused.');

        return false;
    }

    // Whether this remember-me token was minted for an OIDC session (migration
    // 000076). It decides both how the token is matched — hashed for OIDC, raw
    // for local — and, further down, that an OIDC restore does not authenticate
    // on its own but re-enters Phase 2 revalidation.
    $tokenIsOidc = isset($row['from_oidc']) && (int) $row['from_oidc'] === 1;

    // Confirm the credential in constant time against the column that must have
    // matched: an OIDC token by its hash, a local token by its raw value. A cookie
    // carrying the stored hash verbatim (a database-read attacker) is refused for
    // an OIDC row here, because its own hash is not the stored hash; a local token
    // keeps its exact prior behaviour.
    $storedToken = (string) $row['token'];
    $expectedToken = $tokenIsOidc ? $hashedToken : $token;
    if (!hash_equals($storedToken, $expectedToken)) {
        error_log('Wallos: a remember-me cookie for user ' . (int) $userId
            . ' presented a token that matches no active session; it was refused.');

        return false;
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    // The stored value — the hash for an OIDC token, the raw value for a local one
    // — so logout revokes login_tokens by the same value that is recorded there.
    $_SESSION['token'] = $storedToken;
    $_SESSION['loggedin'] = true;
    $_SESSION['main_currency'] = $main_currency;
    $_SESSION['userId'] = $userId;

    // A PHP session is collected after about 24 minutes idle while this cookie
    // lives 30 days, so most long-lived sessions come back through here rather
    // than through a login. For a NON-OIDC session that is the whole story: the
    // lines above have authenticated it, and it is returned below unchanged.
    //
    // For an OIDC session it is not. An OIDC remember-me cookie is a resume
    // HANDLE, never an authenticator (WP6 / §14): a live one does NOT rebuild an
    // authenticated session here — it re-enters the Phase 2 silent-revalidation
    // flow. Two things still have to travel across, or the rebuilt session is
    // permanently exempt from that flow and from back-channel logout:
    //
    //   from_oidc, because only an OIDC session is revalidated and revocable, and
    //   a session that has forgotten its origin is never checked again;
    //
    //   the new session id in oidc_sessions, because session_regenerate_id() above
    //   just invalidated the recorded one — leaving revocation AND revalidation to
    //   target a row that belongs to a session that no longer exists.
    //
    // The oidc_sessions row is keyed by the SAME stored value login_tokens holds —
    // hashed for an OIDC session — so it is located with $storedToken, not the raw
    // cookie. The status column is absent only mid-migration (before Phase 1); when
    // it is, the pre-v2 restore behaviour stands, matching the guard's own
    // pre-migration bypass.
    $hasStatus = $db->columnExists('oidc_sessions', 'status');
    $statusColumn = $hasStatus ? ', status' : '';

    $sessionStatement = $db->prepare('SELECT id, id_token' . $statusColumn
        . ' FROM oidc_sessions WHERE login_token = :token LIMIT 1');
    if ($sessionStatement !== false) {
        $sessionStatement->bindValue(':token', $storedToken, SQLITE3_TEXT);
        $sessionResult = $sessionStatement->execute();
        $sessionRow = $sessionResult === false ? false : $sessionResult->fetchArray(SQLITE3_ASSOC);

        if ($sessionRow !== false) {
            $_SESSION['from_oidc'] = true;

            // The id token comes back too: it is the id_token_hint the silent
            // prompt=none revalidation sends, and the one the first logout after a
            // container restart offers the end-session endpoint (#123). Rows from
            // before the column exist carry '', which stays absent.
            if (!empty($sessionRow['id_token'])) {
                $_SESSION['oidc_id_token'] = $sessionRow['id_token'];
            }

            // A row the provider has already ended is refused outright rather than
            // revalidated. (The revocation paths also delete the login token, so the
            // lookup above usually fails first; this is the belt-and-suspenders case
            // where a marked row outlived nothing.)
            $status = $hasStatus && isset($sessionRow['status']) ? (string) $sessionRow['status'] : '';
            if ($status === 'revoked') {
                error_log('Wallos: a remember-me cookie for a revoked OIDC session was refused rather '
                    . 'than restored.');

                $_SESSION = [];

                return false;
            }

            // WP6 / §14: move the authority row onto the regenerated session id AND
            // force it into REVALIDATION_REQUIRED, in one statement. The guard the
            // caller runs next therefore refuses protected access and sends the
            // browser through the silent prompt=none round-trip (/oidc/revalidate);
            // access is created only when the provider confirms a live session for
            // the same (iss, sub). A stolen cookie with no live OP browser session
            // gets nothing (test N). With the status column absent (mid-migration)
            // only the id is moved, preserving the pre-v2 behaviour.
            if ($hasStatus) {
                $update = $db->prepare('UPDATE oidc_sessions
                                           SET session_id = :sessionId, status = :required
                                         WHERE id = :id');
            } else {
                $update = $db->prepare('UPDATE oidc_sessions SET session_id = :sessionId WHERE id = :id');
            }
            $recorded = false;

            if ($update !== false) {
                $update->bindValue(':sessionId', session_id(), SQLITE3_TEXT);
                if ($hasStatus) {
                    $update->bindValue(':required', 'revalidation_required');
                }
                $update->bindValue(':id', $sessionRow['id'], SQLITE3_INTEGER);
                $recorded = $update->execute() !== false;
            }

            if (!$recorded) {
                // The row still names the session id that session_regenerate_id()
                // invalidated a few lines above, so back-channel revocation and the
                // silent revalidation would both target a session that no longer
                // exists. Refused rather than logged and continued: making somebody
                // sign in again is a smaller harm than a session the provider cannot
                // end and the browser cannot revalidate (#37, #49, #87).
                error_log('Wallos: could not move the OIDC session onto the restored session id, '
                    . 'so the remember-me restore was refused: ' . $db->lastErrorMsg());

                $_SESSION = [];

                return false;
            }
        } elseif ($tokenIsOidc) {
            // The token was minted for an OIDC session and its oidc_sessions row
            // is gone: the identity provider ended that session — a back-channel
            // logout, or a definitive refresh failure the guard acted on.
            // Restoring here would rebuild an independent local session the
            // provider can no longer reach, which is exactly what the revocation
            // removed. Refused, so a cookie cannot outlive the authority that
            // created the session behind it. (The genuine revocation paths also
            // delete this token, so the login_tokens lookup above usually fails
            // first; this closes the case where the token outlived its row.)
            error_log('Wallos: a remember-me cookie for a revoked OIDC session was refused rather '
                . 'than restored as a local session.');

            $_SESSION = [];

            return false;
        }
    }

    return $userData;
}

?>
