<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$currencyConfiguration = wallos_get_instance_currency_config($db);
$aiConfiguration = wallos_get_instance_ai_config($db);
$telegramConfiguration = wallos_get_instance_telegram_config($db);
$pushoverConfiguration = wallos_get_instance_pushover_config($db);
$ntfyConfiguration = wallos_get_instance_ntfy_config($db);

$currencyProvider = trim((string) ($data['currency_provider'] ?? ''));
$currencyApiKey = trim((string) ($data['currency_api_key'] ?? ''));
$aiProvider = strtolower(trim((string) ($data['ai_provider'] ?? '')));
$aiBaseUrl = trim((string) ($data['ai_base_url'] ?? ''));
$aiModel = trim((string) ($data['ai_model'] ?? ''));
$aiApiKey = trim((string) ($data['ai_api_key'] ?? ''));
$telegramBotToken = trim((string) ($data['telegram_bot_token'] ?? ''));
$pushoverAppToken = trim((string) ($data['pushover_app_token'] ?? ''));
$ntfyBaseUrl = trim((string) ($data['ntfy_base_url'] ?? ''));
$ntfyHeaders = trim((string) ($data['ntfy_headers'] ?? ''));

if ($currencyProvider !== '' && wallos_parse_currency_provider($currencyProvider) === null) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

if ($aiProvider !== '' && !in_array($aiProvider, WALLOS_AI_PROVIDERS, true)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

// Self-hosted AI endpoints stay subject to the same SSRF validation as every
// other host based integration.
if ($aiBaseUrl !== '' && empty($aiConfiguration['managed']['url'])) {
    $parsedUrl = parse_url($aiBaseUrl);
    if (
        !isset($parsedUrl['scheme']) ||
        !in_array(strtolower($parsedUrl['scheme']), ['http', 'https']) ||
        !filter_var($aiBaseUrl, FILTER_VALIDATE_URL)
    ) {
        die(json_encode([
            "success" => false,
            "message" => translate('invalid_host', $i18n)
        ]));
    }

    validate_webhook_url_for_ssrf($aiBaseUrl, $db, $i18n, $userId);
}

// The ntfy server URL is validated for scheme here; the actual delivery URL
// (server plus a user's topic) is SSRF-checked where the request is made — the
// per-user save and the notification cron.
if ($ntfyBaseUrl !== '' && empty($ntfyConfiguration['managed']['host'])) {
    $parsedNtfyUrl = parse_url($ntfyBaseUrl);
    if (
        !isset($parsedNtfyUrl['scheme']) ||
        !in_array(strtolower($parsedNtfyUrl['scheme']), ['http', 'https']) ||
        !filter_var($ntfyBaseUrl, FILTER_VALIDATE_URL)
    ) {
        die(json_encode([
            "success" => false,
            "message" => translate('invalid_host', $i18n)
        ]));
    }
}

// Environment managed values are never persisted.
if (empty($currencyConfiguration['managed']['provider'])) {
    wallos_set_instance_setting($db, 'currency', 'provider', $currencyProvider);
}

// An empty secret field means "keep the stored value"; removing a secret is an
// explicit action, so an unchanged form never destroys a credential.
if (empty($currencyConfiguration['managed']['api_key'])) {
    if (!empty($data['currency_api_key_remove'])) {
        wallos_set_instance_setting($db, 'currency', 'api_key', '', true);
    } elseif ($currencyApiKey !== '') {
        wallos_set_instance_setting($db, 'currency', 'api_key', $currencyApiKey, true);
    }
}

if (empty($aiConfiguration['managed']['type'])) {
    wallos_set_instance_setting($db, 'ai', 'provider', $aiProvider);
}

if (empty($aiConfiguration['managed']['url'])) {
    wallos_set_instance_setting($db, 'ai', 'base_url', $aiBaseUrl);
}

if (empty($aiConfiguration['managed']['model'])) {
    wallos_set_instance_setting($db, 'ai', 'model', $aiModel);
}

if (empty($aiConfiguration['managed']['api_key'])) {
    if (!empty($data['ai_api_key_remove'])) {
        wallos_set_instance_setting($db, 'ai', 'api_key', '', true);
    } elseif ($aiApiKey !== '') {
        wallos_set_instance_setting($db, 'ai', 'api_key', $aiApiKey, true);
    }
}

// The Telegram bot token is a shared credential; an empty field keeps the
// stored value, and removing it is an explicit action.
if (empty($telegramConfiguration['managed']['bot_token'])) {
    if (!empty($data['telegram_bot_token_remove'])) {
        wallos_set_instance_setting($db, 'telegram', 'bot_token', '', true);
    } elseif ($telegramBotToken !== '') {
        wallos_set_instance_setting($db, 'telegram', 'bot_token', $telegramBotToken, true);
    }
}

// The Pushover application token is a shared credential; an empty field keeps
// the stored value, and removing it is an explicit action.
if (empty($pushoverConfiguration['managed']['token'])) {
    if (!empty($data['pushover_app_token_remove'])) {
        wallos_set_instance_setting($db, 'pushover', 'app_token', '', true);
    } elseif ($pushoverAppToken !== '') {
        wallos_set_instance_setting($db, 'pushover', 'app_token', $pushoverAppToken, true);
    }
}

// The ntfy server URL is not a secret and is stored as given. The shared auth
// headers are a secret: an empty field keeps the stored value, removing them is
// explicit.
if (empty($ntfyConfiguration['managed']['host'])) {
    wallos_set_instance_setting($db, 'ntfy', 'base_url', $ntfyBaseUrl);
}

if (empty($ntfyConfiguration['managed']['headers'])) {
    if (!empty($data['ntfy_headers_remove'])) {
        wallos_set_instance_setting($db, 'ntfy', 'headers', '', true);
    } elseif ($ntfyHeaders !== '') {
        wallos_set_instance_setting($db, 'ntfy', 'headers', $ntfyHeaders, true);
    }
}

echo json_encode([
    "success" => true,
    "message" => translate('success', $i18n)
]);
