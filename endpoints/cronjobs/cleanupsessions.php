<?php

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('cleanupsessions');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
wallos_cron_database($db);

// The remember-me cookie lives 30 days (login.php sets it so), and it is the
// only way back into a login_tokens row — a row older than that answers no
// client that can still present it. The same boundary bounds oidc_sessions,
// which since #123 carries the id_token beside the login token: a session
// that dies by PHP garbage collection instead of an explicit logout used to
// leave both at rest indefinitely. Bounded retention was a condition of the
// #123 security review.
//
// gmdate() rather than date(), because both timestamp columns are written by
// CURRENT_TIMESTAMP defaults, which are UTC whatever timezone PHP is set to.
$cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$removed = 0;

foreach (['login_tokens' => 'timestamp', 'oidc_sessions' => 'created_at'] as $table => $column) {
    $stmt = $db->prepare('DELETE FROM "' . $table . '" WHERE "' . $column . '" <= :cutoff');

    if ($stmt === false) {
        wallos_cron_fail('could not prepare the ' . $table . ' cleanup: ' . wallos_cron_reason($db));
    }

    $stmt->bindValue(':cutoff', $cutoff);

    // A DELETE that matched nothing still answers a result; false means the
    // statement itself failed — and a table that keeps every session ever
    // opened is not a quiet day, it is credentials accumulating.
    if ($stmt->execute() === false) {
        wallos_cron_fail('the ' . $table . ' cleanup failed: ' . wallos_cron_reason($db));
    }

    $deleted = $db->changes();
    $removed += $deleted;
    wallos_cron_count($table, $deleted);

    echo "Removed " . $deleted . " expired row(s) from " . $table . ".\n";
}

wallos_cron_done($removed === 0 ? 'nothing older than the cookie' : $removed . ' expired row(s) removed');

$db->close();
?>
