<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$aiEnabled    = isset($data['ai_enabled']) ? (bool) $data['ai_enabled'] : false;
$aiType       = isset($data['ai_type']) ? trim($data['ai_type']) : '';
$aiApiKey     = isset($data['api_key']) ? trim($data['api_key']) : '';
$aiOllamaHost = isset($data['ollama_host']) ? trim($data['ollama_host']) : '';
$aiModel      = isset($data['model']) ? trim($data['model']) : '';
$aiSchedule   = isset($data['ai_run_schedule']) ? $data['ai_run_schedule'] : "manual";

// Clients that predate the instance/custom choice keep configuring their own provider.
$mode = wallos_normalize_mode($data['provider_mode'] ?? 'custom');

$rowStmt = $db->prepare("SELECT COUNT(*) AS count FROM ai_settings WHERE user_id = :userId");
$rowStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$rowResult = $rowStmt->execute();
$rowCount = $rowResult ? $rowResult->fetchArray(SQLITE3_ASSOC) : false;
$rowExists = $rowCount && $rowCount['count'] > 0;

if ($mode === 'instance') {
    $instanceConfig = wallos_get_instance_ai_config($db);

    if (!$instanceConfig['valid']) {
        echo json_encode(["success" => false, "message" => translate('instance_ai_provider_not_configured', $i18n)]);
        exit;
    }

    // The model is the only instance value a user may override; an empty value
    // means "use the instance model". Any previously stored custom provider is
    // left in place so switching back does not lose it.
    $query = $rowExists
        ? "UPDATE ai_settings
           SET enabled = :enabled, model = :model, run_schedule = :run_schedule, provider_mode = 'instance'
           WHERE user_id = :user_id"
        : "INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule, provider_mode)
           VALUES (:user_id, '', :enabled, '', :model, '', :run_schedule, 'instance')";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':enabled', $aiEnabled, SQLITE3_INTEGER);
    $stmt->bindValue(':model', $aiModel, SQLITE3_TEXT);
    $stmt->bindValue(':run_schedule', $aiSchedule, SQLITE3_TEXT);

    echo json_encode($stmt->execute()
        ? ["success" => true, "message" => translate('success', $i18n), "enabled" => $aiEnabled]
        : ["success" => false, "message" => translate('error', $i18n)]);
    exit;
}

// Validate type
if (empty($aiType) || !in_array($aiType, WALLOS_AI_PROVIDERS)) {
    echo json_encode(["success" => false, "message" => translate('error', $i18n)]);
    exit;
}

// API key required only for these
if (!in_array($aiType, WALLOS_AI_HOST_PROVIDERS) && empty($aiApiKey)) {
    echo json_encode(["success" => false, "message" => translate('invalid_api_key', $i18n)]);
    exit;
}

// URL required when using a host
if (in_array($aiType, WALLOS_AI_HOST_PROVIDERS) && empty($aiOllamaHost)) {
    echo json_encode(["success" => false, "message" => translate('invalid_host', $i18n)]);
    exit;
}

// Model is always required
if (empty($aiModel)) {
    echo json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]);
    exit;
}

// URL validation + SSRF for host-based providers
if (in_array($aiType, WALLOS_AI_HOST_PROVIDERS)) {
    $parsedUrl = parse_url($aiOllamaHost);
    if (
        !isset($parsedUrl['scheme']) ||
        !in_array(strtolower($parsedUrl['scheme']), ['http', 'https']) ||
        !filter_var($aiOllamaHost, FILTER_VALIDATE_URL)
    ) {
        echo json_encode(["success" => false, "message" => translate('invalid_host', $i18n)]);
        exit;
    }

    validate_webhook_url_for_ssrf($aiOllamaHost, $db, $i18n, $userId);

    if ($aiType === 'ollama') {
        // Ollama never uses an API key
        $aiApiKey = '';
    }
} else {
    // Non-host providers do not store a URL
    $aiOllamaHost = '';
}

// Save settings
$stmt = $db->prepare("DELETE FROM ai_settings WHERE user_id = ?");
$stmt->bindValue(1, $userId, SQLITE3_INTEGER);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare("
    INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule, provider_mode)
    VALUES (:user_id, :type, :enabled, :api_key, :model, :url, :run_schedule, 'custom')
");
$stmt->bindValue(':user_id',  $userId,      SQLITE3_INTEGER);
$stmt->bindValue(':type',     $aiType,      SQLITE3_TEXT);
$stmt->bindValue(':enabled',  $aiEnabled,   SQLITE3_INTEGER);
$stmt->bindValue(':api_key',  $aiApiKey,    SQLITE3_TEXT);
$stmt->bindValue(':model',    $aiModel,     SQLITE3_TEXT);
$stmt->bindValue(':url',      $aiOllamaHost,SQLITE3_TEXT);
$stmt->bindValue(':run_schedule', $aiSchedule, SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {
    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "enabled" => $aiEnabled,
    ];
} else {
    $response = [
        "success" => false,
        "message" => translate('error', $i18n),
    ];
}

echo json_encode($response);
