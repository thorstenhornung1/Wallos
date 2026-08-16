<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/integration_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die(json_encode(["success" => false]));
}

// Usage is only available for the apilayer provider; it is captured from the
// response headers of rate updates, so this endpoint never calls the API.
$config = wallos_get_effective_currency_config($db, $userId);

if ((int) ($config['values']['provider'] ?? 0) !== 1) {
    die(json_encode(["success" => false]));
}

if ($config['mode'] === 'instance') {
    // A shared credential has shared quota, so it is reported as such instead
    // of looking like this user's private allowance.
    $instance = wallos_get_instance_settings($db, 'currency');

    if (!isset($instance['usage_used']) || empty($instance['usage_limit'])) {
        die(json_encode(["success" => false]));
    }

    die(json_encode([
        "success" => true,
        "used" => (int) $instance['usage_used'],
        "total" => (int) $instance['usage_limit'],
        "shared" => true,
    ]));
}

if ($db->querySingle("SELECT COUNT(*) FROM pragma_table_info('fixer') WHERE name='usage_used'") == 0) {
    die(json_encode(["success" => false]));
}

$stmt = $db->prepare("SELECT usage_used, usage_limit FROM fixer WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

if (!$row || $row['usage_used'] === null || !$row['usage_limit']) {
    die(json_encode(["success" => false]));
}

die(json_encode([
    "success" => true,
    "used" => (int) $row['usage_used'],
    "total" => (int) $row['usage_limit'],
    "shared" => false,
]));
