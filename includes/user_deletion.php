<?php

/**
 * Deleting a user account, in one place, for both flows that do it.
 *
 * An administrator removing somebody (endpoints/admin/deleteuser.php) and a
 * user closing their own account (endpoints/settings/deleteaccount.php) used to
 * be the same hundred lines transcribed into two files. The copies drifted:
 * custom_css_style, ntfy_notifications and serverchan_notifications were
 * missing from both, login_tokens from the self-service one — so closing your
 * own account left a valid remember-me token behind for an account that no
 * longer existed — and twelve further tables that store rows per user were
 * never mentioned by either.
 *
 * Three defects beyond the missing tables, all of which this file exists to
 * fix:
 *
 *   Order. Both files deleted the "user" row first and its children afterwards.
 *   SQLite never objected, because it enforces foreign keys only when a
 *   connection asks it to and Wallos never asks; PostgreSQL enforces them, so
 *   the parent delete simply failed.
 *
 *   Atomicity. Neither ran in a transaction. When the parent delete failed on
 *   PostgreSQL, the child deletes that had already run stayed committed, and
 *   the account was left with its settings, categories, household members and
 *   payment methods gone while the login still worked.
 *
 *   Checked results. Neither inspected a single return value, so both answered
 *   {"success": true} whether or not anything had happened.
 */

require_once __DIR__ . '/database/connection.php';

/**
 * The tables emptied before every other table the account owns.
 *
 * subscriptions references categories, currencies, household and
 * payment_methods, all of which belong to the same account. Deleting the
 * subscriptions last would mean deleting the rows they point at first.
 *
 * @return string[]
 */
function wallos_user_deletion_leading_tables()
{
    return ['subscriptions'];
}

/**
 * The tables emptied after the "user" row itself.
 *
 * user.main_currency references currencies, which makes the account row a child
 * of its own currency list. Currencies therefore cannot go before the user row,
 * and this is the ordering constraint the previous code stumbled into: with the
 * user row still present because its own delete had failed, the currency delete
 * failed too.
 *
 * @return string[]
 */
function wallos_user_deletion_trailing_tables()
{
    return ['currencies'];
}

/**
 * The ordered list of deletes for one account.
 *
 * The tables are derived from the live schema rather than listed here: every
 * base table with a user_id column holds rows for an account, so the schema
 * already knows the answer and cannot forget to be updated. Only the ordering
 * is stated by hand, because it follows from four foreign keys rather than from
 * a column name.
 *
 * @param WallosDatabase $db
 * @return array[] each entry ['table' => string, 'column' => string]
 */
function wallos_user_deletion_plan($db)
{
    $leading = wallos_user_deletion_leading_tables();
    $trailing = wallos_user_deletion_trailing_tables();

    $owned = [];
    foreach ($db->tablesWithColumn('user_id') as $table) {
        // The names come from the schema rather than from a request, but they
        // are interpolated into SQL below because no backend will bind an
        // identifier. Checked rather than trusted, so that the one place this
        // file builds SQL by concatenation cannot be the interesting one.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1) {
            $owned[] = $table;
        }
    }

    sort($owned);

    $plan = [];

    foreach ($leading as $table) {
        if (in_array($table, $owned, true)) {
            $plan[] = ['table' => $table, 'column' => 'user_id'];
        }
    }

    foreach ($owned as $table) {
        if (in_array($table, $leading, true) || in_array($table, $trailing, true)) {
            continue;
        }

        $plan[] = ['table' => $table, 'column' => 'user_id'];
    }

    // The account itself, after everything that points at it and before the
    // one thing it points at.
    $plan[] = ['table' => 'user', 'column' => 'id'];

    foreach ($trailing as $table) {
        if (in_array($table, $owned, true)) {
            $plan[] = ['table' => $table, 'column' => 'user_id'];
        }
    }

    return $plan;
}

/**
 * Deletes an account and everything that belongs to it, atomically.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @return array{success: bool, error: string|null, tables: int}
 */
function wallos_delete_user($db, $userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        // Not a real id. Running the plan with it would delete the rows of
        // every table whose user_id happens to be 0 or null-cast-to-zero.
        return wallos_user_deletion_failure('refusing to delete user id ' . $userId);
    }

    $plan = wallos_user_deletion_plan($db);

    if (!$db->beginTransaction()) {
        return wallos_user_deletion_failure('could not open a transaction');
    }

    foreach ($plan as $step) {
        $sql = 'DELETE FROM "' . $step['table'] . '" WHERE "' . $step['column'] . '" = :id';

        // Silenced, and then reported properly. SQLite raises a PHP warning of
        // its own on a failed prepare, and both callers answer JSON — a warning
        // printed ahead of the body makes the response unparseable, so the
        // browser shows "unknown error" for a failure the log describes exactly.
        $statement = @$db->prepare($sql);
        if ($statement === false) {
            return wallos_user_deletion_abort($db, 'could not prepare the delete for '
                . $step['table'] . ': ' . $db->lastErrorMsg());
        }

        $statement->bindValue(':id', $userId);

        if (@$statement->execute() === false) {
            return wallos_user_deletion_abort($db, 'deleting from ' . $step['table']
                . ' failed: ' . $db->lastErrorMsg());
        }

        if ($step['table'] === 'user' && (int) $db->changes() === 0) {
            // Nothing was deleted, so there was no such account. Committing
            // here would answer "deleted" for an id that never existed, which
            // is the same untruth this whole file is about.
            return wallos_user_deletion_abort($db, 'no user row with id ' . $userId);
        }
    }

    if (!$db->commit()) {
        return wallos_user_deletion_abort($db, 'the transaction could not be committed');
    }

    return ['success' => true, 'error' => null, 'tables' => count($plan)];
}

/**
 * Rolls the transaction back and reports why.
 *
 * @param WallosDatabase $db
 * @param string         $reason
 * @return array{success: bool, error: string|null, tables: int}
 */
function wallos_user_deletion_abort($db, $reason)
{
    $db->rollBack();

    return wallos_user_deletion_failure($reason);
}

/**
 * @param string $reason
 * @return array{success: bool, error: string|null, tables: int}
 */
function wallos_user_deletion_failure($reason)
{
    // The caller shows the visitor a translated message; the detail belongs in
    // the log, where it says which table refused and what the backend said.
    error_log('Wallos user deletion: ' . $reason);

    return ['success' => false, 'error' => $reason, 'tables' => 0];
}
