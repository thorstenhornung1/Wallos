<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$enabled = (int) ($data["enabled"] ?? 0);
$chatId = trim((string) ($data["chat_id"] ?? ''));
$botToken = trim((string) ($data["bot_token"] ?? ''));

// Clients that predate the instance/custom choice always sent a bot token, so a
// submission carrying one still means "my own bot".
$defaultMode = $botToken === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

// The chat id is personal and required in either mode: without it there is
// nowhere to deliver.
if ($chatId === '') {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ]));
}

if ($mode === 'custom') {
    if ($botToken === '') {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }
} else {
    // The instance must actually provide a bot token before a user can inherit
    // it, so a save cannot leave the user believing delivery will work.
    $instanceConfig = wallos_get_instance_telegram_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_telegram_not_configured', $i18n)
        ]));
    }
}

$stmt = $db->prepare("SELECT COUNT(*) FROM telegram_notifications WHERE user_id = :userId");
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
        ? "UPDATE telegram_notifications
           SET enabled = :enabled, bot_token_mode = :mode, bot_token = :botToken, chat_id = :chatId
           WHERE user_id = :userId"
        : "INSERT INTO telegram_notifications (enabled, bot_token_mode, bot_token, chat_id, user_id)
           VALUES (:enabled, :mode, :botToken, :chatId, :userId)";
} else {
    // Only the user owned values are written. Any bot token the user stored
    // before is kept, so switching back to a custom bot does not lose it, and
    // the instance token is never copied into the row.
    $query = $exists
        ? "UPDATE telegram_notifications
           SET enabled = :enabled, bot_token_mode = :mode, chat_id = :chatId
           WHERE user_id = :userId"
        : "INSERT INTO telegram_notifications (enabled, bot_token_mode, chat_id, user_id)
           VALUES (:enabled, :mode, :chatId, :userId)";
}

$stmt = $db->prepare($query);
$stmt->bindValue(':enabled', $enabled);
$stmt->bindValue(':mode', $mode);
$stmt->bindValue(':chatId', $chatId);
$stmt->bindValue(':userId', (int) $userId);

if ($mode === 'custom') {
    $stmt->bindValue(':botToken', $botToken);
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
