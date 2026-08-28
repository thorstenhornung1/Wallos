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

// The runner narrates as it works, and with output_buffering=0 — the shipped
// image's setting — its first echo sends the headers, after which the status
// line below is a warning and a failed run answers 200 anyway (#116).
// Buffered, the status still belongs to this endpoint when the answer is
// known; import.php holds its restore output the same way.
ob_start();
require_once __DIR__ . '/../../includes/run_migrations.php';

if ($migrationFailure !== null) {
    http_response_code(500);
}

ob_end_flush();

// This endpoint exists to run migrations and nothing else, so its status code
// is the whole answer. Ending here regardless left it saying 200 for a run that
// stopped halfway (issue #103) — and the caller most likely to be listening is
// a cron job or a deployment script, neither of which reads prose. On the
// command line that caller watches the exit code.
if ($migrationFailure !== null) {
    echo 'Migration failed: ' . basename((string) $migrationFailure) . PHP_EOL;

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
