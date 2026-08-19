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
 * A failed delete is distinguishable from a token that was not there. Both
 * remove nothing, but only one of them means a browser is still holding a
 * credential the caller believes it has revoked — and the callers act on that
 * difference: logout warns, back-channel logout refuses to count the session as
 * revoked. Returning 0 for both is what let a live session be reported to the
 * identity provider as ended.
 *
 * @return int|false tokens removed, or false when the delete did not run
 */
function wallos_revoke_login_token($db, $token)
{
    if (!is_string($token) || $token === '') {
        return 0;
    }

    $stmt = $db->prepare("DELETE FROM login_tokens WHERE token = :token");
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);

    // The execute result, not changes(), decides whether this worked. Neither
    // backend resets the change counter after a failed statement — measured on
    // both — so a DELETE that never ran would otherwise report the row count of
    // whatever ran before it. For a function whose answer is "how many sessions
    // did I just end", that is the wrong way to be wrong.
    if ($stmt->execute() === false) {
        return false;
    }

    return $db->changes();
}

/**
 * Revoke every token belonging to a user.
 *
 * Used when a session ends for a reason outside this browser — the identity
 * provider signalling logout, or an administrator ending a session. Removing
 * one device's token would leave the others usable.
 *
 * @return int|false tokens removed, or false when the delete did not run
 */
function wallos_revoke_user_login_tokens($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("DELETE FROM login_tokens WHERE user_id = :userId");
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    if ($stmt->execute() === false) {
        return false;
    }

    return $db->changes();
}
