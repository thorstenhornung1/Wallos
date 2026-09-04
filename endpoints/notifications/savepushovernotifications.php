<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$enabled = (int) ($data["enabled"] ?? 0);
$userKey = trim((string) ($data["user_key"] ?? ''));
$token = trim((string) ($data["token"] ?? ''));

// Clients that predate the instance/custom choice always sent an application
// token, so a submission carrying one still means "my own application".
$defaultMode = $token === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

// The user key is personal and required in either mode.
if ($userKey === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

if ($mode === 'custom') {
    if ($token === '') {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }
} else {
    $instanceConfig = wallos_get_instance_pushover_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_pushover_not_configured', $i18n)
        ]));
    }
}

$stmt = $db->prepare("SELECT COUNT(*) FROM pushover_notifications WHERE user_id = :userId");
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
        ? "UPDATE pushover_notifications
           SET enabled = :enabled, token_mode = :mode, token = :token, user_key = :userKey
           WHERE user_id = :userId"
        : "INSERT INTO pushover_notifications (enabled, token_mode, token, user_key, user_id)
           VALUES (:enabled, :mode, :token, :userKey, :userId)";
} else {
    // Only the user owned values are written; a token the user stored before is
    // kept so switching back does not lose it, and the instance token is never
    // copied into the row.
    $query = $exists
        ? "UPDATE pushover_notifications
           SET enabled = :enabled, token_mode = :mode, user_key = :userKey
           WHERE user_id = :userId"
        : "INSERT INTO pushover_notifications (enabled, token_mode, user_key, user_id)
           VALUES (:enabled, :mode, :userKey, :userId)";
}

$stmt = $db->prepare($query);
$stmt->bindValue(':enabled', $enabled);
$stmt->bindValue(':mode', $mode);
$stmt->bindValue(':userKey', $userKey);
$stmt->bindValue(':userId', (int) $userId);

if ($mode === 'custom') {
    $stmt->bindValue(':token', $token);
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
