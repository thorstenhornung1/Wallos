<?php
/*
  What the calendar counts for a month (reported from the test instance).

  The monthly tile labelled "active subscriptions" was incremented once per
  payment, not once per subscription, so a daily subscription reported itself
  as 29 or 30 "subscriptions" and swamped the number. The two quantities are
  genuinely different and the calendar needs both — the grid draws every
  occurrence, the tile names subscriptions — so the projection returns them
  separately and these cases hold them apart.

  The defect is upstream's as much as ours: the same loop stands in
  upstream/main. It survived because the projection was inline in calendar.php,
  computed inside the loop that renders the grid, where nothing could ask it
  anything.
*/

require_once WALLOS_ROOT . '/includes/calendar_occurrences.php';

/**
 * A subscription row as calendar.php reads it (SELECT * FROM subscriptions).
 */
function wallos_test_subscription($id, $cycle, $frequency, $nextPayment, $startDate = null)
{
    return [
        'id' => $id,
        'name' => 'Subscription ' . $id,
        'price' => 1.0,
        'currency_id' => 1,
        'cycle' => $cycle,
        'frequency' => $frequency,
        'next_payment' => $nextPayment,
        'start_date' => $startDate,
        'inactive' => 0,
    ];
}

wallos_test('a daily subscription is one subscription, not thirty', function () {
    // The reported case: one daily subscription in a 30-day month. The grid
    // must show it on all 30 days; the tile must say 1.
    $daily = wallos_test_subscription(1, 1, 1, '2026-09-15', '2026-09-01');

    $month = wallos_calendar_month_occurrences([$daily], 2026, 9);

    assert_equals(30, $month['occurrences'], 'it pays on every day of September');
    assert_equals(1, $month['subscriptions'], 'it is still a single subscription');
    assert_equals(30, count($month['byDay']), 'every day of the month carries an entry');
    assert_equals(1, count($month['byDay'][17]), 'a day it pays holds exactly one payment');
});

wallos_test('a monthly subscription counts once', function () {
    $monthly = wallos_test_subscription(1, 3, 1, '2026-09-12');

    $month = wallos_calendar_month_occurrences([$monthly], 2026, 9);

    assert_equals(1, $month['occurrences'], 'one payment');
    assert_equals(1, $month['subscriptions'], 'one subscription');
    assert_same([12], array_keys($month['byDay']), 'on its payment day');
});

wallos_test('subscriptions and payments are counted separately', function () {
    // Three subscriptions, seven payments between them: weekly (5 in September
    // 2026, from the 1st), fortnightly (2), monthly (1). A tile that counts
    // payments would say 8; the answer is 3.
    $weekly = wallos_test_subscription(1, 2, 1, '2026-09-01');
    $fortnightly = wallos_test_subscription(2, 2, 2, '2026-09-04');
    $monthly = wallos_test_subscription(3, 3, 1, '2026-09-20');

    $month = wallos_calendar_month_occurrences([$weekly, $fortnightly, $monthly], 2026, 9);

    assert_equals(3, $month['subscriptions'], 'three subscriptions pay this month');
    assert_true($month['occurrences'] > $month['subscriptions'],
        'and they make more payments than that between them');
    assert_equals(8, $month['occurrences'], 'five weekly, two fortnightly, one monthly');
});

wallos_test('a subscription is not projected before it started', function () {
    // #997: the walk backwards from next_payment must not invent payments that
    // predate the subscription.
    $monthly = wallos_test_subscription(1, 3, 1, '2026-11-10', '2026-10-10');

    $september = wallos_calendar_month_occurrences([$monthly], 2026, 9);
    assert_equals(0, $september['occurrences'], 'nothing before the start date');
    assert_equals(0, $september['subscriptions'], 'and no subscription counted for it');

    $october = wallos_calendar_month_occurrences([$monthly], 2026, 10);
    assert_equals(1, $october['occurrences'], 'the month it starts does carry its payment');
});

wallos_test('a one-time purchase appears on its date only', function () {
    $oneOff = wallos_test_subscription(1, 5, 1, '2026-09-18');

    $september = wallos_calendar_month_occurrences([$oneOff], 2026, 9);
    assert_same([18], array_keys($september['byDay']), 'on the day it is paid');
    assert_equals(1, $september['subscriptions'], 'counted once');

    $october = wallos_calendar_month_occurrences([$oneOff], 2026, 10);
    assert_equals(0, $october['occurrences'], 'and never again');
});

wallos_test('a month with nothing due counts nothing', function () {
    $monthly = wallos_test_subscription(1, 3, 1, '2026-09-12');

    // A month the projection does not reach at all: the subscription starts in
    // September, so August holds no payment.
    $august = wallos_calendar_month_occurrences([wallos_test_subscription(1, 3, 1, '2026-09-12', '2026-09-12')], 2026, 8);
    assert_equals(0, $august['occurrences'], 'no payments');
    assert_equals(0, $august['subscriptions'], 'and no subscriptions');
    assert_same([], $august['byDay'], 'nothing to draw');
});
