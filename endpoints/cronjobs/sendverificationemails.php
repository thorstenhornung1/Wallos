<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('sendverificationemails');

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

if ($admin['require_email_verification'] == 0) {
    wallos_cron_done('email verification is not required');

    if (php_sapi_name() !== 'cli') {
        echo "Email verification is not required.";
    }
    die();
}

$query = "SELECT * FROM email_verification WHERE email_sent = 0";
$stmt = $db->prepare($query);
$result = $stmt === false ? false : $stmt->execute();

if ($result === false) {
    wallos_cron_fail('could not read the verification queue: ' . wallos_cron_reason($db));
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
            // The try is inside the loop on purpose. PHPMailer is constructed
            // with exceptions enabled, so one address it refuses used to end
            // the whole batch — and every registration queued behind it waited
            // for a verification mail that was never attempted again, because
            // the run that would retry it stops at the same address.
            try {
                $mail->addAddress($user['email']);
                $mail->isHTML(true);
                $mail->Subject = 'Wallos - Email Verification';
                $mail->Body = '<img src="' . $server_url . '/images/siteicons/wallos.png" alt="Logo" />
                    <br>
                    Registration on Wallos was successful.
                    <br>
                    Please click the following link to verify your email: <a href="' . $server_url . '/verifyemail.php?email=' . $user['email'] . '&token=' . $user['token'] . '">Verify Email</a>';

                $mail->send();
            } catch (Exception $e) {
                wallos_cron_problem('verification email to ' . $user['email'] . ' was not sent: '
                    . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()));
                $mail->clearAddresses();
                echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
                continue;
            }

            $query = "UPDATE email_verification SET email_sent = 1 WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $user['id'], SQLITE3_INTEGER);

            if ($stmt === false || $stmt->execute() === false) {
                // The mail has gone and the queue still says it has not. This
                // job runs every two minutes, so the address receives the same
                // verification mail thirty times an hour until someone notices.
                wallos_cron_problem('verification email to ' . $user['email']
                    . ' was sent but the queue was not updated, so it will be sent again: '
                    . wallos_cron_reason($db));
            }

            $sent++;
            $mail->clearAddresses();

            echo "Verification email sent to " . $user['email'] . "<br>";
        }

        wallos_cron_count('queued', count($rows));
        wallos_cron_count('sent', $sent);
        wallos_cron_done();
    } else {
        // The instance SMTP transport is unusable. Nothing was attempted, so
        // nothing is marked sent and the queue is intact — but nobody can
        // finish registering until this is fixed, and until now the only
        // record of it was one line in a file inside the container.
        echo "Verification emails not sent: " . $transport['message'] . "\n";
        wallos_cron_fail('the instance mail transport is unusable, so ' . count($rows)
            . ' queued verification email(s) were not sent: ' . $transport['message']);
    }
} else {
    // There are no verification emails to be sent, which is the normal state
    // of this job on almost every one of its runs.
    wallos_cron_done('nothing queued');

    if (php_sapi_name() !== 'cli') {
        echo "No verification emails to be sent.";
    }
    exit();
}

?>
