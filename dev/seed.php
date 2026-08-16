<?php
/*
  Seeds a running development database with representative data, so query
  counts and page timings can be measured against realistic volumes.

      podman exec wallos-dev php /var/www/html/dev/seed.php 10 100

  Arguments: number of users, subscriptions per user. Existing seeded rows are
  removed first; real accounts (id 1) are left alone.
*/

if (php_sapi_name() !== 'cli') {
    die("This script is meant to be run from the command line.\n");
}

$userCount = isset($argv[1]) ? max(1, (int) $argv[1]) : 10;
$perUser = isset($argv[2]) ? max(1, (int) $argv[2]) : 100;

$db = new SQLite3(__DIR__ . '/../db/wallos.db');
$db->busyTimeout(5000);

$seedPrefix = 'seed-';

echo "Removing previously seeded data\n";
$db->exec("DELETE FROM subscriptions WHERE name LIKE '" . $seedPrefix . "%'");
$db->exec("DELETE FROM user WHERE username LIKE '" . $seedPrefix . "%'");

$started = microtime(true);
$db->exec('BEGIN');

$mainCurrency = (int) $db->querySingle('SELECT id FROM currencies ORDER BY id LIMIT 1');

$insertUser = $db->prepare("INSERT INTO user (username, email, password, main_currency) VALUES (:username, :email, 'seeded', :currency)");
$insertSubscription = $db->prepare('INSERT INTO subscriptions
    (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, notify, inactive, user_id, auto_renew)
    VALUES (:name, :price, :currency, :next, 3, 1, :payer, 1, :notify, 0, :userId, 1)');

$subscriptionTotal = 0;

for ($u = 1; $u <= $userCount; $u++) {
    $username = $seedPrefix . 'user' . $u;
    $insertUser->bindValue(':username', $username, SQLITE3_TEXT);
    $insertUser->bindValue(':email', $username . '@example.com', SQLITE3_TEXT);
    $insertUser->bindValue(':currency', $mainCurrency, SQLITE3_INTEGER);
    $insertUser->execute();
    $insertUser->reset();

    $userId = $db->lastInsertRowID();

    for ($s = 0; $s < $perUser; $s++) {
        $insertSubscription->bindValue(':name', $seedPrefix . 'sub-' . $u . '-' . $s, SQLITE3_TEXT);
        $insertSubscription->bindValue(':price', 4.99 + ($s % 20), SQLITE3_FLOAT);
        $insertSubscription->bindValue(':currency', $mainCurrency, SQLITE3_INTEGER);
        $insertSubscription->bindValue(':next', date('Y-m-d', strtotime('+' . ($s % 45) . ' days')), SQLITE3_TEXT);
        $insertSubscription->bindValue(':payer', $userId, SQLITE3_INTEGER);
        $insertSubscription->bindValue(':notify', $s % 5 === 0 ? 1 : 0, SQLITE3_INTEGER);
        $insertSubscription->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $insertSubscription->execute();
        $insertSubscription->reset();
        $subscriptionTotal++;
    }
}

$db->exec('COMMIT');

printf(
    "Seeded %d users and %d subscriptions in %.1fs\n",
    $userCount,
    $subscriptionTotal,
    microtime(true) - $started
);

$db->close();
