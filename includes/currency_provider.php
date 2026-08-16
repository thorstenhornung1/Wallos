<?php
/*
  Shared currency exchange provider client.

  The settings page, the manual update endpoint and the cron job all fetch rates
  through this file, using the credentials returned by
  wallos_get_effective_currency_config().
*/

require_once __DIR__ . '/integration_config.php';

/**
 * Fetches exchange rates with EUR as the base currency.
 *
 * @param array  $config Result of wallos_get_effective_currency_config().
 * @param string $codes  Comma separated currency codes.
 * @return array{success: bool, rates: array, usage: array{limit: int|null, used: int|null}, message: string}
 */
function wallos_fetch_exchange_rates($config, $codes)
{
    $failure = [
        'success' => false,
        'rates' => [],
        'usage' => ['limit' => null, 'used' => null],
        'message' => '',
    ];

    if (empty($config['valid'])) {
        $failure['message'] = $config['notes'][0] ?? 'Currency provider is not configured.';

        return $failure;
    }

    $apiKey = (string) $config['values']['api_key'];
    $provider = (int) $config['values']['provider'];
    $usage = ['limit' => null, 'used' => null];

    // One shared credential should not spend one provider request per user, so
    // identical requests within a single run reuse the first response.
    static $cache = [];
    $cacheKey = md5($provider . '|' . $apiKey . '|' . $codes);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($provider === 1) {
        $apiUrl = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'apikey: ' . $apiKey,
            ]
        ]);
        $response = @file_get_contents($apiUrl, false, $context);

        // apilayer reports the monthly quota in its response headers; keep it so
        // the usage bar does not cost an extra API request.
        if (isset($http_response_header)) {
            $limit = null;
            $remaining = null;
            foreach ($http_response_header as $header) {
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
        $response = @file_get_contents($apiUrl);
    }

    if ($response === false) {
        $failure['message'] = 'The currency provider could not be reached.';

        return $failure;
    }

    $apiData = json_decode($response, true);

    if (!is_array($apiData) || !isset($apiData['rates'])) {
        $failure['usage'] = $usage;
        $failure['message'] = is_array($apiData) && isset($apiData['error']['info'])
            ? (string) $apiData['error']['info']
            : 'The currency provider returned an invalid response.';

        return $failure;
    }

    $cache[$cacheKey] = [
        'success' => true,
        'rates' => $apiData['rates'],
        'usage' => $usage,
        'message' => '',
    ];

    return $cache[$cacheKey];
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

    $stmt = $db->prepare('SELECT c.code FROM user u LEFT JOIN currencies c ON u.main_currency = c.id WHERE u.id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $mainCurrencyCode = $row ? $row['code'] : null;

    if (empty($mainCurrencyCode)) {
        return ['success' => false, 'message' => 'Main currency is not set.'];
    }

    $rates = wallos_fetch_exchange_rates($config, $codes);
    wallos_store_currency_usage($db, $config, $userId, $rates['usage']);

    if (!$rates['success']) {
        return ['success' => false, 'message' => $rates['message']];
    }

    if (!isset($rates['rates'][$mainCurrencyCode]) || !$rates['rates'][$mainCurrencyCode]) {
        return ['success' => false, 'message' => 'The provider did not return a rate for the main currency.'];
    }

    $mainCurrencyToEUR = $rates['rates'][$mainCurrencyCode];

    foreach ($rates['rates'] as $currencyCode => $rate) {
        $exchangeRate = $currencyCode === $mainCurrencyCode ? 1.0 : $rate / $mainCurrencyToEUR;

        $updateStmt = $db->prepare('UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId');
        $updateStmt->bindValue(':rate', $exchangeRate, SQLITE3_TEXT);
        $updateStmt->bindValue(':code', $currencyCode, SQLITE3_TEXT);
        $updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }

    $formattedDate = (new DateTime())->format('Y-m-d');

    $deleteStmt = $db->prepare('DELETE FROM last_exchange_update WHERE user_id = :userId');
    $deleteStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $deleteStmt->execute();

    $insertStmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, :userId)');
    $insertStmt->bindValue(':date', $formattedDate, SQLITE3_TEXT);
    $insertStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $insertStmt->execute();

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

    if (!$db->querySingle("SELECT COUNT(*) FROM pragma_table_info('fixer') WHERE name='usage_used'")) {
        return;
    }

    $stmt = $db->prepare('UPDATE fixer SET usage_used = :used, usage_limit = :limit, usage_updated_at = :updatedAt WHERE user_id = :userId');
    $stmt->bindValue(':used', $usage['used'], SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $usage['limit'], SQLITE3_INTEGER);
    $stmt->bindValue(':updatedAt', $updatedAt, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}
