<?php

/**
 * The persistent side of two-factor verification.
 *
 * All of this lived inline in totp.php, where it could not be tested, and that
 * is precisely how the replay guard came to be dead code: the SELECT that feeds
 * it never asked for last_totp_used, so the comparison ran against 0 forever
 * and every check passed. Nothing failed, nothing was logged, and a captured
 * code stayed usable for the whole leeway window.
 *
 * Reading the state and consuming a credential belong together, so a column
 * added to one is visible to the other.
 */

/**
 * Every column the verification path needs, for one user.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @return array|null null when there is no enrolment, or the read failed
 */
function wallos_totp_load_state($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }

    // Named individually rather than SELECT *: a wildcard would have hidden the
    // original defect, but it also hides the opposite one — a column renamed out
    // from under this code fails loudly here instead of silently reading null.
    $statement = $db->prepare(
        'SELECT totp_secret, backup_codes, failed_attempts, lockout_until, last_totp_used
         FROM totp WHERE user_id = :id'
    );
    if ($statement === false) {
        return null;
    }
    $statement->bindValue(':id', $userId, SQLITE3_INTEGER);

    $result = $statement->execute();
    if ($result === false) {
        return null;
    }

    $row = $result->fetchArray(SQLITE3_ASSOC);

    return $row === false ? null : $row;
}

/**
 * The time-step of the last code this account consumed.
 *
 * Older installations stored a raw unix timestamp in this column. Such a value
 * is ~30x larger than any current step, which would reject every code as a
 * replay and lock the account out, so it is converted rather than trusted.
 *
 * @param  mixed $stored
 * @param  int   $currentStep
 * @return int
 */
function wallos_totp_last_used_step($stored, $currentStep)
{
    $lastUsedStep = (int) $stored;

    if ($lastUsedStep > $currentStep) {
        $lastUsedStep = intdiv($lastUsedStep, 30);
    }

    return $lastUsedStep;
}

/**
 * Which time-step a submitted code belongs to, if any.
 *
 * Mirrors the leeway the library applies, so the step recorded as consumed is
 * the step that was actually accepted.
 *
 * @param  object $totp   an OTPHP TOTP instance
 * @param  string $code
 * @param  int    $now
 * @param  int    $period
 * @param  int    $leeway
 * @return int|null null when the code matches no step in the window
 */
function wallos_totp_matched_step($totp, $code, $now, $period = 30, $leeway = 15)
{
    foreach ([$now - $leeway, $now, $now + $leeway] as $candidate) {
        if ($candidate < 0) {
            continue;
        }
        if (hash_equals($totp->at($candidate), (string) $code)) {
            return intdiv($candidate, $period);
        }
    }

    return null;
}

/**
 * Whether this step has already been spent.
 *
 * @param  int $matchedStep
 * @param  int $lastUsedStep
 * @return bool
 */
function wallos_totp_step_is_replay($matchedStep, $lastUsedStep)
{
    return $matchedStep <= $lastUsedStep;
}

/**
 * Record a consumed time-step.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @param  int            $step
 * @return bool false when the step was not stored
 */
function wallos_totp_consume_step($db, $userId, $step)
{
    $statement = $db->prepare('UPDATE totp SET last_totp_used = :step WHERE user_id = :id');
    if ($statement === false) {
        return false;
    }
    $statement->bindValue(':step', (int) $step, SQLITE3_INTEGER);
    $statement->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return $statement->execute() !== false;
}

/**
 * Spend a backup code.
 *
 * The caller must treat a false return as "this code was not accepted". A
 * backup code is single-use by definition, so one that could not be removed has
 * not been used up — honouring it anyway would turn the one-time code into a
 * permanent one, which is the failure this function exists to prevent.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @param  array          $backupCodes the codes currently on the account
 * @param  string         $submitted
 * @return bool whether the code was present and is now spent
 */
