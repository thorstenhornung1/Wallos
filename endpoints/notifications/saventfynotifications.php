<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$enabled = (int) ($data["enabled"] ?? 0);
$topic = trim((string) ($data["topic"] ?? ''));
$host = trim((string) ($data["host"] ?? ''));
$headers = (string) ($data["headers"] ?? '');
$ignoreSsl = (int) ($data["ignore_ssl"] ?? 0);

// Clients that predate the instance/custom choice always sent a host, so a
// submission carrying one still means "my own server".
$defaultMode = $host === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

// The topic is personal and required in either mode.
if ($topic === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

if ($mode === 'custom') {
    if ($host === '') {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }

    $effectiveHost = $host;
} else {
    $instanceConfig = wallos_get_instance_ntfy_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_ntfy_not_configured', $i18n)
        ]));
    }

    $effectiveHost = (string) $instanceConfig['values']['host'];
}

// The delivery URL is the effective host plus the personal topic, validated the
// same way whichever server is in use.
$url = rtrim($effectiveHost, '/') . '/' . ltrim($topic, '/');
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

validate_webhook_url_for_ssrf($url, $db, $i18n, $userId);

$stmt = $db->prepare("SELECT COUNT(*) FROM ntfy_notifications WHERE user_id = :userId");
$stmt->bindValue(":userId", (int) $userId);
$result = $stmt->execute();

if ($result === false) {
    die(json_encode([
        "success" => false,
        "message" => translate('error_saving_notifications', $i18n)
    ]));
}

$row = $result->fetchArray();
$exists = $row[0] > 0;

if ($mode === 'custom') {
    $query = $exists
        ? "UPDATE ntfy_notifications
           SET enabled = :enabled, server_mode = :mode, host = :host, topic = :topic,
               headers = :headers, ignore_ssl = :ignoreSsl
           WHERE user_id = :userId"
        : "INSERT INTO ntfy_notifications (enabled, server_mode, host, topic, headers, ignore_ssl, user_id)
           VALUES (:enabled, :mode, :host, :topic, :headers, :ignoreSsl, :userId)";
} else {
    // Only the user owned values are written: the topic, the ignore-ssl choice,
    // and the optional header override. The instance server is not copied into
    // the row, and any host the user stored before is kept so switching back
    // does not lose it.
    $query = $exists
        ? "UPDATE ntfy_notifications
           SET enabled = :enabled, server_mode = :mode, topic = :topic,
               headers = :headers, ignore_ssl = :ignoreSsl
           WHERE user_id = :userId"
        : "INSERT INTO ntfy_notifications (enabled, server_mode, topic, headers, ignore_ssl, user_id)
           VALUES (:enabled, :mode, :topic, :headers, :ignoreSsl, :userId)";
}

$stmt = $db->prepare($query);
$stmt->bindValue(':enabled', $enabled);
$stmt->bindValue(':mode', $mode);
$stmt->bindValue(':topic', $topic);
$stmt->bindValue(':headers', $headers);
$stmt->bindValue(':ignoreSsl', $ignoreSsl);
$stmt->bindValue(':userId', (int) $userId);

if ($mode === 'custom') {
    $stmt->bindValue(':host', $host);
}

if ($stmt->execute()) {
    wallos_reset_config_cache($db);

    echo json_encode([
        "success" => true,
        "message" => translate('notifications_settings_saved', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error_saving_notifications', $i18n)
    ]);
}
