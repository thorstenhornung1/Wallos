<?php
function errorHandler($severity, $message, $file, $line)
{
    throw new ErrorException($message, 0, $severity, $file, $line);
}

// Set the custom error handler
set_error_handler('errorHandler');
/** @var \SQLite3 $db */
try {
    require_once 'includes/connect_endpoint_crontabs.php';
} catch (Exception $e) {
    require_once '../../includes/connect_endpoint.php';
} finally {
    // Restore the default error handler
    restore_error_handler();
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../includes/user_roles.php';
    if (!wallos_user_is_admin($db, $userId)) {
        http_response_code(403);
        die("Forbidden");
    }
}

require_once __DIR__ . '/../../includes/run_migrations.php';

// This endpoint exists to run migrations and nothing else, so its status code
// is the whole answer. Ending here regardless left it saying 200 for a run that
// stopped halfway (issue #103) — and the caller most likely to be listening is
// a cron job or a deployment script, neither of which reads prose.
if ($migrationFailure !== null) {
    http_response_code(500);
    echo 'Migration failed: ' . basename((string) $migrationFailure) . PHP_EOL;
}
