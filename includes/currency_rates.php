<?php
/*
  Exchange-rate lookups for price conversion.

  Rates change once a day at most, but conversion happens once per subscription,
  once per statistics row and once per calendar entry. Looking them up per row
  turns rendering a list into one query per item, so they are loaded once and
  answered from memory for the rest of the request.
*/

/**
 * Returns the exchange rates as [currency_id => rate].
 *
 * Passing a user id restricts the map to that user's currencies, matching the
 * lookups that filtered by user; omitting it covers every currency, matching
 * the lookups that resolved a currency by id alone.
 *
 * The map is cached per database connection, so a second connection — a test,
 * or a job that reopens the database — never sees another connection's rates.
 *
 * @param SQLite3  $db
 * @param int|null $userId
 * @return array<int, float>
 */
function wallos_currency_rates($db, $userId = null)
{
    // Keyed by the connection object itself: an id would be reused once a
    // connection is closed, and the next one would inherit stale rates.
    static $cache = null;

    if ($cache === null) {
        $cache = new WeakMap();
    }

    $key = $userId === null ? 'all' : (int) $userId;
    $connectionRates = $cache[$db] ?? [];

    if (isset($connectionRates[$key])) {
        return $connectionRates[$key];
    }

    $rates = [];

    if ($userId === null) {
        $stmt = $db->prepare('SELECT id, rate FROM currencies');
    } else {
        $stmt = $db->prepare('SELECT id, rate FROM currencies WHERE user_id = :userId');
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    }

    $result = $stmt ? $stmt->execute() : false;

    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rates[(int) $row['id']] = (float) $row['rate'];
    }

    $connectionRates[$key] = $rates;
    $cache[$db] = $connectionRates;

    return $rates;
}

/**
 * Converts a price from the given currency into the user's main currency.
 *
 * An unknown currency or a missing rate leaves the price untouched, which is
 * what the per-row lookups did when they found no row.
 *
 * @param float|int|string $price
 * @param int              $currencyId
 * @param SQLite3          $db
 * @param int|null         $userId
 * @return float
 */
function wallos_convert_price($price, $currencyId, $db, $userId = null)
{
    $rates = wallos_currency_rates($db, $userId);
    $rate = $rates[(int) $currencyId] ?? null;

    if (empty($rate)) {
        return (float) $price;
    }

    return (float) $price / $rate;
}

/**
 * How many subscriptions reference each of a user's currencies, in one query.
 *
 * The settings page needs this to decide which currency rows may be deleted.
 * It used to ask once per currency — a COUNT(*) over subscriptions for every
 * row, and subscriptions.currency_id carries no index, so each was a scan
 * (#134): linear in currencies × subscriptions, on a page that only renders a
 * form. One GROUP BY answers all of them at once. A currency no subscription
 * uses is simply absent from the map, which the caller reads as zero — the
 * same answer the per-currency COUNT gave, without the per-currency query.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @return array<int, int> currency_id => subscription count
 */
function wallos_currency_usage_counts($db, $userId)
{
    $counts = [];
    $stmt = $db->prepare('SELECT currency_id, COUNT(*) AS count FROM subscriptions WHERE user_id = :userId GROUP BY currency_id');

    if ($stmt === false) {
        return $counts;
    }

    // Bare bind and bare fetch keep this new read off the SQLite boundary
    // audit (#20); both backends answer them the same.
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();

    while ($result && $row = $result->fetchArray()) {
        $counts[(int) $row['currency_id']] = (int) $row['count'];
    }

    return $counts;
}
