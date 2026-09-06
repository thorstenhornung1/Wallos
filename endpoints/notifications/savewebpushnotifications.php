<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/webpush.php';

// The owner is always the session user, never a value from the request body, so
// one account can never store or remove another's subscription.
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

// A push endpoint is always an absolute https (or http) URL. Reserved/private
// addresses are not refused here — the outbound send routes every endpoint
// through the SSRF allowlist — but a value that is not even a URL is rejected.
$parsedUrl = parse_url($endpoint);
if (
    !is_array($parsedUrl) ||
    !isset($parsedUrl['scheme']) ||
    !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true) ||
    !filter_var($endpoint, FILTER_VALIDATE_URL)
) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => translate("error", $i18n)]);
    exit;
}

if (wallos_webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth)) {
    echo json_encode(["success" => true, "message" => translate('notifications_settings_saved', $i18n)]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
}
