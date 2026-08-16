<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_provider.php';

$newApiKey = isset($_POST["api_key"]) ? trim($_POST["api_key"]) : "";
$provider = isset($_POST["provider"]) ? $_POST["provider"] : 0;

// Clients that predate the instance/custom choice keep their own credentials.
$defaultMode = $newApiKey === "" ? 'instance' : 'custom';
$mode = wallos_normalize_mode($_POST['mode'] ?? $defaultMode);

$stmt = $db->prepare("SELECT COUNT(*) AS count FROM fixer WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
$rowExists = $row && $row['count'] > 0;

if ($mode === 'instance') {
    $instanceConfig = wallos_get_instance_currency_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_currency_provider_not_configured', $i18n)
        ]));
    }

    // The stored key is kept untouched so switching back does not lose it.
    $query = $rowExists
        ? "UPDATE fixer SET provider_mode = 'instance' WHERE user_id = :userId"
        : "INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES ('', 0, 'instance', :userId)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    if ($stmt->execute()) {
        die(json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]));
    }

    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

if ($newApiKey === "") {
    // Submitting an empty key removes the stored credential, as before.
    $stmt = $db->prepare("DELETE FROM fixer WHERE user_id = :userId");
    $stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);
    $stmt->execute();

    die(json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]));
}

$config = wallos_currency_config_from_input($provider, $newApiKey);

if (!$config['valid']) {
    die(json_encode([
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ]));
}

// Verified with the same client the scheduled updates use.
$test = wallos_fetch_exchange_rates($config, 'USD');

if (!$test['success']) {
    die(json_encode([
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ]));
}

$removeOldKey = "DELETE FROM fixer WHERE user_id = :userId";
$stmt = $db->prepare($removeOldKey);
$stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);
$stmt->execute();

$insertNewKey = "INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES (:api_key, :provider, 'custom', :userId)";
$stmt = $db->prepare($insertNewKey);
$stmt->bindValue(":api_key", $config['values']['api_key'], SQLITE3_TEXT);
$stmt->bindValue(":provider", $config['values']['provider'], SQLITE3_INTEGER);
$stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);

if (!$stmt->execute()) {
    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

wallos_reset_config_cache($db);
wallos_store_currency_usage($db, $config, $userId, $test['usage']);

echo json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]);
