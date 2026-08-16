<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$enabled = $data["enabled"] ?? 0;
$otherEmails = $data["otheremails"] ?? '';

// Clients that predate the instance/custom choice keep their own transport as
// long as they submit one.
$defaultMode = empty($data["smtpaddress"]) ? 'instance' : 'custom';
$mode = wallos_normalize_mode($data['smtpmode'] ?? $defaultMode);

if ($mode === 'custom') {
    if (
        !isset($data["smtpaddress"]) || $data["smtpaddress"] == "" ||
        !isset($data["smtpport"]) || $data["smtpport"] == ""
    ) {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }

    $smtpConfig = wallos_smtp_config_from_input($data);

    if (!$smtpConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => $smtpConfig['notes'][0] ?? translate('fill_mandatory_fields', $i18n)
        ]));
    }

    if (!validate_smtp_host($smtpConfig['values']['host'], (int) $smtpConfig['values']['port'], $db)) {
        die(json_encode([
            "success" => false,
            "message" => "Security Error: SMTP host must not target link-local or loopback addresses."
        ]));
    }
} else {
    // Nothing from the instance transport is copied into the user's row.
    $instanceConfig = wallos_get_instance_smtp_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_smtp_not_configured', $i18n)
        ]));
    }
}

$query = "SELECT COUNT(*) FROM email_notifications WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);
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
        ? "UPDATE email_notifications
           SET enabled = :enabled, smtp_mode = :smtpMode, smtp_address = :smtpAddress, smtp_port = :smtpPort,
               smtp_username = :smtpUsername, smtp_password = :smtpPassword, from_email = :fromEmail,
               other_emails = :otherEmails, encryption = :encryption
           WHERE user_id = :userId"
        : "INSERT INTO email_notifications (enabled, smtp_mode, smtp_address, smtp_port, smtp_username, smtp_password, from_email, other_emails, encryption, user_id)
           VALUES (:enabled, :smtpMode, :smtpAddress, :smtpPort, :smtpUsername, :smtpPassword, :fromEmail, :otherEmails, :encryption, :userId)";
} else {
    // Only the user owned values are written; any previously stored custom
    // transport is kept so switching back does not lose it.
    $query = $exists
        ? "UPDATE email_notifications
           SET enabled = :enabled, smtp_mode = :smtpMode, other_emails = :otherEmails
           WHERE user_id = :userId"
        : "INSERT INTO email_notifications (enabled, smtp_mode, other_emails, user_id)
           VALUES (:enabled, :smtpMode, :otherEmails, :userId)";
}

$stmt = $db->prepare($query);
$stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
$stmt->bindValue(':smtpMode', $mode, SQLITE3_TEXT);
$stmt->bindValue(':otherEmails', $otherEmails, SQLITE3_TEXT);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($mode === 'custom') {
    $stmt->bindValue(':smtpAddress', $smtpConfig['values']['host'], SQLITE3_TEXT);
    $stmt->bindValue(':smtpPort', $smtpConfig['values']['port'], SQLITE3_INTEGER);
    $stmt->bindValue(':smtpUsername', $smtpConfig['values']['username'], SQLITE3_TEXT);
    $stmt->bindValue(':smtpPassword', $smtpConfig['values']['password'], SQLITE3_TEXT);
    $stmt->bindValue(':fromEmail', $data['fromemail'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':encryption', $smtpConfig['values']['encryption'], SQLITE3_TEXT);
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
