<?php

/**
 * Fetch the subscriptions whose cancellation reminder is still ahead of us.
 *
 * One-time purchases are excluded: they have no recurring commitment to cancel,
 * and the subscription form clears their cancellation date. The cancellation
 * notification cron applies the same rule.
 *
 * $today is bound rather than written as date('now'): cancellation_date is
 * stored as 'YYYY-MM-DD' text and PostgreSQL has no date('now'), so the
 * boundary is computed once in PHP. UTC by default, which is the day SQLite
 * answered whatever timezone PHP was set to.
 *
 * @param WallosDatabase     $db
 * @param int         $userId
 * @param string|null $today 'YYYY-MM-DD'; today in UTC when omitted
 * @return array
 */
function get_upcoming_cancellations($db, $userId, $today = null)
{
    $today = $today ?? gmdate('Y-m-d');

    $stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, cycle, frequency, cancellation_date
        FROM subscriptions
        WHERE user_id = :userId
          AND inactive = 0
          AND cancellation_date IS NOT NULL
          AND cancellation_date != ''
          AND cancellation_date >= :today
          AND cycle != 5
        ORDER BY cancellation_date ASC");

    if ($stmt === false) {
        // An empty list rather than a fatal: bindValue() on false is a fatal,
        // and this runs while the dashboard is already rendering (#87).
        return [];
    }

    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':today', $today);
    $result = $stmt->execute();

    $subscriptions = [];
    while ($result && ($row = $result->fetchArray())) {
        $subscriptions[] = $row;
    }

    return $subscriptions;
}

/**
 * Total monthly cost, in the user's main currency, of everything currently
 * flagged for cancellation — what the user would stop spending by cancelling.
 *
 * Expects getPricePerMonth() and getPriceConverted() from stats_calculations.php.
 *
 * @param array   $cancellations rows from get_upcoming_cancellations()
 * @param WallosDatabase $db
 * @param int     $userId
 * @return float
 */
function get_upcoming_cancellations_monthly_value($cancellations, $db, $userId)
{
    $total = 0;

    foreach ($cancellations as $subscription) {
        $perMonth = getPricePerMonth($subscription['cycle'], $subscription['frequency'], $subscription['price']);
        $total += getPriceConverted($perMonth, $subscription['currency_id'], $db, $userId);
    }

    return $total;
}
