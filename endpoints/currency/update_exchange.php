<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_provider.php';

if (!isset($_POST['force']) || $_POST['force'] !== "true") {
    // One implementation for both refresh paths; the cron asks the same
    // question since #117. This check had never run before #120 — the date
    // was compared without being read off the result first.
    if (wallos_exchange_rates_fresh($db, $userId)) {
        echo "Rates are current, no need to update.";
        exit;
    }
}

$update = wallos_update_exchange_rates_for_user($db, $userId);

$db->close();

echo $update['success']
    ? "Rates updated successfully!"
    : "Exchange rates update skipped. " . $update['message'];
