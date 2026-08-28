<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_provider.php';

$shouldUpdate = true;

if (!isset($_POST['force']) || $_POST['force'] !== "true") {
    // The date has to be read off the result before it can be compared.
    // execute() returns a result object, and handing that object to DateTime
    // threw a TypeError on every request since this file exists — so the
    // skip below, and the force override above, had never run once (#120).
    $query = "SELECT date FROM last_exchange_update WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $row = false;

    if ($stmt !== false) {
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    }

    // A missing or unreadable row means update: refusing to refresh because
    // the freshness could not be established would be the wrong default.
    if ($row && !empty($row['date'])) {
        $shouldUpdate = $row['date'] < (new DateTime())->format('Y-m-d');
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
