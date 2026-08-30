<?php
/*
  Shared currency exchange provider client.

  The settings page, the manual update endpoint and the cron job all fetch rates
  through this file, using the credentials returned by
  wallos_get_effective_currency_config().
*/

require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/http_status.php';

if (!function_exists('wallos_provider_http_get')) {
    /**
     * The one network touch of the provider client, separated so a test can
     * stand in for the provider without a socket — the same reasoning that
     * put the status logic into http_status.php: no test in this suite makes
     * a request, and the one that proved it the expensive way spent half a
     * year of a free tier (#104). A test defines its own version before this
     * file is loaded; the guard lets that stand.
     *
     * @param string   $url
     * @param resource $context
     * @return array{body: string|false, headers: array|null}
     */
    function wallos_provider_http_get($url, $context)
    {
        $body = @file_get_contents($url, false, $context);

        // Populated by PHP only when a response actually arrived, which is
        // what separates a refusal from an outage.
        return [
            'body' => $body,
            'headers' => isset($http_response_header) ? $http_response_header : null,
        ];
    }
}

/**
 * Fetches exchange rates with EUR as the base currency.
 *
 * The transport flag says whether this answer cost a request over the wire —
 * false for a refused config and for answers served from the per-run cache.
 * Call sites count provider consumption by it (#106), so a cached answer
 * must never carry the mark of the request it reuses.
 *
 * @param array  $config Result of wallos_get_effective_currency_config().
 * @param string $codes  Comma separated currency codes.
 * @return array{success: bool, rates: array, usage: array{limit: int|null, used: int|null}, message: string, transport: bool}
 */
function wallos_fetch_exchange_rates($config, $codes)
{
    $failure = [
        'success' => false,
        'rates' => [],
        'usage' => ['limit' => null, 'used' => null],
        'message' => '',
        'transport' => false,
    ];

    if (empty($config['valid'])) {
        $failure['message'] = $config['notes'][0] ?? 'Currency provider is not configured.';

        return $failure;
    }

    $apiKey = (string) $config['values']['api_key'];
    $provider = (int) $config['values']['provider'];
    $usage = ['limit' => null, 'used' => null];

    // One shared credential should not spend one provider request per user.
    // Within a run, an earlier answer serves any request whose codes it
    // covers — the same list, or a subset of a union fetched up front (#9).
    // A covering refusal answers too: a quota exhausted for the union is
    // exhausted for every part of it, and asking again with fewer symbols
    // would spend a call to learn the same thing (#117). The caller only
    // reads the rates it owns, so extra rates in a covering answer are
    // simply not looked at.
    static $cache = [];
    $requested = array_filter(array_map('trim', explode(',', $codes)));
    $credential = $provider . '|' . $apiKey;

    foreach ($cache as $entry) {
        if ($entry['credential'] === $credential
            && array_diff($requested, $entry['codes']) === []) {
            return $entry['result'];
        }
    }

    if ($provider === 1) {
        $apiUrl = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'apikey: ' . $apiKey,
                // Without this a 401 arrives as false, indistinguishable from
                // the network being down. With it, the provider's own
                // explanation arrives instead (issue #101).
                'ignore_errors' => true,
            ]
        ]);
        $http = wallos_provider_http_get($apiUrl, $context);
        $response = $http['body'];

        // apilayer reports the monthly quota in its response headers; keep it so
        // the usage bar does not cost an extra API request.
        if (is_array($http['headers'])) {
            $limit = null;
            $remaining = null;
            foreach ($http['headers'] as $header) {
                if (stripos($header, 'x-ratelimit-limit-month:') === 0) {
                    $limit = (int) trim(substr($header, strlen('x-ratelimit-limit-month:')));
                } elseif (stripos($header, 'x-ratelimit-remaining-month:') === 0) {
                    $remaining = (int) trim(substr($header, strlen('x-ratelimit-remaining-month:')));
                }
            }
            if ($limit !== null && $remaining !== null) {
                $usage = ['limit' => $limit, 'used' => $limit - $remaining];
            }
        }
    } else {
        $apiUrl = "http://data.fixer.io/api/latest?access_key=" . $apiKey . "&base=EUR&symbols=" . $codes;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ]
        ]);
        $http = wallos_provider_http_get($apiUrl, $context);
        $response = $http['body'];
    }

    $status = wallos_http_status_code($http['headers']);

    if ($response === false) {
        $failure['usage'] = $usage;
        $failure['message'] = wallos_provider_failure_message($status, null);

        // Cached like a success: a provider that was unreachable a moment ago
        // will not answer the next account in the same run either, and
        // retrying per account multiplies timeouts, not information (#117).
        $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $failure];

        $failure['transport'] = true;

        return $failure;
    }

    $apiData = json_decode($response, true);

    if (!is_array($apiData) || !isset($apiData['rates'])) {
        $failure['usage'] = $usage;
        $failure['message'] = wallos_provider_failure_message($status, $apiData);

        // A key the provider just rejected is rejected for every account
        // sharing it in this run. Before this, a run over N accounts with an
        // exhausted quota spent N calls to learn the same 429 N times —
        // observed on the test instance on 2026-08-28 (#117). The cache key
        // includes the code list, so accounts with different currency lists
        // still ask once each; a key-level negative cache is deliberately
        // not attempted.
        $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $failure];

        $failure['transport'] = true;

        return $failure;
    }

    $fresh = [
        'success' => true,
        'rates' => $apiData['rates'],
        'usage' => $usage,
        'message' => '',
        'transport' => false,
    ];

    $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $fresh];

    $fresh['transport'] = true;

    return $fresh;
}

