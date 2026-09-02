<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

if (!isset($data['local_webhook_notifications_allowlist'])) {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
    die();
}

if (wallos_get_effective_ssrf_allowlist($db)['is_managed']) {
    echo json_encode([
        "success" => false,
        "message" => translate('ssrf_allowlist_env_managed', $i18n)
    ]);
    die();
}

// Basic cleanup: trim whitespace and strip any accidental HTML tags
$allowlist = trim(strip_tags($data['local_webhook_notifications_allowlist']));
$allowStandardUsers = !empty($data['allow_standard_users_local_webhooks']) ? 1 : 0;

// Update the admin table (assuming id 1 is the primary settings row, as in your reference)
$sql = "UPDATE admin SET local_webhook_notifications_allowlist = :allowlist, allow_standard_users_local_webhooks = :allowStandardUsers WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->bindParam(':allowlist', $allowlist, SQLITE3_TEXT);
// No type constant: those are what issue #41 confines to the adapter, and the
// value is already an int.
$stmt->bindParam(':allowStandardUsers', $allowStandardUsers);
$result = $stmt->execute();

if ($result) {
    echo json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}