<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$topic = trim((string) ($data["topic"] ?? ''));
$host = trim((string) ($data["host"] ?? ''));
$ignoreSsl = (int) ($data["ignore_ssl"] ?? 0);

// The test resolves the exact server production would use: the instance server
// (and its shared auth headers, or a per-user override) when the user inherits
// it, their own otherwise.
$defaultMode = $host === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

$config = wallos_effective_ntfy_config(
    wallos_get_instance_ntfy_config($db),
    [
        'server_mode' => $mode,
        'host' => $host,
        'topic' => $topic,
        'headers' => (string) ($data["headers"] ?? ''),
        'ignore_ssl' => $ignoreSsl,
        'enabled' => 1,
    ]
);

if (!$config['values']['deliverable']) {
    die(json_encode([
        "success" => false,
        "message" => $mode === 'instance' && $topic !== ''
            ? translate('instance_ntfy_not_configured', $i18n)
            : translate('fill_mandatory_fields', $i18n)
    ]));
}

$effectiveHost = rtrim((string) $config['values']['host'], '/');
$url = $effectiveHost . '/' . ltrim((string) $config['values']['topic'], '/');

$parsedUrl = parse_url($url);
if (
    !isset($parsedUrl['scheme']) ||
    !in_array(strtolower($parsedUrl['scheme']), ['http', 'https']) ||
    !filter_var($url, FILTER_VALIDATE_URL)
) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$ssrf = validate_webhook_url_for_ssrf($url, $db, $i18n, $userId);

$decodedHeaders = json_decode((string) $config['values']['headers'], true);
$customheaders = [];
if (is_array($decodedHeaders)) {
    $customheaders = array_map(function ($key, $value) {
        return "$key: $value";
    }, array_keys($decodedHeaders), $decodedHeaders);
}

$message = translate('test_notification', $i18n);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

if ($ignoreSsl) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

$response = curl_exec($ch);

unset($ch);

if ($response === false) {
    die(json_encode([
        "success" => false,
        "message" => translate('notification_failed', $i18n)
    ]));
}

die(json_encode([
    "success" => true,
    "message" => translate('notification_sent_successfuly', $i18n)
]));
