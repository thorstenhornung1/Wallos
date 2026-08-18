<?php
/*
  Bulk loading of per-user notification settings.

  The notification cron iterates over every user and previously asked each
  provider table for that user's row, so the query count grew as
  users x providers — including for users with no notification enabled at all.

  One query per table answers the same question for everybody.
*/

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

        while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
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

    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
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
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[(int) $row['user_id']] = true;
    }

    return $users;
}
