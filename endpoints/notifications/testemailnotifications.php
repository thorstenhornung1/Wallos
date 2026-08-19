<?php

use PHPMailer\PHPMailer\Exception;

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/mailer.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$mode = wallos_normalize_mode($data['smtpmode'] ?? 'custom');

if ($mode === 'instance') {
    // The instance transport is never sent to the browser, so it is resolved
    // here instead of being taken from the form.
    $smtpConfig = wallos_get_instance_smtp_config($db);
} else {
    if (
        !isset($data["smtpaddress"]) || $data["smtpaddress"] == "" ||
        !isset($data["smtpport"]) || $data["smtpport"] == ""
    ) {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_all_fields', $i18n)
        ]));
    }

    $smtpConfig = wallos_smtp_config_from_input($data);
}

$transport = wallos_build_mailer($smtpConfig, $db);

if (!$transport['success']) {
    die(json_encode([
        "success" => false,
        "message" => $transport['message']
    ]));
}

$mail = $transport['mailer'];

$getUser = "SELECT username, email FROM \"user\" WHERE id = :userId";
$stmt = $db->prepare($getUser);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

if (!$user) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

try {
    $mail->addAddress($user['email'], $user['username']);
    $mail->Subject = translate('wallos_notification', $i18n);
    $mail->Body = translate('test_notification', $i18n);

    if ($mail->send()) {
        $message = translate('notification_sent_successfuly', $i18n);

        // A working transport is not the same as working notifications: the
        // scheduled job only sends for users who enabled and saved them. Say so
        // here, or a green test is read as proof that renewals will arrive.
        if (($data['context'] ?? '') === 'user') {
            $effective = wallos_get_effective_smtp_config($db, $userId);

            if (empty($effective['values']['enabled'])) {
                $message .= ' ' . translate('notifications_not_enabled_yet', $i18n);
            }
        }

        $response = [
            "success" => true,
            "message" => $message
        ];
    } else {
        $response = [
            "success" => false,
            "message" => translate('email_error', $i18n) . $mail->ErrorInfo
        ];
    }
} catch (Exception $e) {
    $response = [
        "success" => false,
        "message" => translate('email_error', $i18n) . $e->getMessage()
    ];
}

die(json_encode($response));
