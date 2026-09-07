<?php
/*
  Which subscription payments fall inside one calendar month.

  This was inline in calendar.php, where it could not be checked: the page
  computes the projection and the three monthly figures in the same loop that
  renders the grid, so the only way to find out what it counted was to look at
  the rendered page. It counted wrong — the "active subscriptions" tile was
  incremented once per *payment*, so a daily subscription reported itself 30
  times — and nothing could have caught that.

  The projection lives here so it can be asked directly. calendar.php keeps the
  money, because converting prices needs the database and the rate map.
*/

/**
 * Every payment occurrence of the given subscriptions inside one month.
 *
 * A subscription pays on `next_payment` and every `frequency` cycles around it,
 * in both directions, but never before its `start_date`. A one-time purchase
 * (cycle 5) pays once, on its date.
 *
 * @param array $subscriptions rows with cycle, frequency, next_payment, start_date
 * @param int   $year
 * @param int   $month 1-12
 * @param int   $yearsToLoad how far past next_payment to project
 * @return array{byDay: array<int, array<int, array>>, occurrences: int, subscriptions: int}
 *         byDay maps day-of-month to the subscriptions paying that day;
 *         occurrences counts every payment, subscriptions counts distinct
 *         subscriptions that pay at all this month — the two differ whenever a
 *         subscription pays more than once, which is the whole point.
 */
function wallos_calendar_month_occurrences(array $subscriptions, $year, $month, $yearsToLoad = 5)
{
    $monthKey = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    $startOfMonth = strtotime($monthKey . '-01');

    $byDay = [];
    $occurrences = 0;
    $paying = [];

    $register = function ($date, $subscription) use (&$byDay, &$occurrences, &$paying) {
        $byDay[(int) date('j', $date)][] = $subscription;
        $occurrences++;
        // Keyed by id so a subscription paying weekly is one subscription, not
        // four. Rows without an id (never the case from the query, but the
        // helper must not depend on that) fall back to their position.
        $paying[$subscription['id'] ?? count($paying)] = true;
    };

    foreach ($subscriptions as $subscription) {
        $nextPaymentDate = strtotime($subscription['next_payment']);
        $subscriptionStartDate = !empty($subscription['start_date'])
            ? strtotime($subscription['start_date'])
            : $nextPaymentDate;
        $cycle = $subscription['cycle'];
        $frequency = $subscription['frequency'];

        if ($cycle == 5) {
            // One-time purchase: only on its exact payment date.
            if (date('Y-m', $nextPaymentDate) == $monthKey) {
                $register($nextPaymentDate, $subscription);
            }
            continue;
        }

        switch ($cycle) {
            case 1:
                $incrementString = "+{$frequency} days";
                break;
            case 2:
                $incrementString = "+{$frequency} weeks";
                break;
            case 3:
                $incrementString = "+{$frequency} months";
                break;
            case 4:
                $incrementString = "+{$frequency} years";
                break;
            default:
                $incrementString = "+{$frequency} months";
        }

        $endDate = strtotime("+" . $yearsToLoad . " years", $nextPaymentDate);

        // Walk back to the first occurrence at or before this month, then
        // forward through it.
        $startDate = $nextPaymentDate;
        while ($startDate > $startOfMonth) {
            $startDate = strtotime("-" . $incrementString, $startDate);
        }

        for ($date = $startDate; $date <= $endDate; $date = strtotime($incrementString, $date)) {
            if ($date < $subscriptionStartDate) {
                continue;
            }
            if (date('Y-m', $date) == $monthKey) {
                $register($date, $subscription);
            }
        }
    }

    return [
        'byDay' => $byDay,
        'occurrences' => $occurrences,
        'subscriptions' => count($paying),
    ];
}
