<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$token = trim((string) ($data["token"] ?? ''));
$url = trim((string) ($data["gotify_url"] ?? ''));
$ignoreSsl = (int) ($data["ignore_ssl"] ?? 0);

// The test resolves the exact host production would use: the instance server
// when the user inherits it, their own otherwise. The application token is
// always the user's own.
$defaultMode = $url === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

$config = wallos_effective_gotify_config(
    wallos_get_instance_gotify_config($db),
    [
        'url_mode' => $mode,
        'url' => $url,
        'token' => $token,
        'ignore_ssl' => $ignoreSsl,
        'enabled' => 1,
    ]
);

if (!$config['values']['deliverable']) {
    die(json_encode([
        "success" => false,
        "message" => $mode === 'instance' && $token !== ''
            ? translate('instance_gotify_not_configured', $i18n)
            : translate('fill_mandatory_fields', $i18n)
    ]));
}

$effectiveUrl = (string) $config['values']['url'];

$parsedUrl = parse_url($effectiveUrl);
if (
    !isset($parsedUrl['scheme']) ||
    !in_array(strtolower($parsedUrl['scheme']), ['http', 'https']) ||
    !filter_var($effectiveUrl, FILTER_VALIDATE_URL)
) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$ssrf = validate_webhook_url_for_ssrf($effectiveUrl, $db, $i18n, $userId);

$title = translate('wallos_notification', $i18n);
$message = translate('test_notification', $i18n);
$priority = 5;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, rtrim($effectiveUrl, '/') . "/message?token=" . (string) $config['values']['token']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'title' => $title,
    'message' => $message,
    'priority' => $priority,
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

if ($ignoreSsl) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

unset($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    die(json_encode([
        "success" => false,
        "message" => translate('notification_failed', $i18n),
        "http_code" => $httpCode
    ]));
} else {
    die(json_encode([
        "success" => true,
        "message" => translate('notification_sent_successfuly', $i18n)
    ]));
}
