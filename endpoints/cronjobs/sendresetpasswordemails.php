<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/mailer.php';

require 'settimezone.php';

$query = "SELECT * FROM admin";
$stmt = $db->prepare($query);
$result = $stmt->execute();
$admin = $result->fetchArray(SQLITE3_ASSOC);

$query = "SELECT * FROM password_resets WHERE email_sent = 0";
$stmt = $db->prepare($query);
$result = $stmt->execute();

$rows = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

if ($rows) {
    $transport = wallos_build_instance_mailer($db);

    if ($transport['success']) {
        $server_url = $admin['server_url'];
        $mail = $transport['mailer'];

        try {
            foreach ($rows as $user) {
                $mail->addAddress($user['email']);
                $mail->isHTML(true);
                $mail->Subject = 'Wallos - Reset Password';
                $mail->Body = '<img src="' . $server_url . '/images/siteicons/wallos.png" alt="Logo" />
                    <br>
                    A password reset was requested for your account.
                    <br>
                    Please click the following link to reset your password: <a href="' . $server_url . '/passwordreset.php?email=' . $user['email'] . '&token=' . $user['token'] . '">Reset Password</a>';

                $mail->send();

                $query = "UPDATE password_resets SET email_sent = 1 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $user['id'], SQLITE3_INTEGER);
                $stmt->execute();

                $mail->clearAddresses();

                echo "Password reset email sent to " . $user['email'] . "<br>";

            }
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo} <br>";
        }
    } else {
        // The instance SMTP transport is unusable
        echo "Password reset emails not sent: " . $transport['message'] . "\n";
        exit();
    }
} else {
    // There are no password reset emails to be sent
    if (php_sapi_name() !== 'cli') {
        echo "There are no password reset emails to be sent.";
    }
    exit();
}

?>
