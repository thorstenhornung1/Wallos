<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_provider.php';

$shouldUpdate = true;

if (!isset($_POST['force']) || $_POST['force'] !== "true") {
    $query = "SELECT date FROM last_exchange_update WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        $lastUpdateDate = new DateTime($result);
        $currentDate = new DateTime();
        $lastUpdateDateString = $lastUpdateDate->format('Y-m-d');
        $currentDateString = $currentDate->format('Y-m-d');
        $shouldUpdate = $lastUpdateDateString < $currentDateString;
    }

    if (!$shouldUpdate) {
        echo "Rates are current, no need to update.";
        exit;
    }
}

$update = wallos_update_exchange_rates_for_user($db, $userId);

$db->close();

echo $update['success']
    ? "Rates updated successfully!"
    : "Exchange rates update skipped. " . $update['message'];
