<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('sendresetpasswordemails');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/mailer.php';
wallos_cron_database($db);

require 'settimezone.php';

$query = "SELECT * FROM admin";
$stmt = $db->prepare($query);
$result = $stmt === false ? false : $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not read the admin settings: ' . wallos_cron_reason($db));
}

$admin = $result->fetchArray(SQLITE3_ASSOC);

if ($admin === false) {
    wallos_cron_fail('the admin settings row is missing');
}

$query = "SELECT * FROM password_resets WHERE email_sent = 0";
$stmt = $db->prepare($query);
$result = $stmt === false ? false : $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not read the password reset queue: ' . wallos_cron_reason($db));
}

$rows = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

if ($rows) {
    $transport = wallos_build_instance_mailer($db);

    if ($transport['success']) {
        $server_url = $admin['server_url'];
        $mail = $transport['mailer'];
        $sent = 0;

        foreach ($rows as $user) {
            // Per recipient, not per batch. The token these mails carry is
            // valid for one hour; a batch that stops at the first address
            // PHPMailer refuses leaves everyone behind it waiting for a link
            // that expires before the next attempt would have delivered it.
            try {
                $mail->addAddress($user['email']);
                $mail->isHTML(true);
                $mail->Subject = 'Wallos - Reset Password';
                $mail->Body = '<img src="' . $server_url . '/images/siteicons/wallos.png" alt="Logo" />
                    <br>
                    A password reset was requested for your account.
                    <br>
                    Please click the following link to reset your password: <a href="' . $server_url . '/passwordreset.php?email=' . $user['email'] . '&token=' . $user['token'] . '">Reset Password</a>';

                $mail->send();
            } catch (Exception $e) {
                wallos_cron_problem('password reset email to ' . $user['email'] . ' was not sent: '
                    . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()));
                $mail->clearAddresses();
                echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo} <br>";
                continue;
            }

            $query = "UPDATE password_resets SET email_sent = 1 WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $user['id'], SQLITE3_INTEGER);

            if ($stmt === false || $stmt->execute() === false) {
                wallos_cron_problem('password reset email to ' . $user['email']
                    . ' was sent but the queue was not updated, so it will be sent again: '
                    . wallos_cron_reason($db));
            }

            $sent++;
            $mail->clearAddresses();

            echo "Password reset email sent to " . $user['email'] . "<br>";
        }

        wallos_cron_count('queued', count($rows));
        wallos_cron_count('sent', $sent);
        wallos_cron_done();
    } else {
        // Nobody who has forgotten their password can get back in, and the
        // page that sent them here told them to check their mail.
        echo "Password reset emails not sent: " . $transport['message'] . "\n";
        wallos_cron_fail('the instance mail transport is unusable, so ' . count($rows)
            . ' queued password reset email(s) were not sent: ' . $transport['message']);
    }
} else {
    // There are no password reset emails to be sent, which is the normal state
    // of this job on almost every one of its runs.
    wallos_cron_done('nothing queued');

    if (php_sapi_name() !== 'cli') {
        echo "There are no password reset emails to be sent.";
    }
    exit();
}

?>
