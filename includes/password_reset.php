<?php

/**
 * Issuing a password reset token.
 *
 * Kept out of passwordreset.php so it can be tested. The page ran the delete
 * and the insert unchecked and then displayed "check your email" regardless,
 * which on this flow in particular is the worst place to be wrong: it is the
 * only way back in for somebody who cannot log in, and a user told an email is
 * coming will wait for it rather than look for another route.
 */

/**
 * Replace any outstanding reset token for an address with a new one.
 *
 * The delete and the insert are one transaction. Separately, a failed insert
 * leaves the account with the old token deleted and no new one — no way to
 * reset at all — and retrying reproduces it exactly, because there is nothing
 * left to delete and the insert fails again. Rolled back, the previous token
 * keeps working.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @param  string         $email
 * @param  string         $token
 * @return bool false when no token was issued
 */
function wallos_issue_password_reset($db, $userId, $email, $token)
{
    $db->beginTransaction();

    $issued = false;

    $stmt = $db->prepare('DELETE FROM password_resets WHERE email = :email');
    if ($stmt !== false) {
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        if ($stmt->execute() !== false) {
            $stmt = $db->prepare(
                'INSERT INTO password_resets (user_id, email, token) VALUES (:user_id, :email, :token)'
            );
            if ($stmt !== false) {
                $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                $issued = $stmt->execute() !== false;
            }
        }
    }

    if ($issued) {
        $issued = $db->commit() !== false;
    } else {
        $db->rollBack();
    }

    if (!$issued) {
        error_log('Wallos password reset: no token was issued for user ' . (int) $userId
            . '; any previous token was left in place: ' . $db->lastErrorMsg());
    }

    return $issued;
}
