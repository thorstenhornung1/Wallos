<?php

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('cleanupresettokens');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
wallos_cron_database($db);

// The same one-hour window passwordreset.php enforces, computed here because
// SQLite's datetime('now', '-1 hour') has no PostgreSQL spelling. gmdate()
// rather than date() because created_at is written by the column's
// CURRENT_TIMESTAMP default, which is UTC whatever timezone PHP is set to.
$stmt = $db->prepare("DELETE FROM password_resets WHERE created_at <= :expiredBefore");

if ($stmt === false) {
    wallos_cron_fail('could not prepare the token cleanup: ' . wallos_cron_reason($db));
}

$stmt->bindValue(':expiredBefore', gmdate('Y-m-d H:i:s', time() - 3600));

// execute() answers a result for a DELETE that matched nothing, and false only
// when the statement itself failed. Reading it as "were there any" therefore
// printed "no expired password reset tokens to clean up" in exactly the case
// where the cleanup had broken — and a table that keeps every reset token ever
// issued is not a quiet day, it is an authentication problem accumulating.
if ($stmt->execute() === false) {
    wallos_cron_fail('the token cleanup failed: ' . wallos_cron_reason($db));
}

$deleted = $db->changes();
wallos_cron_count('deleted', $deleted);
wallos_cron_done($deleted === 0 ? 'no expired tokens' : $deleted . ' expired token(s) removed');

echo "Removed " . $deleted . " expired password reset token(s).\n";

$db->close();
?>