<?php

/**
 * Revocation of persistent login tokens.
 *
 * Wallos issues a remember-me token that survives the PHP session, so ending a
 * session means removing that row as well. Keeping the deletes here rather than
 * inline in logout.php means they can be tested, and means a later back-channel
 * logout can revoke a user's tokens through the same path.
 */

/**
 * Revoke a single token.
 *
 * The token value identifies the row on its own — it is the primary credential.
 * Scoping the delete by user as well is only sound when the user is genuinely
 * known, and getting that wrong silently deletes nothing at all.
 *
 * @return int number of tokens removed
 */
function wallos_revoke_login_token($db, $token)
{
    if (!is_string($token) || $token === '') {
        return 0;
    }

    $stmt = $db->prepare("DELETE FROM login_tokens WHERE token = :token");
    if ($stmt === false) {
        return 0;
    }
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->execute();

    return $db->changes();
}

/**
 * Revoke every token belonging to a user.
 *
 * Used when a session ends for a reason outside this browser — the identity
 * provider signalling logout, or an administrator ending a session. Removing
 * one device's token would leave the others usable.
 *
 * @return int number of tokens removed
 */
function wallos_revoke_user_login_tokens($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("DELETE FROM login_tokens WHERE user_id = :userId");
    if ($stmt === false) {
        return 0;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    return $db->changes();
}
