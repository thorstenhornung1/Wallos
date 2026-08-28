<?php

// The progress-bar arithmetic for the subscriptions list. It lives in its own
// file, apart from includes/list_subscriptions.php, because that file drags in
// translations and a database handle on include — this calculation needs
// neither, and the test suite includes this file directly.

function getSubscriptionProgress($cycle, $frequency, $next_payment, $start_date = null)
{
    if ($cycle === 5) {
        return 0;
    }

    $nextPaymentDate = new DateTime($next_payment);
    $currentDate = new DateTime((new DateTime('now'))->format('Y-m-d'));

    // A subscription that has not started yet has made no progress through
    // any billing period, whatever walking cycles back from next_payment
    // would suggest. Rows can carry no start date at all (older than the
    // migration that added the column) or an empty string from a blank form
    // field; both mean "unknown", and unknown keeps the old behaviour.
    if ($start_date !== null && $start_date !== '') {
        // The column is TEXT and the form endpoint stores whatever arrived,
        // so a row can carry anything. This parse runs inside the list
        // renderer: one unreadable value must not take the whole page down
        // with it. Unreadable means "unknown", exactly like missing (#121).
        try {
            $startDate = new DateTime((new DateTime($start_date))->format('Y-m-d'));
        } catch (Exception $unreadableStartDate) {
            $startDate = null;
        }

        if ($startDate !== null && $startDate > $currentDate) {
            return 0;
        }
    }

    $paymentCycleDays = 30; // Default to monthly
    if ($cycle === 1) {
        $paymentCycleDays = 1 * $frequency;
    } else if ($cycle === 2) {
        $paymentCycleDays = 7 * $frequency;
    } else if ($cycle === 3) {
        $paymentCycleDays = 30 * $frequency;
    } else if ($cycle === 4) {
        $paymentCycleDays = 365 * $frequency;
    }

    if ($paymentCycleDays <= 0) {
        return 0;
    }

    // next_payment can be many cycles away from today (a stale value, or
    // several missed renewal runs), so we can't always assume it's within a
    // single cycle of "now". Walk back however many whole cycles are needed
    // so the window we measure progress against is the one that actually
    // contains today.
    $daysUntilNextPayment = $currentDate->diff($nextPaymentDate)->days;
    $cyclesBack = $currentDate <= $nextPaymentDate
        ? max(1, (int) ceil($daysUntilNextPayment / $paymentCycleDays))
        : 1;

    $lastPaymentDate = clone $nextPaymentDate;
    $lastPaymentDate->modify('-' . ($cyclesBack * $paymentCycleDays) . ' days');

    $daysSinceLastPayment = $lastPaymentDate->diff($currentDate)->days;
    $subscriptionProgress = ($daysSinceLastPayment / $paymentCycleDays) * 100;

    return floor($subscriptionProgress);
}
