<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/webpush.php';

// The owner is always the session user, never a value from the request body.
// Removing another account's subscription is impossible for the same reason —
// the delete is scoped by user_id. Storing one is refused a layer down, in
// wallos_webpush_store_subscription(), which lets an endpoint change hands only
// when the request can show that subscription's own p256dh: the shared family
// browser can, somebody who merely learned the endpoint string cannot.
$userId = (int) $userId;

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);
if (!is_array($data)) {
    $data = [];
}

$action = (string) ($data['action'] ?? 'subscribe');

if ($action === 'unsubscribe') {
    $endpoint = trim((string) ($data['endpoint'] ?? ''));
    if ($endpoint === '') {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]);
        exit;
    }

    if (wallos_webpush_delete_by_endpoint($db, $userId, $endpoint)) {
        echo json_encode(["success" => true, "message" => translate('notifications_settings_saved', $i18n)]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
    }
    exit;
}

if ($action === 'remove_device') {
    // Removing a device from the list on the settings page, which names it by
    // handle rather than by endpoint. Scoped to this account inside
    // wallos_webpush_delete_by_handle(), so a handle from another account's
    // subscription resolves to nothing.
    $handle = trim((string) ($data['handle'] ?? ''));

    if ($handle === '' || !wallos_webpush_delete_by_handle($db, $userId, $handle)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => translate("error", $i18n)]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => translate('notifications_settings_saved', $i18n),
        "devices" => wallos_webpush_user_devices($db, $userId),
    ]);
    exit;
}

// Subscribe: the PushSubscription the browser produced. The keys are the RFC
// 8291 material used to encrypt every payload sent to this device.
$subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : $data;
$endpoint = trim((string) ($subscription['endpoint'] ?? ''));
$keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
$p256dh = trim((string) ($keys['p256dh'] ?? ($subscription['p256dh'] ?? '')));
$auth = trim((string) ($keys['auth'] ?? ($subscription['auth'] ?? '')));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]);
    exit;
}

// The URL, the key and the auth secret have a shape RFC 8291 fixes, and a row
// that does not have it is a subscription no notification can ever reach. The
// check lives in webpush.php so a test can hold it.
if (!wallos_webpush_subscription_is_wellformed($endpoint, $p256dh, $auth)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => translate("error", $i18n)]);
    exit;
}

// The user agent of this request, as the label the settings page shows. Taken
// from the header rather than the body: both are client-supplied, but the
// header is the one that describes the browser actually making the call.
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

if (wallos_webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth, $userAgent)) {
    echo json_encode([
        "success" => true,
        "message" => translate('notifications_settings_saved', $i18n),
        "devices" => wallos_webpush_user_devices($db, $userId),
    ]);
} else {
    // Either the write failed or the endpoint belongs to another account and
    // this request could not show its key. Both are reported the same way: the
    // device is not subscribed, and saying otherwise would leave somebody
    // waiting for notifications that are going elsewhere.
    http_response_code(500);
    echo json_encode(["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
}
