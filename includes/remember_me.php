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

    $adminQuery = "SELECT login_disabled FROM admin";
    $adminResult = $db->query($adminQuery);
    $adminRow = $adminResult->fetchArray(SQLITE3_ASSOC);

    if ($adminRow['login_disabled'] == 1) {
        $sql = "SELECT * FROM login_tokens WHERE user_id = :userId";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':userId', $userId, SQLITE3_TEXT);
    } else {
        $sql = "SELECT * FROM login_tokens WHERE user_id = :userId AND token = :token";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':userId', $userId, SQLITE3_TEXT);
        $stmt->bindParam(':token', $token, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row == false) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    $_SESSION['token'] = $token;
    $_SESSION['loggedin'] = true;
    $_SESSION['main_currency'] = $main_currency;
    $_SESSION['userId'] = $userId;

    // A PHP session is collected after about 24 minutes idle while this cookie
    // lives 30 days, so most long-lived sessions come back through here rather
    // than through a login. Two things have to be carried across, or the
    // rebuilt session is permanently exempt from back-channel logout:
    //
    //   from_oidc, because the revocation check only applies to OIDC sessions
    //   and a session that has forgotten its origin is never checked again;
    //
    //   the new session id in oidc_sessions, because session_regenerate_id()
    //   above just invalidated the recorded one — leaving revocation to delete
    //   a row that belongs to a session that no longer exists.
    $sessionStatement = $db->prepare('SELECT id, id_token FROM oidc_sessions WHERE login_token = :token LIMIT 1');
    if ($sessionStatement !== false) {
        $sessionStatement->bindValue(':token', $token, SQLITE3_TEXT);
        $sessionResult = $sessionStatement->execute();
        $sessionRow = $sessionResult === false ? false : $sessionResult->fetchArray(SQLITE3_ASSOC);

        if ($sessionRow !== false) {
            $_SESSION['from_oidc'] = true;

            // The id token comes back too, or the first logout after a
            // container restart has no id_token_hint to offer and the
            // end-session request degrades to the bare form (#123). Rows
            // from before the column exist carry '', which stays absent.
            if (!empty($sessionRow['id_token'])) {
                $_SESSION['oidc_id_token'] = $sessionRow['id_token'];
            }

            $update = $db->prepare('UPDATE oidc_sessions SET session_id = :sessionId WHERE id = :id');
            $recorded = false;

            if ($update !== false) {
                $update->bindValue(':sessionId', session_id(), SQLITE3_TEXT);
                $update->bindValue(':id', $sessionRow['id'], SQLITE3_INTEGER);
                $recorded = $update->execute() !== false;
            }

            if (!$recorded) {
                // The row still names the session id that session_regenerate_id()
                // invalidated a few lines above, so back-channel revocation would
                // delete a session that no longer exists and leave this one
                // running — for up to the thirty days the cookie lasts. That is
                // the defect 5.8.0 closed (#37, #49), reachable again through a
                // write whose result nobody read (issue #87).
                //
                // Refused rather than logged and continued: making somebody sign
                // in again is a smaller harm than a session the provider cannot
                // end.
                error_log('Wallos: could not move the OIDC session onto the restored session id, '
                    . 'so the remember-me restore was refused: ' . $db->lastErrorMsg());

                $_SESSION = [];

                return false;
            }
        }
    }

    return $userData;
}

?>
