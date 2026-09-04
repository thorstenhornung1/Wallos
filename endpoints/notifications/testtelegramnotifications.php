<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$chatId = trim((string) ($data["chatid"] ?? ''));
$botToken = trim((string) ($data["bottoken"] ?? ''));

// The test resolves the exact credential production would use, so a green test
// proves the configuration the cron will send with — the instance bot token
// when the user inherits it, their own when they run a custom bot.
$defaultMode = $botToken === '' ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['mode'] ?? $defaultMode);

$config = wallos_effective_telegram_config(
    wallos_get_instance_telegram_config($db),
    [
        'bot_token_mode' => $mode,
        'bot_token' => $botToken,
        'chat_id' => $chatId,
        'enabled' => 1,
    ]
);

if (!$config['values']['deliverable']) {
    die(json_encode([
        "success" => false,
        "message" => $mode === 'instance' && $chatId !== ''
            ? translate('instance_telegram_not_configured', $i18n)
            : translate('fill_mandatory_fields', $i18n)
    ]));
}

$message = translate('test_notification', $i18n);

$effectiveToken = (string) $config['values']['bot_token'];
$effectiveChatId = (string) $config['values']['chat_id'];

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . $effectiveToken . "/sendMessage");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'chat_id' => $effectiveChatId,
    'text' => $message,
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
