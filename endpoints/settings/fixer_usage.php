<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_session.php';
require_once '../../includes/integration_config.php';
require_once '../../includes/currency_provider.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die(json_encode(["success" => false]));
}

// What the page can honestly say about provider consumption, in one shape for
// every provider. There are three states and they must not be collapsed into
// two: apilayer reports its monthly figure in response headers, captured from
// rate updates so this endpoint never calls the API; fixer.io has a quota and
// reports nothing about it, and for years that absence rendered as an empty
// area indistinguishable from "plenty left" (#104); Frankfurter has no quota
// at all, which is not the same claim as "unknown" and must not be drawn as a
// bar at 0%. Wallos's own count and the date of the last successful refresh
// exist in all three (#106).
//
// None of this costs a request: every figure below comes out of the database.
$config = wallos_get_effective_currency_config($db, $userId);

$provider = (int) ($config['values']['provider'] ?? 0);
$isInstance = ($config['mode'] ?? 'instance') === 'instance';

$response = [
    'success' => true,
    'provider' => $provider,
    'has_quota' => wallos_currency_provider_has_quota($provider),
    'provider_reports' => $provider === 1,
    'shared' => $isInstance,
    'used' => null,
    'total' => null,
    'exhausted' => false,
    // The daily pair, apart from the monthly one: a day's limit reached clears
    // by tomorrow's cron, a month's quota exhausted does not, and the reader is
    // owed the difference (#106).
    'used_day' => null,
    'total_day' => null,
    'daily_limit_reached' => false,
    'local_calls' => wallos_currency_local_calls($db, $config, $userId),
    'rates_updated' => null,
    // Whether the last successful refresh has fallen far enough behind to read
    // as a stall rather than a normal daily gap. Derived below from the date
    // alone — no provider request (#106).
    'rates_stale' => false,
];

// The last successful refresh is per user whatever the key mode: rates are
// stored per user, and stale rates are what the reader is trying to rule out.
$stmt = $db->prepare('SELECT date FROM last_exchange_update WHERE user_id = :userId');

if ($stmt !== false) {
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row && !empty($row['date'])) {
        $response['rates_updated'] = $row['date'];
    }
}

// A refresh runs daily, so a stored date older than yesterday means the
// automatic updates have stopped — the stall #106 is about. One day of slack
// absorbs a cron that has not run yet today. A never-refreshed instance carries
// no date and is "not yet", not "stalled", so a null date is left alone.
if ($response['rates_updated'] !== null) {
    $response['rates_stale'] = $response['rates_updated'] < date('Y-m-d', strtotime('-1 day'));
}

if ($provider === 1) {
    $used = null;
    $limit = null;
    $usedDay = null;
    $limitDay = null;

    if ($isInstance) {
        // A shared credential has shared quota, so it is reported as such
        // instead of looking like this user's private allowance.
        $instance = wallos_get_instance_settings($db, 'currency');
        $used = $instance['usage_used'] ?? null;
        $limit = $instance['usage_limit'] ?? null;
        $usedDay = $instance['usage_used_day'] ?? null;
        $limitDay = $instance['usage_limit_day'] ?? null;
    } elseif ($db->columnExists('fixer', 'usage_used')) {
        // The daily columns arrived in migration 000075; read them only where
        // they exist, in the one query that already reads the monthly pair.
        $hasDay = $db->columnExists('fixer', 'usage_used_day');
        $columns = $hasDay
            ? 'usage_used, usage_limit, usage_used_day, usage_limit_day'
            : 'usage_used, usage_limit';
        $stmt = $db->prepare('SELECT ' . $columns . ' FROM fixer WHERE user_id = :userId');

        if ($stmt !== false) {
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

            if ($row) {
                $used = $row['usage_used'];
                $limit = $row['usage_limit'];

                if ($hasDay) {
                    $usedDay = $row['usage_used_day'] ?? null;
                    $limitDay = $row['usage_limit_day'] ?? null;
                }
            }
        }
    }

    if ($used !== null && !empty($limit)) {
        $response['used'] = (int) $used;
        $response['total'] = (int) $limit;
        $response['exhausted'] = (int) $used >= (int) $limit;
    }

    if ($usedDay !== null && !empty($limitDay)) {
        $response['used_day'] = (int) $usedDay;
        $response['total_day'] = (int) $limitDay;
        $response['daily_limit_reached'] = (int) $usedDay >= (int) $limitDay;
    }
}

$db->close();

die(json_encode($response));