/**
 * One provider request for everyone the shared credential serves (#9).
 *
 * Called by the scheduled refresh before it walks its users: the accounts
 * that inherit the instance credential and are due today are grouped, the
 * union of their currency codes is fetched once, and the per-user updates
 * that follow are answered from the run cache — the covering-answer rule
 * above is what makes a user's smaller list a hit. Users with their own key
 * are their own group of one and gain nothing here (the issue's rule 8);
 * a user already refreshed today neither fetches nor widens the union, the
 * #117 rule carried forward — symbols nobody due needs are quota spent on
 * nothing.
 *
 * @param SQLite3 $db
 * @param int[]   $userIds Every account the caller is about to refresh.
 */
function wallos_prewarm_shared_exchange_rates($db, $userIds)
{
    $due = [];
    $config = null;

    foreach ($userIds as $userId) {
        if (wallos_exchange_rates_fresh($db, $userId)) {
            continue;
        }

        $candidate = wallos_get_effective_currency_config($db, $userId);

        if (empty($candidate['valid']) || ($candidate['mode'] ?? 'instance') !== 'instance') {
            continue;
        }

        $due[] = (int) $userId;
        $config = $candidate;
    }

    // One due user's own fetch already is the union; only two or more share.
    if (count($due) < 2) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($due), '?'));
    $stmt = $db->prepare('SELECT DISTINCT code FROM currencies WHERE user_id IN ('
        . $placeholders . ') ORDER BY code');

    if ($stmt === false) {
        return;
    }

    foreach ($due as $index => $userId) {
        $stmt->bindValue($index + 1, $userId, SQLITE3_INTEGER);
    }

    $result = $stmt->execute();
    $codes = [];
    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $codes[] = $row['code'];
    }

    if ($codes === []) {
        return;
    }

    $rates = wallos_fetch_exchange_rates($config, implode(',', $codes));

    // The union request is a real provider call like any other: counted
    // (#106), and its quota headers recorded, whatever the per-user updates
    // after it do.
    if (!empty($rates['transport'])) {
        wallos_count_currency_call($db, $config, $due[0]);
        wallos_store_currency_usage($db, $config, $due[0], $rates['usage']);
    }
}

/**
 * Counts one provider request made from this installation.
 *
 * The provider's own figure arrives only in apilayer's response headers;
 * fixer.io reports nothing, which is how a QA round spent six months of a
 * 100-call tier while the usage area stayed empty (#104). This records what
 * Wallos itself sends — per calendar month, with the key's holder: the
 * instance when the key is shared, the user when it is their own. An
 * estimate by design: it cannot see other software using the same key, and
 * it counts attempts whether or not the provider accepted them. Whether the
 * provider's billing period agrees with the calendar month is what the turn
 * of 2026-09-01 is calibrated against.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 */
