<?php

const UPCOMING_PAYMENTS_LIMITS = [3, 5, 10, 20];

/**
 * Parse a dashboard limit and return null for unsupported values.
 *
 * @param mixed $limit
 * @return int|null
 */
function parse_upcoming_payments_limit($limit)
{
    if (is_string($limit) && ctype_digit($limit)) {
        $limit = (int) $limit;
    }

    return is_int($limit) && in_array($limit, UPCOMING_PAYMENTS_LIMITS, true) ? $limit : null;
}

/**
 * Return a supported dashboard limit, falling back to the current default.
 *
 * @param mixed $limit
 * @return int
 */
function normalize_upcoming_payments_limit($limit)
{
    return parse_upcoming_payments_limit($limit) ?? 3;
}

/**
 * Fetch the upcoming subscriptions shown on the dashboard.
 *
 * $today is bound rather than written as date('now'): next_payment is stored as
 * 'YYYY-MM-DD' text, PostgreSQL has no date('now'), and the comparison has to
 * land on the same day on both backends. gmdate() is the default because that
 * is the day SQLite's date('now') answered, whatever timezone PHP was set to.
 *
 * @param WallosDatabase     $db
 * @param int         $userId
 * @param mixed       $limit
 * @param string|null $today 'YYYY-MM-DD'; today in UTC when omitted
 * @return array
 */
function get_upcoming_payments($db, $userId, $limit, $today = null)
{
    $limit = normalize_upcoming_payments_limit($limit);
    $today = $today ?? gmdate('Y-m-d');

    $stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, next_payment, inactive
        FROM subscriptions
        WHERE user_id = :userId
          AND next_payment >= :today
          AND inactive = 0
          AND cycle != 5
        ORDER BY next_payment ASC
        LIMIT :limit");

    if ($stmt === false) {
        // An empty list rather than a fatal: bindValue() on false is a fatal,
        // and this runs while the dashboard is already rendering (#87).
        return [];
    }

    $stmt->bindValue(':userId', $userId);
    $stmt->bindValue(':today', $today);
    $stmt->bindValue(':limit', $limit);
    $result = $stmt->execute();

    $subscriptions = [];
    while ($result && ($row = $result->fetchArray())) {
        $subscriptions[] = $row;
    }

    return $subscriptions;
}
