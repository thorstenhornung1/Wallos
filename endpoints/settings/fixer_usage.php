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
    'local_calls' => wallos_currency_local_calls($db, $config, $userId),
    'rates_updated' => null,
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

if ($provider === 1) {
    $used = null;
    $limit = null;

    if ($isInstance) {
        // A shared credential has shared quota, so it is reported as such
        // instead of looking like this user's private allowance.
        $instance = wallos_get_instance_settings($db, 'currency');
        $used = $instance['usage_used'] ?? null;
        $limit = $instance['usage_limit'] ?? null;
    } elseif ($db->columnExists('fixer', 'usage_used')) {
        $stmt = $db->prepare('SELECT usage_used, usage_limit FROM fixer WHERE user_id = :userId');

        if ($stmt !== false) {
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

            if ($row) {
                $used = $row['usage_used'];
                $limit = $row['usage_limit'];
            }
        }
    }

    if ($used !== null && !empty($limit)) {
        $response['used'] = (int) $used;
        $response['total'] = (int) $limit;
        $response['exhausted'] = (int) $used >= (int) $limit;
    }
}

$db->close();

die(json_encode($response));
