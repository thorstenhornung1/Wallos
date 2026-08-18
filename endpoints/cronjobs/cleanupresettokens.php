<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

// The same one-hour window passwordreset.php enforces, computed here because
// SQLite's datetime('now', '-1 hour') has no PostgreSQL spelling. gmdate()
// rather than date() because created_at is written by the column's
// CURRENT_TIMESTAMP default, which is UTC whatever timezone PHP is set to.
$stmt = $db->prepare("DELETE FROM password_resets WHERE created_at <= :expiredBefore");
$stmt->bindValue(':expiredBefore', gmdate('Y-m-d H:i:s', time() - 3600));
$deleted = $stmt->execute();

if ($deleted) {
    echo "Expired password reset tokens cleaned up successfully.\n";
} else {
    echo "No expired password reset tokens to clean up.\n";
}

$db->close();
?>