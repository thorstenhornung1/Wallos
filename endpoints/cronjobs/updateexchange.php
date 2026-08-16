<?php
require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/currency_provider.php';

require 'settimezone.php';

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
}

$query = "SELECT id, username FROM user";
$stmt = $db->prepare($query);
$usersToUpdateExchange = $stmt->execute();

while ($userToUpdateExchange = $usersToUpdateExchange->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToUpdateExchange['id'];
    echo "For user: " . $userToUpdateExchange['username'] . "<br />";

    $update = wallos_update_exchange_rates_for_user($db, $userId);

    if ($update['success']) {
        echo "Rates updated successfully!<br />";
    } else {
        echo "Exchange rates update skipped. " . $update['message'] . "<br />";
    }
}

$db->close();

?>
