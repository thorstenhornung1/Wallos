<?php
/*
  Bulk loading of per-user notification settings.

  The notification cron iterates over every user and previously asked each
  provider table for that user's row, so the query count grew as
  users x providers — including for users with no notification enabled at all.

  One query per table answers the same question for everybody.
*/

/**
 * One row of a result, keyed by column name.
 *
 * The mode constant lives here and nowhere else in this file. The result object
 * defaults to returning every column twice — once by name and once by position
 * — and the boundary offers no way to ask for names only without naming the
 * constant (issue #20), so it is named once.
 *
 * @param mixed $result
 * @return array|false
 */
function wallos_notification_fetch($result)
{
    return $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);
}

/**
 * Provider key => table name. The key is what the cron jobs use internally.
 */
const WALLOS_NOTIFICATION_TABLES = [
    'discord' => 'discord_notifications',
    'gotify' => 'gotify_notifications',
    'telegram' => 'telegram_notifications',
    'pushplus' => 'pushplus_notifications',
    'mattermost' => 'mattermost_notifications',
    'pushover' => 'pushover_notifications',
    'ntfy' => 'ntfy_notifications',
    'webhook' => 'webhook_notifications',
    'serverchan' => 'serverchan_notifications',
];

/**
 * Loads every provider's rows, indexed by provider and user id.
 *
 * A table that does not exist yet — an installation part-way through the
 * migration chain — is skipped rather than failing the run.
 *
 * @param SQLite3 $db
 * @return array<string, array<int, array>>
 */
function wallos_load_notification_settings($db)
{
    $settings = [];

    foreach (WALLOS_NOTIFICATION_TABLES as $provider => $table) {
        $settings[$provider] = [];

        $stmt = @$db->prepare('SELECT * FROM ' . $table);
        $result = $stmt ? $stmt->execute() : false;

        while ($row = wallos_notification_fetch($result)) {
            if (isset($row['user_id'])) {
                $settings[$provider][(int) $row['user_id']] = $row;
            }
        }
    }

    return $settings;
}

/**
 * Loads the notification timing of every user: how many days before a renewal
 * they want to hear about it, and whether they want the period summary.
 *
 * @param SQLite3 $db
 * @return array<int, array{days: int, period_summary_at_period_start: int}>
 */
function wallos_load_notification_timing($db)
{
    $hasPeriodSummary = (bool) $db->columnExists('notification_settings', 'period_summary_at_period_start');

    $columns = $hasPeriodSummary
        ? 'user_id, days, period_summary_at_period_start'
        : 'user_id, days';

    $timing = [];
    $result = $db->query('SELECT ' . $columns . ' FROM notification_settings');

    while ($row = wallos_notification_fetch($result)) {
        $timing[(int) $row['user_id']] = [
            'days' => (int) $row['days'],
            'period_summary_at_period_start' => (int) ($row['period_summary_at_period_start'] ?? 0),
        ];
    }

    return $timing;
}

/**
 * Returns the user ids that have at least one notification method enabled, so
 * the cron can skip the expensive per-user work for everybody else.
 *
 * Email is resolved separately, because whether it is enabled lives in the
 * user's row while the transport may be inherited from the instance.
 *
 * @param array $settings Result of wallos_load_notification_settings().
 * @param SQLite3 $db
 * @return array<int, true> Keyed by user id.
 */
function wallos_users_with_notifications($settings, $db)
{
    $users = [];

    foreach ($settings as $providerRows) {
        foreach ($providerRows as $userId => $row) {
            if (!empty($row['enabled'])) {
                $users[$userId] = true;
            }
        }
    }

    $result = $db->query('SELECT user_id FROM email_notifications WHERE enabled = 1');
    while ($row = wallos_notification_fetch($result)) {
        $users[(int) $row['user_id']] = true;
    }

    return $users;
}

/**
 * Rows of one table for a set of accounts, in one query, grouped by account.
 *
 * The notification cron asked six questions per account inside its loop. On
 * SQLite that costs almost nothing — the engine runs in the same process — so
 * the shape was invisible for as long as SQLite was the only backend. On
 * PostgreSQL each is a network round trip, and the job that exists to run
 * unattended over every account grew by about 2.5 ms per account over loopback
 * and 10 ms over an overlay network (issue #99).
 *
 * Six questions for everybody instead of six per person. The same rows, the
 * same order, one query each — which is what #16 already did for the
 * notification settings themselves and what #18 asks to be able to assert.
 *
 * Asked only for the accounts that will actually be processed, so an
 * installation with ten thousand accounts and forty using notifications reads
 * forty accounts' worth of rows rather than everybody's.
 *
 * @param WallosDatabase $db
 * @param string         $table    a table name from this file, never a request
 * @param int[]          $userIds
 * @param string         $columns  what to select
 * @param string         $idColumn the column naming the account — "user" itself
 *                                 is keyed by id rather than user_id
 * @param string         $where    an extra condition, from this file only. The
 *                                 per-account queries this replaces were
 *                                 filtered, and loading everything and
 *                                 filtering in PHP trades round trips for rows
 *                                 — which is the wrong trade for an account
 *                                 with thousands of subscriptions.
 * @return array<int, array[]> user id => rows, accounts with none absent
 */
function wallos_load_rows_by_user($db, $table, array $userIds, $columns = '*', $idColumn = 'user_id', $where = '')
{
    if ($userIds === []) {
        return [];
    }

    // The ids are cast as they are read and the table name comes from the
    // caller in this file; no backend binds an identifier, and a placeholder
    // list would be as long as the account list anyway.
    $ids = [];
    foreach ($userIds as $userId) {
        $ids[] = (int) $userId;
    }

    $condition = $where === '' ? '' : ' AND ' . $where;
    $statement = $db->prepare('SELECT ' . $columns . ' FROM "' . $table . '"
                               WHERE "' . $idColumn . '" IN (' . implode(',', $ids) . ')' . $condition);

    if ($statement === false) {
        return [];
    }

    $result = $statement->execute();

    if ($result === false) {
        return [];
    }

    $grouped = [];

    while ($row = wallos_notification_fetch($result)) {
        // The account column is user_id everywhere except in "user" itself,
        // where the caller either aliases id to user_id or does not — and a
        // loader that assumed one of those would warn on the other.
        $key = array_key_exists('user_id', $row) ? $row['user_id'] : ($row[$idColumn] ?? null);

        if ($key === null) {
            continue;
        }

        $grouped[(int) $key][] = $row;
    }

    return $grouped;
}

/**
 * The same, keyed by a column of each row rather than appended in order.
 *
 * The cron indexes currencies, household members and categories by their own
 * id, because the subscription rows reference them by id.
 *
 * @param array<int, array[]> $grouped
 * @param string              $key
 * @return array<int, array<int|string, array>>
 */
function wallos_index_rows_by($grouped, $key)
{
    $indexed = [];

    foreach ($grouped as $userId => $rows) {
        foreach ($rows as $row) {
            $indexed[$userId][$row[$key]] = $row;
        }
    }

    return $indexed;
}
