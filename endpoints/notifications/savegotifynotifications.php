<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$enabled = (int) ($data["enabled"] ?? 0);
$token = trim((string) ($data["token"] ?? ''));
$url = trim((string) ($data["gotify_url"] ?? ''));
$ignoreSsl = (int) ($data["ignore_ssl"] ?? 0);

// Clients that predate the instance/custom choice always sent a host, so a
// submission carrying one still means "my own server".
$defaultMode = $url === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

// The application token is personal and required in either mode: it is never a
// shared instance value.
if ($token === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

if ($mode === 'custom') {
    if ($url === '') {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }

    $effectiveUrl = $url;
} else {
    $instanceConfig = wallos_get_instance_gotify_config($db);

    if (trim((string) $instanceConfig['values']['url']) === '') {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_gotify_not_configured', $i18n)
        ]));
    }

    $effectiveUrl = (string) $instanceConfig['values']['url'];
}

// The effective server URL is validated the same way whichever host is in use.
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

validate_webhook_url_for_ssrf($effectiveUrl, $db, $i18n, $userId);

$stmt = $db->prepare("SELECT COUNT(*) FROM gotify_notifications WHERE user_id = :userId");
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
        ? "UPDATE gotify_notifications
           SET enabled = :enabled, url_mode = :mode, url = :url, token = :token, ignore_ssl = :ignoreSsl
           WHERE user_id = :userId"
        : "INSERT INTO gotify_notifications (enabled, url_mode, url, token, ignore_ssl, user_id)
           VALUES (:enabled, :mode, :url, :token, :ignoreSsl, :userId)";
} else {
    // The application token, enable flag and ignore-ssl choice are the user's;
    // the instance host is not copied into the row, and any host the user stored
    // before is kept so switching back does not lose it.
    $query = $exists
        ? "UPDATE gotify_notifications
           SET enabled = :enabled, url_mode = :mode, token = :token, ignore_ssl = :ignoreSsl
           WHERE user_id = :userId"
        : "INSERT INTO gotify_notifications (enabled, url_mode, token, ignore_ssl, user_id)
           VALUES (:enabled, :mode, :token, :ignoreSsl, :userId)";
}

$stmt = $db->prepare($query);
$stmt->bindValue(':enabled', $enabled);
$stmt->bindValue(':mode', $mode);
$stmt->bindValue(':token', $token);
$stmt->bindValue(':ignoreSsl', $ignoreSsl);
$stmt->bindValue(':userId', (int) $userId);

if ($mode === 'custom') {
    $stmt->bindValue(':url', $url);
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
