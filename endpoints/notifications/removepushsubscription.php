<?php

/*
  Removes one device's push subscription - either by its handle (the settings
  page's per-device "remove" button, which never learns an endpoint) or by its
  endpoint (the "disable on this device" button, which only ever knows what
  the browser's own subscription object holds). Exactly one of the two is
  expected, and the account scopes either lookup, so one account can never
  remove another's device.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/webpush_helper.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$handle = isset($data['handle']) ? trim((string) $data['handle']) : '';
$endpoint = isset($data['endpoint']) ? trim((string) $data['endpoint']) : '';

if ($handle === '' && $endpoint === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

if ($handle === '') {
    $handle = webpush_device_handle($endpoint);
}

if (!webpush_delete_by_handle($db, $userId, $handle)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error_saving_notifications', $i18n)
    ]));
}

echo json_encode([
    "success" => true,
    "message" => translate('notifications_settings_saved', $i18n)
]);
