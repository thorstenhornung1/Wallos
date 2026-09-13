<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_session.php';
require_once '../../includes/webpush.php';

// A read, so the session guard rather than the POST + CSRF guard — the same
// choice getwebpushkey.php makes, and for the same reason. It runs before
// anything can produce output.
header('Content-Type: application/json');

// The devices this account has subscribed, so the settings page can show what
// is registered and offer to remove it. Until now the page could only say "this
// device on / off", which left a phone somebody no longer owns receiving the
// household's renewal reminders with nothing on any screen to say so.
//
// wallos_webpush_user_devices() returns a handle, a browser and platform name
// chosen server-side from a fixed list, and a date. The endpoint and the key
// material stay where they are.
$userId = (int) $userId;

echo json_encode([
    "success" => true,
    "devices" => wallos_webpush_user_devices($db, $userId),
    "limit" => WALLOS_WEBPUSH_MAX_DEVICES,
]);