function wallos_count_currency_call($db, $config, $userId)
{
    $month = date('Y-m');

    if (($config['mode'] ?? 'instance') === 'instance') {
        $instance = wallos_get_instance_settings($db, 'currency');
        $calls = ($instance['local_calls_month'] ?? '') === $month
            ? (int) ($instance['local_calls'] ?? 0)
            : 0;

        wallos_set_instance_setting($db, 'currency', 'local_calls', (string) ($calls + 1));
        wallos_set_instance_setting($db, 'currency', 'local_calls_month', $month);

        return;
    }

    if (!$db->columnExists('fixer', 'local_calls')) {
        return;
    }

    $stmt = $db->prepare('SELECT local_calls, local_calls_month FROM fixer WHERE user_id = :userId');

    if ($stmt === false) {
        return;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if (!$row) {
        // No stored key row means nowhere to keep the figure; the request
        // itself already happened, so there is nothing useful to refuse.
        return;
    }

    $calls = ($row['local_calls_month'] ?? '') === $month ? (int) $row['local_calls'] : 0;

    $stmt = $db->prepare('UPDATE fixer SET local_calls = :calls, local_calls_month = :month WHERE user_id = :userId');

    if ($stmt === false) {
        error_log('Wallos: could not record the provider call for user ' . $userId . ': '
            . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':calls', $calls + 1, SQLITE3_INTEGER);
    $stmt->bindValue(':month', $month, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    // The counter is what makes consumption visible on the settings page; a
    // figure that silently stopped moving would repeat the defect it fixes.
    if ($stmt->execute() === false) {
        error_log('Wallos: could not record the provider call for user ' . $userId . ': '
            . $db->lastErrorMsg());
    }
}

/**
 * The local request count for whoever holds the effective key, in the
 * current calendar month.
 *
 * A month that has turned since the last request answers zero without
 * writing anything; null means the installation cannot count yet — a
 * database from before migration 000069.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 * @return int|null
 */
function wallos_currency_local_calls($db, $config, $userId)
{
    $month = date('Y-m');

    if (($config['mode'] ?? 'instance') === 'instance') {
        $instance = wallos_get_instance_settings($db, 'currency');

        return ($instance['local_calls_month'] ?? '') === $month
            ? (int) ($instance['local_calls'] ?? 0)
            : 0;
    }

    if (!$db->columnExists('fixer', 'local_calls')) {
        return null;
    }

    $stmt = $db->prepare('SELECT local_calls, local_calls_month FROM fixer WHERE user_id = :userId');

    if ($stmt === false) {
        return null;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if (!$row) {
        return 0;
    }

    return ($row['local_calls_month'] ?? '') === $month ? (int) $row['local_calls'] : 0;
}

/**
 * Whether one user's rates were already refreshed today.
 *
 * The one answer both refresh paths agree on: the manual endpoint skips a
 * fresh user unless forced, and since #117 the cron and the startup run skip
 * them too — a container start used to spend one provider call per account
 * whatever the rates' age, so deploy frequency alone could exhaust a free
 * tier. A missing or unreadable row answers false, because refusing to
 * refresh over an unestablished freshness would be the wrong default.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_exchange_rates_fresh($db, $userId)
{
    $stmt = $db->prepare('SELECT date FROM last_exchange_update WHERE user_id = :userId');

    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row && !empty($row['date']) && $row['date'] >= (new DateTime())->format('Y-m-d');
}

/**
 * Updates the stored exchange rates of one user with the provider credentials
 * that are effective for them.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return array{success: bool, message: string}
 */
function wallos_update_exchange_rates_for_user($db, $userId)
{
    $config = wallos_get_effective_currency_config($db, $userId);

    if (!$config['valid']) {
        return [
            'success' => false,
            'message' => $config['notes'][0] ?? 'Currency provider is not configured.',
        ];
    }

    $codes = "";
    $stmt = $db->prepare('SELECT code FROM currencies WHERE user_id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $codes .= $row['code'] . ",";
    }
    $codes = rtrim($codes, ',');

    if ($codes === "") {
        return ['success' => false, 'message' => 'No currencies configured.'];
    }

    $stmt = $db->prepare('SELECT c.code FROM "user" u LEFT JOIN currencies c ON u.main_currency = c.id WHERE u.id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $mainCurrencyCode = $row ? $row['code'] : null;

    if (empty($mainCurrencyCode)) {
        return ['success' => false, 'message' => 'Main currency is not set.'];
    }

    $rates = wallos_fetch_exchange_rates($config, $codes);

    // Counted per request that went over the wire, not per account: the
    // per-run cache answers repeat asks without spending quota, and the
    // counter has to agree with what the provider saw (#106).
    if (!empty($rates['transport'])) {
        wallos_count_currency_call($db, $config, $userId);
    }

    wallos_store_currency_usage($db, $config, $userId, $rates['usage']);

    if (!$rates['success']) {
        return ['success' => false, 'message' => $rates['message']];
    }

    if (!isset($rates['rates'][$mainCurrencyCode]) || !$rates['rates'][$mainCurrencyCode]) {
        return ['success' => false, 'message' => 'The provider did not return a rate for the main currency.'];
    }

    $mainCurrencyToEUR = $rates['rates'][$mainCurrencyCode];

    // One user's rates and their refresh date are one unit of work. Rates are
    // only comparable when they share a conversion base, so a refresh that
    // stops halfway would leave a set that looks plausible and is wrong.
    $db->exec('BEGIN');

    $updateStmt = $db->prepare('UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId');

    foreach ($rates['rates'] as $currencyCode => $rate) {
        $exchangeRate = $currencyCode === $mainCurrencyCode ? 1.0 : $rate / $mainCurrencyToEUR;

        $updateStmt->bindValue(':rate', $exchangeRate, SQLITE3_TEXT);
        $updateStmt->bindValue(':code', $currencyCode, SQLITE3_TEXT);
        $updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

        if (!$updateStmt->execute()) {
            $db->exec('ROLLBACK');

            return ['success' => false, 'message' => 'Rate update failed for ' . $currencyCode . '; the previous rates were kept.'];
        }

        $updateStmt->reset();
    }

    $formattedDate = (new DateTime())->format('Y-m-d');

    // Checked the same way the rate updates above are, and inside the same
    // transaction. Discarding these two results meant the rates could be
    // committed with no record that they had been updated — after which the
    // job either refetches them every run, spending quota on a provider that
    // charges per call, or the page reports rates as older than they are
    // (issue #87).
    $recorded = false;
    $deleteStmt = $db->prepare('DELETE FROM last_exchange_update WHERE user_id = :userId');

    if ($deleteStmt !== false) {
        $deleteStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $recorded = $deleteStmt->execute() !== false;
    }

    if ($recorded) {
        $recorded = false;
        $insertStmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, :userId)');

        if ($insertStmt !== false) {
            $insertStmt->bindValue(':date', $formattedDate, SQLITE3_TEXT);
            $insertStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $recorded = $insertStmt->execute() !== false;
        }
    }

    if (!$recorded) {
        // Rolled back rather than committed with the timestamp missing: the
        // previous rates and their date belong together, and the caller can
        // retry. Same reasoning as the rate loop above.
        $db->exec('ROLLBACK');

        return [
            'success' => false,
            'message' => 'The rates were fetched, but the update could not be recorded; '
                . 'the previous rates were kept.',
        ];
    }

    $db->exec('COMMIT');

    return ['success' => true, 'message' => 'Rates updated successfully!'];
}

/**
 * Records provider quota where it belongs: with the instance when the key is
 * shared, with the user when the key is their own. Shared usage must not be
 * presented as if it were private to whoever triggered the update.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 * @param array   $usage  ['limit' => int|null, 'used' => int|null]
 */
function wallos_store_currency_usage($db, $config, $userId, $usage)
{
    if ($usage['limit'] === null || $usage['used'] === null) {
        return;
    }

    $updatedAt = date('Y-m-d H:i:s');

    if (($config['mode'] ?? 'instance') === 'instance') {
        wallos_set_instance_setting($db, 'currency', 'usage_used', (string) $usage['used']);
        wallos_set_instance_setting($db, 'currency', 'usage_limit', (string) $usage['limit']);
        wallos_set_instance_setting($db, 'currency', 'usage_updated_at', $updatedAt);

        return;
    }

    if (!$db->columnExists('fixer', 'usage_used')) {
        return;
    }

    $stmt = $db->prepare('UPDATE fixer SET usage_used = :used, usage_limit = :limit, usage_updated_at = :updatedAt WHERE user_id = :userId');

    if ($stmt === false) {
        error_log('Wallos: could not record provider quota for user ' . $userId . ': '
            . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':used', $usage['used'], SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $usage['limit'], SQLITE3_INTEGER);
    $stmt->bindValue(':updatedAt', $updatedAt, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    // Quota is what the settings page shows to explain why refreshes stopped
    // working. A number that silently stayed where it was is worse than none.
    if ($stmt->execute() === false) {
        error_log('Wallos: could not record provider quota for user ' . $userId . ': '
            . $db->lastErrorMsg());
    }
}
