<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userKey = trim((string) ($data["user_key"] ?? ''));
$token = trim((string) ($data["token"] ?? ''));

// The test resolves the exact credential production would use: the instance
// application token when the user inherits it, their own otherwise.
$defaultMode = $token === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

$config = wallos_effective_pushover_config(
    wallos_get_instance_pushover_config($db),
    [
        'token_mode' => $mode,
        'token' => $token,
        'user_key' => $userKey,
        'enabled' => 1,
    ]
);

if (!$config['values']['deliverable']) {
    die(json_encode([
        "success" => false,
        "message" => $mode === 'instance' && $userKey !== ''
            ? translate('instance_pushover_not_configured', $i18n)
            : translate('fill_mandatory_fields', $i18n)
    ]));
}

$message = translate('test_notification', $i18n);

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, "https://api.pushover.net/1/messages.json");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'token' => (string) $config['values']['token'],
    'user' => (string) $config['values']['user_key'],
    'message' => $message,
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);

unset($ch);

if ($response === false) {
    die(json_encode([
        "success" => false,
        "message" => translate('notification_failed', $i18n)
    ]));
} else {
    die(json_encode([
        "success" => true,
        "message" => translate('notification_sent_successfuly', $i18n)
    ]));
}