function wallos_totp_consume_backup_code($db, $userId, $backupCodes, $submitted)
{
    if (!is_array($backupCodes)) {
        return false;
    }

    $key = array_search($submitted, $backupCodes, true);
    if ($key === false) {
        return false;
    }

    unset($backupCodes[$key]);
    $remaining = array_values($backupCodes);

    $statement = $db->prepare('UPDATE totp SET backup_codes = :codes WHERE user_id = :id');
    if ($statement === false) {
        return false;
    }
    $statement->bindValue(':codes', json_encode($remaining), SQLITE3_TEXT);
    $statement->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return $statement->execute() !== false;
}

/**
 * Clear the failed-attempt counter after a successful verification.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @return bool
 */
function wallos_totp_reset_attempts($db, $userId)
{
    $statement = $db->prepare('UPDATE totp SET failed_attempts = 0, lockout_until = 0 WHERE user_id = :id');
    if ($statement === false) {
        return false;
    }
    $statement->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return $statement->execute() !== false;
}

/**
 * Count a failed attempt, and lock the account once there are too many.
 *
 * A write failure here disables brute-force protection silently — the attacker
 * sees no difference, and the counter simply never rises. It is reported so the
 * caller can log it; refusing the login instead would let a database problem
 * lock everyone out, which is the larger harm.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @param  int            $failedAttempts the count including this attempt
 * @param  int            $maxAttempts
 * @param  int            $lockoutSeconds
 * @return array{locked: bool, stored: bool}
 */
function wallos_totp_record_failure($db, $userId, $failedAttempts, $maxAttempts, $lockoutSeconds)
{
    if ($failedAttempts >= $maxAttempts) {
        // Reset the counter as the lockout is set, so a fresh window begins
        // when the lockout expires.
        $statement = $db->prepare(
            'UPDATE totp SET failed_attempts = 0, lockout_until = :lockout WHERE user_id = :id'
        );
        if ($statement === false) {
            return ['locked' => true, 'stored' => false];
        }
        $statement->bindValue(':lockout', time() + $lockoutSeconds, SQLITE3_INTEGER);
        $statement->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

        return ['locked' => true, 'stored' => $statement->execute() !== false];
    }

    $statement = $db->prepare('UPDATE totp SET failed_attempts = :attempts WHERE user_id = :id');
    if ($statement === false) {
        return ['locked' => false, 'stored' => false];
    }
    $statement->bindValue(':attempts', (int) $failedAttempts, SQLITE3_INTEGER);
    $statement->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return ['locked' => false, 'stored' => $statement->execute() !== false];
}

/**
 * Turn two-factor authentication off for an account.
 *
 * Two writes have to agree: the flag on the account and the enrolment row. When
 * only one lands, the account is left in a state no credential can satisfy —
 * totp_enabled still set, but no secret and no backup codes to answer with, so
 * login.php routes the user to a page that can never accept anything. Both
 * call sites reported success unconditionally, so the user was told 2FA was off
 * while being locked out of their account.
 *
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @return bool false when nothing was changed
 */
function wallos_totp_disable($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $db->beginTransaction();

    $statement = $db->prepare('UPDATE "user" SET totp_enabled = 0 WHERE id = :id');
    if ($statement === false) {
        $db->rollBack();
        return false;
    }
    $statement->bindValue(':id', $userId, SQLITE3_INTEGER);
    if ($statement->execute() === false) {
        $db->rollBack();
        return false;
    }

    $statement = $db->prepare('DELETE FROM totp WHERE user_id = :id');
    if ($statement === false) {
        $db->rollBack();
        return false;
    }
    $statement->bindValue(':id', $userId, SQLITE3_INTEGER);
    if ($statement->execute() === false) {
        $db->rollBack();
        return false;
    }

    if ($db->commit() === false) {
        error_log('Wallos: could not commit disabling 2FA for user ' . $userId
            . ': ' . $db->lastErrorMsg());
        return false;
    }

    return true;
}
