<?php
/*
  Which accounts the notification cron actually has work for.

  The job loads every notified account's currencies, household members,
  categories and budget configuration, and only then works out whether that
  account has a payment due. On most days most accounts do not. On SQLite the
  waste is invisible — a query is a function call in the same process — and on
  PostgreSQL each of those is a network round trip, so the one job that runs
  unattended over every account grew with account count times latency
  (issue #99).

  Deciding first and loading afterwards needs the decision separated from the
  loading, which is what this file is. The rules are copied from the loop
  rather than improved, deliberately: a prefilter that is merely *nearly* the
  same as the code it replaces silently drops notifications, and a missing
  notification is worse than the slowness being fixed.

  Two rules are easy to get wrong and are the reason this is not a SQL WHERE
  clause:

  A subscription may override the account's lead time. notify_days_before is
  -1 for "use the account default" and otherwise its own number, so the window
  differs per row within a single account.

  An account with nothing due can still be owed a message. When the period
  summary is set to arrive at the start of a budget period, that message goes
  out on that day whether or not any payment falls due — so "has a payment due"
  is not the same question as "has work".
*/

/**
 * Whether one subscription falls due for notification today.
 *
 * The comparison is exact rather than a range: a subscription notifies on the
 * day that is exactly its lead time away, which is what makes a daily job
 * send one message per subscription rather than one every day until payment.
 *
 * @param array    $subscription
 * @param int      $defaultDays Account-level lead time.
 * @param DateTime $currentDate
 * @return bool
 */
function wallos_notification_subscription_is_due($subscription, $defaultDays, $currentDate)
{
    if ((int) ($subscription['notify'] ?? 0) !== 1 || (int) ($subscription['inactive'] ?? 0) !== 0) {
        return false;
    }

    $daysToCompare = $subscription['notify_days_before'] !== -1
        ? $subscription['notify_days_before']
        : $defaultDays;

    $nextPaymentDate = new DateTime($subscription['next_payment']);
    $difference = $currentDate->diff($nextPaymentDate)->days;

    if ($nextPaymentDate > $currentDate) {
        $difference += 1;
    }

    // Kept as the loop has it, including the strict comparison. Changing it
    // here would change who gets mail, which is not what this file is for.
    return $difference === $daysToCompare
        && $nextPaymentDate->format('Y-m-d') >= $currentDate->format('Y-m-d');
}

/**
 * The accounts with something to do today.
 *
 * @param array    $subscriptionsByUser user_id => rows, already filtered to notify = 1
 * @param array    $timingByUser        user_id => ['days' => int, ...]
 * @param DateTime $currentDate
 * @param array    $periodStartUserIds  accounts owed a period-start summary today
 * @return array   user_id => true, in the order given
 */
function wallos_notification_accounts_with_work(
    $subscriptionsByUser,
    $timingByUser,
    $currentDate,
    $periodStartUserIds = []
) {
    $withWork = [];

    foreach ($subscriptionsByUser as $userId => $rows) {
        $defaultDays = isset($timingByUser[$userId]['days'])
            ? (int) $timingByUser[$userId]['days']
            : 1;

        foreach ($rows as $row) {
            if (wallos_notification_subscription_is_due($row, $defaultDays, $currentDate)) {
                $withWork[$userId] = true;
                break;
            }
        }
    }

    // Added rather than intersected: these accounts have no payment due, which
    // is exactly why a filter built on payments alone loses them.
    foreach ($periodStartUserIds as $userId) {
        $withWork[$userId] = true;
    }

    return $withWork;
}
