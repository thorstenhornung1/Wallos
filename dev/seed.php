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

require_once __DIR__ . '/../includes/database/connection.php';
$db = wallos_database_connect();
$db->busyTimeout(5000);

$seedPrefix = 'seed-';

echo "Removing previously seeded data\n";
// Order matters: the subscriptions reference the household members and the
// categories, and "user".main_currency references the currencies, so the rows
// doing the referencing go first and the currencies go last.
$db->exec("DELETE FROM subscriptions WHERE name LIKE '" . $seedPrefix . "%'");
$db->exec("DELETE FROM household WHERE name LIKE '" . $seedPrefix . "%'");
$db->exec("DELETE FROM categories WHERE name LIKE '" . $seedPrefix . "%'");
$db->exec("DELETE FROM \"user\" WHERE username LIKE '" . $seedPrefix . "%'");
$db->exec("DELETE FROM currencies WHERE name LIKE '" . $seedPrefix . "%'");

$started = microtime(true);
$db->exec('BEGIN');

// Every id a seeded subscription carries has to belong to the seeded account
// that owns it. payer_user_id in particular references household(id), not
// user(id), and this script used to bind a user id into it — the exact
// confusion the column's name invites, done by the repository to itself
// (issue #82). Pointing the currency and the category at row 1 was the same
// mistake more quietly: those rows belong to the real account.
//
// The account is created against an existing currency and moved onto its own
// immediately after, because currencies.user_id points at the account while
// "user".main_currency points back at the currency.
$bootstrapCurrency = (int) $db->querySingle('SELECT id FROM currencies ORDER BY id LIMIT 1');

$insertUser = $db->prepare("INSERT INTO \"user\" (username, email, password, main_currency) VALUES (:username, :email, 'seeded', :currency)");
$insertCurrency = $db->prepare('INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES (:name, :symbol, :code, :rate, :userId)');
$setMainCurrency = $db->prepare('UPDATE "user" SET main_currency = :currency WHERE id = :userId');
$insertMember = $db->prepare('INSERT INTO household (name, email, user_id) VALUES (:name, :email, :userId)');
$insertCategory = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, 1, :userId)');
$insertSubscription = $db->prepare('INSERT INTO subscriptions
    (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, notify, inactive, user_id, auto_renew)
    VALUES (:name, :price, :currency, :next, 3, 1, :payer, :category, :notify, 0, :userId, 1)');

$subscriptionTotal = 0;

for ($u = 1; $u <= $userCount; $u++) {
    $username = $seedPrefix . 'user' . $u;
    $insertUser->bindValue(':username', $username);
    $insertUser->bindValue(':email', $username . '@example.com');
    $insertUser->bindValue(':currency', $bootstrapCurrency);
    $insertUser->execute();
    $insertUser->reset();

    $userId = $db->lastInsertRowID();

    $insertCurrency->bindValue(':name', $seedPrefix . 'currency-' . $u);
    $insertCurrency->bindValue(':symbol', '$');
    $insertCurrency->bindValue(':code', 'USD');
    $insertCurrency->bindValue(':rate', 1.0);
    $insertCurrency->bindValue(':userId', $userId);
    $insertCurrency->execute();
    $insertCurrency->reset();

    $currencyId = $db->lastInsertRowID();

    $setMainCurrency->bindValue(':currency', $currencyId);
    $setMainCurrency->bindValue(':userId', $userId);
    $setMainCurrency->execute();
    $setMainCurrency->reset();

    $insertMember->bindValue(':name', $seedPrefix . 'member-' . $u);
    $insertMember->bindValue(':email', $username . '@example.com');
    $insertMember->bindValue(':userId', $userId);
    $insertMember->execute();
    $insertMember->reset();

    $payerId = $db->lastInsertRowID();

    $insertCategory->bindValue(':name', $seedPrefix . 'category-' . $u);
    $insertCategory->bindValue(':userId', $userId);
    $insertCategory->execute();
    $insertCategory->reset();

    $categoryId = $db->lastInsertRowID();

    for ($s = 0; $s < $perUser; $s++) {
        $insertSubscription->bindValue(':name', $seedPrefix . 'sub-' . $u . '-' . $s, SQLITE3_TEXT);
        $insertSubscription->bindValue(':price', 4.99 + ($s % 20), SQLITE3_FLOAT);
        $insertSubscription->bindValue(':currency', $currencyId, SQLITE3_INTEGER);
        $insertSubscription->bindValue(':next', date('Y-m-d', strtotime('+' . ($s % 45) . ' days')), SQLITE3_TEXT);
        $insertSubscription->bindValue(':payer', $payerId, SQLITE3_INTEGER);
        $insertSubscription->bindValue(':category', $categoryId);
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
