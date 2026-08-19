<?php

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('updatenextpayment');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
wallos_cron_database($db);

require 'settimezone.php';

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
echo $timezone . "<br />\n";

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

$cycles = array();
$query = "SELECT * FROM cycles";
$result = $db->query($query);

if ($result === false) {
    wallos_cron_fail('could not read the payment cycles: ' . wallos_cron_reason($db));
}

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $cycleId = $row['id'];
    $cycles[$cycleId] = $row;
}

$query = "SELECT id, next_payment, frequency, cycle FROM subscriptions WHERE next_payment < :currentDate AND auto_renew = 1 AND inactive = 0";
$stmt = $db->prepare($query);
$stmt->bindValue(':currentDate', $currentDate->format('Y-m-d'));
$result = $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not read the overdue subscriptions: ' . wallos_cron_reason($db));
}

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptionId = $row['id'];
    $nextPaymentDate = new DateTime($row['next_payment']);
    $frequency = $row['frequency'];
    $cycle = $cycles[$row['cycle']]['name'];

    // Calculate the interval to add based on the cycle
    $intervalSpec = "P";
    if ($cycle == 'Daily') {
        $intervalSpec .= "{$frequency}D";
    } elseif ($cycle === 'Weekly') {
        $intervalSpec .= "{$frequency}W";
    } elseif ($cycle === 'Monthly') {
        $intervalSpec .= "{$frequency}M";
    } elseif ($cycle === 'Yearly') {
        $intervalSpec .= "{$frequency}Y";
    }

    $interval = new DateInterval($intervalSpec);

    // Add intervals until the next payment date is in the future
    while ($nextPaymentDate < $currentDate) {
        $nextPaymentDate->add($interval);
    }

    // Update the subscription's next_payment date
    $updateQuery = "UPDATE subscriptions SET next_payment = :nextPaymentDate WHERE id = :subscriptionId";
    $updateStmt = $db->prepare($updateQuery);

    if ($updateStmt === false) {
        wallos_cron_problem('could not prepare the update for subscription ' . $subscriptionId
            . ': ' . wallos_cron_reason($db));
        continue;
    }

    $updateStmt->bindValue(':nextPaymentDate', $nextPaymentDate->format('Y-m-d'));
    $updateStmt->bindValue(':subscriptionId', $subscriptionId);

    // A subscription that does not move forward stays overdue for ever: the
    // dashboard shows it in the overdue list, the notification window has
    // already passed, and the next run selects it again and fails again. It is
    // worth naming the row rather than the count.
    if ($updateStmt->execute() === false) {
        wallos_cron_problem('could not move subscription ' . $subscriptionId
            . ' to its next payment date: ' . wallos_cron_reason($db));
        continue;
    }

    wallos_cron_count('advanced');
}

$formattedDate = $currentDate->format('Y-m-d');

$deleteQuery = "DELETE FROM last_update_next_payment_date";
$deleteStmt = $db->prepare($deleteQuery);
$deleteResult = $deleteStmt === false ? false : $deleteStmt->execute();

$query = "INSERT INTO last_update_next_payment_date (date) VALUES (:formattedDate)";
$stmt = $db->prepare($query);

if ($deleteResult === false || $stmt === false) {
    wallos_cron_fail('could not record the run date: ' . wallos_cron_reason($db));
}

$stmt->bindParam(':formattedDate', $currentDateString, SQLITE3_TEXT);
$result = $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not record the run date: ' . wallos_cron_reason($db));
}

wallos_cron_done('advanced to ' . $formattedDate);

echo "Updated next payment dates";
?>