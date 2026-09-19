<?php

/*
  Saves one device's push subscription - called by the frontend right after
  a successful pushManager.subscribe(), not by anything the user fills in
  themselves. That still makes the endpoint URL something to validate: it is
  attacker-influenceable through this very request body (see #1220's fix to
  this same class of problem elsewhere in this codebase), even though a
  legitimate one only ever comes from a real push service and is always
  https. Both are checked before anything is stored, the same way every
  other channel's webhook-shaped URL already is.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/webpush_helper.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$endpoint = isset($data['endpoint']) ? trim((string) $data['endpoint']) : '';
$p256dh = isset($data['keys']['p256dh']) ? trim((string) $data['keys']['p256dh']) : '';
$auth = isset($data['keys']['auth']) ? trim((string) $data['keys']['auth']) : '';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

$parsedEndpoint = parse_url($endpoint);
if (
    !$parsedEndpoint ||
    !isset($parsedEndpoint['scheme'], $parsedEndpoint['host']) ||
    strtolower($parsedEndpoint['scheme']) !== 'https'
) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

// A real subscription's keys are always exactly this shape - 65 raw bytes
// for an uncompressed P-256 point, 16 for the auth secret, and an endpoint
// short enough to be a URL a push service issued. Anything else cannot come
// from a real browser subscription and could only ever fail every future
// send, so it is refused here rather than stored to fail later.
if (!webpush_subscription_is_wellformed($endpoint, $p256dh, $auth)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

validate_webhook_url_for_ssrf($endpoint, $db, $i18n, $userId);

// Storing is shared with the rest of the channel: it is what decides whether
// this account may claim an endpoint another one already holds, and it is what
// keeps the device list from growing without bound.
if (!webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth, $userAgent)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error_saving_notifications', $i18n)
    ]));
}

// Handed back so the settings page can add or refresh this device's row
// itself, rather than reloading the whole page to pick it up. The handle
// rather than the row id, and words this server chose rather than the user
// agent the browser sent: the endpoint is the address that receives this
// account's notifications, and a crafted user agent has no business putting
// its own text on a settings page.
$label = webpush_device_label($userAgent);

echo json_encode([
    "success" => true,
    "message" => translate('notifications_settings_saved', $i18n),
    "subscription" => [
        "handle" => webpush_device_handle($endpoint),
        "browser" => $label['browser'],
        "platform" => $label['platform'],
        "created_at" => date('Y-m-d H:i:s'),
    ],
]);
