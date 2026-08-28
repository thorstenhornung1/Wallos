<?php
/*
  The progress bar under each subscription on the list page.

  The bar's left edge is not a stored fact: the code reconstructs the period
  start by walking back whole cycles from next_payment. For a subscription
  whose start date is still in the future that reconstructed period start
  lands before today, and the bar runs even though the contract has not
  begun. These cases pin the rule that before the start date the bar is
  empty, and that everything about already-running subscriptions stays as
  it was.

  Dates are built relative to the real current day because the function
  reads DateTime('now') itself; the offsets are chosen so the expected
  values do not depend on which day the suite runs.
*/

require_once WALLOS_ROOT . '/includes/subscription_progress.php';

function wallos_test_day_offset($days)
{
    $date = new DateTime((new DateTime('now'))->format('Y-m-d'));
    $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
    return $date->format('Y-m-d');
}

wallos_test('a subscription whose start date is in the future shows no progress', function () {
    // The observed defect: monthly subscription, first payment 50 days from
    // now, start date the same day. Walking two 30-day cycles back from the
    // payment date lands 10 days in the past, so the bar showed 33% for a
    // contract that has not begun.
    $futureDay = wallos_test_day_offset(50);
    assert_equals(
        0,
        getSubscriptionProgress(3, 1, $futureDay, $futureDay),
        'monthly, starting 50 days out: the bar is empty until the start date'
    );

    // The day before the start is the worst case of the same defect: the
    // reconstructed period is 29/30 elapsed, so the bar sat at 96% for a
    // subscription that begins tomorrow.
    $tomorrow = wallos_test_day_offset(1);
    assert_equals(
        0,
        getSubscriptionProgress(3, 1, $tomorrow, $tomorrow),
        'monthly, starting tomorrow: still no progress'
    );

    // A yearly subscription a year out was the least wrong (the long cycle
    // dilutes the error), but it must be exactly zero too.
    $nextYear = wallos_test_day_offset(357);
    assert_equals(
        0,
        getSubscriptionProgress(4, 1, $nextYear, $nextYear),
        'yearly, starting in ~a year: no progress'
    );
});

wallos_test('a running subscription keeps its progress semantics', function () {
    // Mid-cycle, started long ago: 20 days until the next monthly payment
    // means 10 of 30 days are elapsed.
    $longAgo = wallos_test_day_offset(-100);
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(20), $longAgo),
        'monthly, 20 days to renewal, started long ago: a third elapsed'
    );

    // A start date of exactly today is a subscription that has begun.
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(20), wallos_test_day_offset(0)),
        'monthly, 20 days to renewal, started today: measured, not clamped'
    );

    // Rows from before migration 32 have no start date at all, and the form
    // submits an empty string when the field is left blank. Both must behave
    // exactly as the three-argument call always did.
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(20), null),
        'null start date: unchanged behaviour'
    );
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(20), ''),
        'empty start date: unchanged behaviour'
    );
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(20)),
        'omitted start date: unchanged behaviour'
    );

    // A next payment more than one whole cycle away, with a start date in the
    // past, is the stale-renewal shape that the walk-back exists for. The
    // window containing today is measured, not the far-future one.
    assert_equals(
        33,
        getSubscriptionProgress(3, 1, wallos_test_day_offset(50), $longAgo),
        'monthly, renewal 50 days out but running: walk-back still applies'
    );

    // One-time purchases have no period to be a fraction of.
    assert_equals(
        0,
        getSubscriptionProgress(5, 1, wallos_test_day_offset(50), $longAgo),
        'one-time purchase: no progress bar semantics'
    );
});

wallos_test('an unparseable start date means unknown, not a broken page', function () {
    // start_date is TEXT and the form endpoint stores whatever arrived; the
    // parse lives inside printSubscriptions(), so one bad row threw and took
    // the whole subscriptions page with it (#121). Unreadable behaves exactly
    // like missing: the old walk-back semantics, no clamp, no exception.
    $nextPayment = wallos_test_day_offset(15);
    $known = getSubscriptionProgress(3, 1, $nextPayment, null);

    foreach (['0', '1756339200', 'not a date'] as $garbage) {
        assert_equals($known, getSubscriptionProgress(3, 1, $nextPayment, $garbage),
            "'" . $garbage . "' behaves like a missing start date");
    }
});
