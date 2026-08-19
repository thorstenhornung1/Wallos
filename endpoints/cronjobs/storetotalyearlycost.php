<?php
require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('storetotalyearlycost');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/currency_rates.php';
wallos_cron_database($db);

require 'settimezone.php';

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
}

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

function getPricePerMonth($cycle, $frequency, $price)
{
  switch ($cycle) {
    case 1:
      $numberOfPaymentsPerMonth = (30 / $frequency);
      return $price * $numberOfPaymentsPerMonth;
    case 2:
      $numberOfPaymentsPerMonth = (4.35 / $frequency);
      return $price * $numberOfPaymentsPerMonth;
    case 3:
      $numberOfPaymentsPerMonth = (1 / $frequency);
      return $price * $numberOfPaymentsPerMonth;
    case 4:
      $numberOfMonths = (12 * $frequency);
      return $price / $numberOfMonths;
  }
}

function getPriceConverted($price, $currency, $database, $userId)
{
  return wallos_convert_price($price, $currency, $database, $userId);
}

// Get all users

$query = "SELECT id, main_currency FROM \"user\"";
$stmt = $db->prepare($query);
$result = $stmt === false ? false : $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not read the user list: ' . wallos_cron_reason($db));
}

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $userId = $row['id'];
    $userCurrencyId = $row['main_currency'];
    $totalYearlyCost = 0;

    $query = "SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $resultSubscriptions = $stmt === false ? false : $stmt->execute();

    if ($resultSubscriptions === false) {
        wallos_cron_problem('could not read the subscriptions of user ' . $userId
            . ': ' . wallos_cron_reason($db));
        continue;
    }

    while ($rowSubscriptions = $resultSubscriptions->fetchArray(SQLITE3_ASSOC)) {
        $originalSubscriptionPrice = getPriceConverted($rowSubscriptions['price'], $rowSubscriptions['currency_id'], $db, $userId);
        $price = getPricePerMonth($rowSubscriptions['cycle'], $rowSubscriptions['frequency'], $originalSubscriptionPrice) * 12;
        $totalYearlyCost += $price;
    }

    $query = "INSERT INTO total_yearly_cost (user_id, date, cost, currency) VALUES (:userId, :date, :cost, :currency)";
    $stmt = $db->prepare($query);

    if ($stmt === false) {
        wallos_cron_problem('could not prepare the yearly cost insert for user ' . $userId
            . ': ' . wallos_cron_reason($db));
        continue;
    }

    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindParam(':date', $currentDateString, SQLITE3_TEXT);
    $stmt->bindParam(':cost', $totalYearlyCost, SQLITE3_FLOAT);
    $stmt->bindParam(':currency', $userCurrencyId, SQLITE3_INTEGER);

    // The row this job exists to write. When the insert fails there is simply
    // no point on the cost graph for that week, and a graph with a gap looks
    // like a week with no subscriptions rather than a week the job could not
    // record — which is how a column declared INTEGER that had never held one
    // went unnoticed until a PostgreSQL install refused every write to it.
    if ($stmt->execute()) {
        wallos_cron_count('recorded');
        echo "Inserted total yearly cost for user " . $userId . " with cost " . $totalYearlyCost . "<br />\n";
    } else {
        wallos_cron_problem('could not store the yearly cost of user ' . $userId
            . ': ' . wallos_cron_reason($db));
        echo "Error inserting total yearly cost for user " . $userId . "<br />\n";
    }
}

wallos_cron_done();








?>