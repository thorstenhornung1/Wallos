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

if ($admin['require_email_verification'] == 0) {
    if (php_sapi_name() !== 'cli') {
        echo "Email verification is not required.";
    }
    die();
}

$query = "SELECT * FROM email_verification WHERE email_sent = 0";
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
                $mail->Subject = 'Wallos - Email Verification';
                $mail->Body = '<img src="' . $server_url . '/images/siteicons/wallos.png" alt="Logo" />
                    <br>
                    Registration on Wallos was successful.
                    <br>
                    Please click the following link to verify your email: <a href="' . $server_url . '/verifyemail.php?email=' . $user['email'] . '&token=' . $user['token'] . '">Verify Email</a>';

                $mail->send();

                $query = "UPDATE email_verification SET email_sent = 1 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $user['id'], SQLITE3_INTEGER);
                $stmt->execute();

                $mail->clearAddresses();

                echo "Verification email sent to " . $user['email'] . "<br>";
            }
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        // The instance SMTP transport is unusable
        echo "Verification emails not sent: " . $transport['message'] . "\n";
        exit();
    }
} else {
    // There are no verification emails to be sent
    if (php_sapi_name() !== 'cli') {
        echo "No verification emails to be sent.";
    }
    exit();
}

?>
