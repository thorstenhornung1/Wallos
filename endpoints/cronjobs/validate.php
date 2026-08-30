<?php

$secondsInMonth = 30 * 24 * 60 * 60;
$userId = 0;

// No session under CLI. Every cron job includes this file, and starting a
// session there wrote one orphan file per run — about 1,450 a day at the
// shipped schedule — that request-time session GC never saw, because CLI
// runs never trigger it (#85). A session identifies a browser; a cron run
// is authorised by being CLI, which the check below already encodes.
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => $secondsInMonth,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }

    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        $userId = $_SESSION['userId'];
    }

    if ($userId !== 1) {
        die("Unauthorized");
    }
}

?>