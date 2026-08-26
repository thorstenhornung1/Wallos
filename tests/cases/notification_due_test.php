<?php
/*
  Who the notification cron has work for.

  This is the safety net for issue #99 step 3, and it is written before the
  change it protects. The job currently loads every notified account's
  currencies, household, categories and budget before asking whether that
  account has anything due; the fix is to ask first. That is only safe if
  "asking" produces exactly the same answer as the loop does today, so what
  follows pins the answer down — including the two rules that a naive prefilter
  gets wrong.

  Counting rather than looking, throughout. "Is this account in the list" is
  the weaker question; "how many accounts, and which" catches a filter that
  adds an account twice as well as one that drops it.

  The times below are 09:00, not midnight, because the job uses
  DateTime('now') and the day count is measured in elapsed hours: from
  2026-08-26 09:00 to 2026-08-27 is under 24 hours and counts as zero days
  plus the look-ahead, while from midnight to midnight is a full day and
  counts as one. Testing at midnight would pin down behaviour the job almost
  never has.
*/

require_once WALLOS_ROOT . '/includes/notification_due.php';

/**
 * @param array $overrides
 * @return array
 */
function due_subscription($overrides = [])
{
    return array_merge([
        'notify' => 1,
        'inactive' => 0,
        'notify_days_before' => -1,
        'next_payment' => '2026-08-27',
    ], $overrides);
}

wallos_test('a subscription notifies on the day its lead time is reached', function () {
    $today = new DateTime('2026-08-26 09:00:00');

    // Tomorrow, with a one-day default: due.
    assert_true(wallos_notification_subscription_is_due(due_subscription(), 1, $today),
        'one day ahead with a lead time of one');

    // The same subscription with a longer lead time is not due today; it will
    // be, earlier. This is the rule that makes a daily job send one message per
    // subscription instead of one per day until payment.
    assert_true(!wallos_notification_subscription_is_due(due_subscription(), 3, $today),
        'one day ahead with a lead time of three is not today');

    assert_true(wallos_notification_subscription_is_due(
        due_subscription(['next_payment' => '2026-08-29']), 3, $today),
        'three days ahead with a lead time of three');
});

wallos_test('a subscription may override the account lead time', function () {
    // The rule that rules out a plain SQL window: rows inside one account can
    // have different lead times, so there is no single BETWEEN that fits.
    $today = new DateTime('2026-08-26 09:00:00');

    $ownLeadTime = due_subscription(['notify_days_before' => 3, 'next_payment' => '2026-08-29']);

    assert_true(wallos_notification_subscription_is_due($ownLeadTime, 1, $today),
        'its own three beats the account default of one');
    assert_true(!wallos_notification_subscription_is_due(due_subscription(), 3, $today),
        'and -1 means the account default really is used');
});

wallos_test('paused and unwatched subscriptions never notify', function () {
    $today = new DateTime('2026-08-26 09:00:00');

    assert_true(!wallos_notification_subscription_is_due(due_subscription(['notify' => 0]), 1, $today),
        'notifications off');
    assert_true(!wallos_notification_subscription_is_due(due_subscription(['inactive' => 1]), 1, $today),
        'subscription paused');
});

wallos_test('a payment already in the past does not notify', function () {
    // The date comparison exists on top of the day count, and it is the reason
    // a subscription whose payment was missed does not start notifying again
    // once the difference happens to match.
    $today = new DateTime('2026-08-26 09:00:00');

    $yesterday = due_subscription(['next_payment' => '2026-08-25']);

    assert_true(!wallos_notification_subscription_is_due($yesterday, 1, $today),
        'yesterday is not notified for');
});

wallos_test('an account is selected once, however many subscriptions are due', function () {
    // The counting question. An account with five due subscriptions is one
    // account of work, and a filter that returns it five times would load its
    // currencies five times over.
    $today = new DateTime('2026-08-26 09:00:00');

    $accounts = [
        7 => [due_subscription(), due_subscription(), due_subscription()],
    ];

    $work = wallos_notification_accounts_with_work($accounts, [7 => ['days' => 1]], $today);

    assert_same([7], array_keys($work), 'one account');
    assert_same(1, count($work), 'listed once');
});

wallos_test('accounts with nothing due are not selected', function () {
    $today = new DateTime('2026-08-26 09:00:00');

    $accounts = [
        7 => [due_subscription()],
        8 => [due_subscription(['next_payment' => '2026-12-01'])],
        9 => [due_subscription(['notify' => 0])],
        10 => [],
    ];

    $work = wallos_notification_accounts_with_work(
        $accounts,
        [7 => ['days' => 1], 8 => ['days' => 1], 9 => ['days' => 1], 10 => ['days' => 1]],
        $today
    );

    assert_same([7], array_keys($work), 'only the account with something due');
});

wallos_test('the period summary keeps an account with nothing due', function () {
    // The rule a payment-based filter loses. sendnotifications.php falls back
    // to an empty notify list for the default payer when the period summary is
    // due at period start — so the account is owed a message precisely when it
    // has no payment to report.
    $today = new DateTime('2026-08-26 09:00:00');

    $accounts = [8 => [due_subscription(['next_payment' => '2026-12-01'])]];
    $timing = [8 => ['days' => 1]];

    $without = wallos_notification_accounts_with_work($accounts, $timing, $today, []);
    assert_same([], array_keys($without), 'nothing due, no summary: no work');

    $with = wallos_notification_accounts_with_work($accounts, $timing, $today, [8]);
    assert_same([8], array_keys($with), 'nothing due, but the summary is owed today');
});

wallos_test('an account owed a summary and a payment appears once', function () {
    // Both reasons at once must not double the account, for the same reason as
    // the multiple-subscriptions case: it decides what gets loaded.
    $today = new DateTime('2026-08-26 09:00:00');

    $work = wallos_notification_accounts_with_work(
        [7 => [due_subscription()]], [7 => ['days' => 1]], $today, [7]
    );

    assert_same(1, count($work), 'one entry, two reasons');
    assert_same([7], array_keys($work), 'and it is the right account');
});

wallos_test('a missing timing row falls back to the same default as the job', function () {
    // sendnotifications.php initialises $days = 1 before consulting the timing
    // table, so an account with no row notifies a day ahead. A prefilter using
    // a different fallback would disagree with the loop for exactly those
    // accounts.
    $today = new DateTime('2026-08-26 09:00:00');

    $work = wallos_notification_accounts_with_work([7 => [due_subscription()]], [], $today);

    assert_same([7], array_keys($work), 'no timing row means a lead time of one');
});
