<?php

$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage == 'index.php') {
    // Redirect to subscriptions page if no subscriptions exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :userId");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_NUM);
    $subscriptionCount = $row[0];

    if ($subscriptionCount === 0) {
        header('Location: subscriptions.php');
        exit;
    }
}

// The one-off name migration page removes itself as soon as it has nothing to
// do — an account with no default names left is sent to the dashboard rather
// than shown an empty list, which is also what makes the reload after a
// successful run leave the page behind for good.
//
// It belongs here rather than in localize.php because header.php starts
// printing the document before that file gets control, and a Location header
// after the first byte of output is ignored ("headers already sent").
// Removed together with localize.php.
if ($currentPage == 'localize.php') {
    require_once __DIR__ . '/user_provisioning.php';

    $localizeLanguage = wallos_resolve_language(
        $db->scalar('SELECT language FROM "user" WHERE id = :userId', [':userId' => $userId]));

    if (wallos_default_name_localization_candidates($db, $userId, $localizeLanguage) === []) {
        header('Location: index.php');
        exit;
    }
}
