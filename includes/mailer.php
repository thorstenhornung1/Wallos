<?php
/*
  Shared SMTP transport.

  Every Wallos generated email — password resets, verification mail, renewal and
  cancellation notifications, and the test buttons — builds its PHPMailer
  instance here, from the configuration returned by
  wallos_get_effective_smtp_config(). Tests therefore exercise exactly the
  transport that production will use.

  Callers must have loaded $db.
*/

require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/ssrf_helper.php';

require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/Exception.php';

/**
 * Builds a configured PHPMailer instance, with the sender already applied.
 *
 * @param array   $config Result of wallos_get_effective_smtp_config().
 * @param SQLite3 $db
 * @return array{success: bool, mailer: \PHPMailer\PHPMailer\PHPMailer|null, message: string}
 */
function wallos_build_mailer($config, $db)
{
    if (empty($config['valid'])) {
        return [
            'success' => false,
            'mailer' => null,
            'message' => $config['notes'][0] ?? 'SMTP is not configured.',
        ];
    }

    $host = (string) $config['values']['host'];
    $port = (int) $config['values']['port'];
    $encryption = (string) $config['values']['encryption'];
    $username = (string) ($config['values']['username'] ?? '');
    $password = (string) ($config['values']['password'] ?? '');
    $fromEmail = (string) $config['values']['from_email'];
    $fromName = trim((string) ($config['values']['from_name'] ?? ''));

    if (!validate_smtp_host($host, $port, $db)) {
        return [
            'success' => false,
            'mailer' => null,
            'message' => 'Security Error: SMTP host must not target link-local or loopback addresses.',
        ];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Timeout = 15;
    $mail->Host = $host;
    $mail->Port = $port;

    $smtpAuth = $username !== '' || $password !== '';
    $mail->SMTPAuth = $smtpAuth;
    if ($smtpAuth) {
        $mail->Username = $username;
        $mail->Password = $password;
    }

    if ($encryption !== 'none') {
        $mail->SMTPSecure = $encryption;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    try {
        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'Wallos App');
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return [
            'success' => false,
            'mailer' => null,
            'message' => $e->getMessage(),
        ];
    }

    return [
        'success' => true,
        'mailer' => $mail,
        'message' => '',
    ];
}

/**
 * Convenience wrapper for system email, which always uses the instance
 * transport and never a per-user override.
 *
 * @param SQLite3 $db
 * @return array{success: bool, mailer: \PHPMailer\PHPMailer\PHPMailer|null, message: string}
 */
function wallos_build_instance_mailer($db)
{
    return wallos_build_mailer(wallos_get_instance_smtp_config($db), $db);
}
