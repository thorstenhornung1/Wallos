<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('sendcancellationnotifications');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ssrf_helper.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notification_settings.php';
wallos_cron_database($db);

require 'settimezone.php';

// Get all user ids
$query = "SELECT id, username FROM \"user\"";
$stmt = $db->prepare($query);
$usersToNotify = $stmt === false ? false : $stmt->execute();

if ($usersToNotify === false) {
    wallos_cron_fail('could not read the user list: ' . wallos_cron_reason($db));
}

// One query per provider table for everybody, instead of one per user per
// provider.
$notificationSettings = wallos_load_notification_settings($db);
$usersWithNotifications = wallos_users_with_notifications($notificationSettings, $db);

while ($userToNotify = $usersToNotify->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToNotify['id'];
    if (php_sapi_name() !== 'cli') {
        echo "For user: " . $userToNotify['username'] . "<br />";
    }

    $emailNotificationsEnabled = false;
    $gotifyNotificationsEnabled = false;
    $telegramNotificationsEnabled = false;
    $pushoverNotificationsEnabled = false;
    $discordNotificationsEnabled = false;
    $ntfyNotificationsEnabled = false;
    $webhookNotificationsEnabled = false;

    if (!isset($usersWithNotifications[$userId])) {
        continue;
    }

    // Check if email notifications are enabled and resolve the effective
    // transport (instance SMTP or the user's own, depending on their choice)
    $emailConfig = wallos_get_effective_smtp_config($db, $userId);
    $emailNotificationsEnabled = !empty($emailConfig['values']['enabled']);

    if ($row = $notificationSettings['discord'][$userId] ?? null) {
        $discordNotificationsEnabled = $row['enabled'];
        $discord['webhook_url'] = $row["webhook_url"];
        $discord['bot_username'] = $row["bot_username"];
        $discord['bot_avatar_url'] = $row["bot_avatar_url"];
    }

    $gotify = [];

    // Instance server host plus this user's own application token, which is
    // never shared across users.
    $gotifyConfig = wallos_effective_gotify_config(
        wallos_get_instance_gotify_config($db),
        $notificationSettings['gotify'][$userId] ?? []
    );
    if (!empty($gotifyConfig['values']['enabled'])) {
        $gotifyNotificationsEnabled = $gotifyConfig['values']['deliverable'];
        $gotify['serverUrl'] = $gotifyConfig['values']['url'];
        $gotify['appToken'] = $gotifyConfig['values']['token'];
        $gotify['ignore_ssl'] = $gotifyConfig['values']['ignore_ssl'];
    }

    // Instance bot token plus this user's own chat id; see the same block in
    // sendnotifications.php.
    $telegramConfig = wallos_effective_telegram_config(
        wallos_get_instance_telegram_config($db),
        $notificationSettings['telegram'][$userId] ?? []
    );
    if (!empty($telegramConfig['values']['enabled'])) {
        $telegramNotificationsEnabled = $telegramConfig['values']['deliverable'];
        $telegram['botToken'] = $telegramConfig['values']['bot_token'];
        $telegram['chatId'] = $telegramConfig['values']['chat_id'];
    }

    // Instance application token plus this user's own user key.
    $pushoverConfig = wallos_effective_pushover_config(
        wallos_get_instance_pushover_config($db),
        $notificationSettings['pushover'][$userId] ?? []
    );
    if (!empty($pushoverConfig['values']['enabled'])) {
        $pushoverNotificationsEnabled = $pushoverConfig['values']['deliverable'];
        $pushover['user_key'] = $pushoverConfig['values']['user_key'];
        $pushover['token'] = $pushoverConfig['values']['token'];
    }

    // Instance server (and its shared auth headers, or a per-user override) plus
    // this user's own topic.
    $ntfyConfig = wallos_effective_ntfy_config(
        wallos_get_instance_ntfy_config($db),
        $notificationSettings['ntfy'][$userId] ?? []
    );
    if (!empty($ntfyConfig['values']['enabled'])) {
        $ntfyNotificationsEnabled = $ntfyConfig['values']['deliverable'];
        $ntfy['host'] = $ntfyConfig['values']['host'];
        $ntfy['topic'] = $ntfyConfig['values']['topic'];
        $ntfy['headers'] = $ntfyConfig['values']['headers'];
        $ntfy['ignore_ssl'] = $ntfyConfig['values']['ignore_ssl'];
    }

    $webhook = [];

    if ($row = $notificationSettings['webhook'][$userId] ?? null) {
        $webhook['url'] = $row["url"];
        $webhook['headers'] = $row["headers"];
        $webhook['cancelation_payload'] = $row["cancelation_payload"];
        $webhook['ignore_ssl'] = $row["ignore_ssl"];
        $webhook['request_method'] = $row["request_method"];
        $webhookNotificationsEnabled = $row['enabled'] && $row['cancelation_payload'] != "";
    }

    $notificationsEnabled = $emailNotificationsEnabled || $gotifyNotificationsEnabled || $telegramNotificationsEnabled ||
        $pushoverNotificationsEnabled || $discordNotificationsEnabled ||$ntfyNotificationsEnabled || $webhookNotificationsEnabled;

    // If no notifications are enabled, no need to run
    if (!$notificationsEnabled) {
        if (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
        continue;
    } else {
        // Get all currencies
        $currencies = array();
        $query = "SELECT * FROM currencies WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $currencies[$row['id']] = $row;
        }

        // Get all household members
        $query = "SELECT * FROM household WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultHousehold = $stmt->execute();

        $household = [];
        while ($rowHousehold = $resultHousehold->fetchArray(SQLITE3_ASSOC)) {
            $household[$rowHousehold['id']] = $rowHousehold;
        }

        // Get all categories
        $query = "SELECT * FROM categories WHERE user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $resultCategories = $stmt->execute();

        $categories = [];
        while ($rowCategory = $resultCategories->fetchArray(SQLITE3_ASSOC)) {
            $categories[$rowCategory['id']] = $rowCategory;
        }

        // Get current date to check which subscriptions are set to notify for cancellation
        $currentDate = new DateTime('now');
        $currentDate = $currentDate->format('Y-m-d');

        $query = "SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = :inactive AND cancellation_date = :cancellationDate ORDER BY payer_user_id ASC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':cancellationDate', $currentDate, SQLITE3_TEXT);
        $resultSubscriptions = $stmt->execute();

        $notify = [];
        $i = 0;
        $currentDate = new DateTime('now');
        while ($rowSubscription = $resultSubscriptions->fetchArray(SQLITE3_ASSOC)) {
            $notify[$rowSubscription['payer_user_id']][$i]['name'] = $rowSubscription['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['price'] = $rowSubscription['price'] . $currencies[$rowSubscription['currency_id']]['symbol'];
            $notify[$rowSubscription['payer_user_id']][$i]['currency'] = $currencies[$rowSubscription['currency_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['category'] = $categories[$rowSubscription['category_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['payer'] = $household[$rowSubscription['payer_user_id']]['name'];
            $notify[$rowSubscription['payer_user_id']][$i]['date'] = $rowSubscription['next_payment'];
            $notify[$rowSubscription['payer_user_id']][$i]['url'] = $rowSubscription['url'];
            $notify[$rowSubscription['payer_user_id']][$i]['notes'] = $rowSubscription['notes'];
            $i++;
        }

        if (!empty($notify)) {

            // Email notifications if enabled
            if ($emailNotificationsEnabled) {
                $stmt = $db->prepare('SELECT * FROM "user" WHERE id = :user_id');
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $defaultUser = $result->fetchArray(SQLITE3_ASSOC);
                $defaultEmail = $defaultUser['email'];
                $defaultName = $defaultUser['username'];

                foreach ($notify as $userId => $perUser) {
                    $message = "The following subscriptions are up for cancellation:\n";

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] ."\n";
                    }

                    // Built per message and re-validated at send time: a save-time
                    // check alone is bypassable via DNS rebinding between when the
                    // host was saved and when the cron fires.
                    $transport = wallos_build_mailer($emailConfig, $db);

                    if (!$transport['success']) {
                        wallos_cron_problem('the mail transport of user ' . $userId
                            . ' is unusable, so no cancellation email was sent: '
                            . $transport['message']);
                        echo "Email notifications not sent: " . $transport['message'] . "<br />";
                        break;
                    }

                    $mail = $transport['mailer'];

                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    $emailaddress = !empty($user['email']) ? $user['email'] : $defaultEmail;
                    $name = !empty($user['name']) ? $user['name'] : $defaultName;

                    // Per recipient. PHPMailer throws from addAddress, addCC
                    // and send alike, and this notice only fires on the single
                    // day a subscription's cancellation date matches — so a run
                    // that dies at the first bad address does not lose one
                    // notification, it loses everyone else's for good.
                    try {
                        $mail->addAddress($emailaddress, $name);

                        if (!empty($emailConfig['values']['other_emails'])) {
                            $list = explode(';', $emailConfig['values']['other_emails']);

                            // Avoid duplicate emails
                            $list = array_unique($list);
                            $list = array_filter($list, function ($value) use ($emailaddress) {
                                return $value !== $emailaddress;
                            });

                            foreach($list as $value) {
                                $mail->addCC(trim($value));
                            }
                        }

                        $mail->Subject = 'Wallos Cancellation Notification';
                        $mail->Body = $message;

                        if ($mail->send()) {
                            wallos_cron_count('sent');
                            echo "Email Notifications sent<br />";
                        } else {
                            wallos_cron_problem('a cancellation email was not delivered: '
                                . $mail->ErrorInfo);
                            echo "Error sending notifications: " . $mail->ErrorInfo . "<br />";
                        }
                    } catch (Exception $error) {
                        wallos_cron_problem('a cancellation email was not delivered: '
                            . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $error->getMessage()));
                        echo "Error sending notifications: " . $error->getMessage() . "<br />";
                    }
                }
            }

            // Discord notifications if enabled
            if ($discordNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($discord['webhook_url'], $db, $userId);
                if (!$ssrf) {
                    wallos_cron_problem('the configured Discord URL failed the SSRF check, '
                        . 'so the whole Discord channel was skipped');
                    echo "Discord notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        $title = translate('wallos_notification', $i18n);

                        if ($user['name']) {
                            $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                        } else {
                            $message = "The following subscriptions are up for cancellation:\n";
                        }

                        foreach ($perUser as $subscription) {
                            $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                        }

                        $postfields = [
                            'content' => $message
                        ];

                        if (!empty($discord['bot_username'])) {
                            $postfields['username'] = $discord['bot_username'];
                        }

                        if (!empty($discord['bot_avatar_url'])) {
                            $postfields['avatar_url'] = $discord['bot_avatar_url'];
                        }

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $discord['webhook_url']);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json'
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $response = curl_exec($ch);
                        
                        if ($response === false) {
                            wallos_cron_problem('a Discord cancellation notification was not delivered: '
                                . curl_error($ch));
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            wallos_cron_count('sent');
                            echo "Discord Notifications sent<br />";
                        }
                        
                        unset($ch);
                    }
                }
            }

            // Gotify notifications if enabled
            if ($gotifyNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($gotify['serverUrl'], $db, $userId);
                if (!$ssrf) {
                    wallos_cron_problem('the configured Gotify URL failed the SSRF check, '
                        . 'so the whole Gotify channel was skipped');
                    echo "Gotify notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        if ($user['name']) {
                            $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                        } else {
                            $message = "The following subscriptions are up for cancellation:\n";
                        }

                        foreach ($perUser as $subscription) {
                            $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                        }

                        $data = array(
                            'message' => $message,
                            'priority' => 5
                        );

                        $data_string = json_encode($data);

                        $ch = curl_init($gotify['serverUrl'] . '/message?token=' . $gotify['appToken']);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt(
                            $ch,
                            CURLOPT_HTTPHEADER,
                            array(
                                'Content-Type: application/json',
                                'Content-Length: ' . strlen($data_string)
                            )
                        );

                        if ($gotify['ignore_ssl']) {
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        }
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $result = curl_exec($ch);
                        if ($result === false) {
                            wallos_cron_problem('a Gotify cancellation notification was not delivered: '
                                . curl_error($ch));
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            wallos_cron_count('sent');
                            echo "Gotify Notifications sent<br />";
                        }
                    }
                }
            }

            // Telegram notifications if enabled
            if ($telegramNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $data = array(
                        'chat_id' => $telegram['chatId'],
                        'text' => $message
                    );

                    $data_string = json_encode($data);

                    $ch = curl_init('https://api.telegram.org/bot' . $telegram['botToken'] . '/sendMessage');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen($data_string)
                        )
                    );

                    $result = curl_exec($ch);
                    if ($result === false) {
                        wallos_cron_problem('a Telegram cancellation notification was not delivered: '
                            . curl_error($ch));
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        wallos_cron_count('sent');
                        echo "Telegram Notifications sent<br />";
                    }
                }
            }

            // Pushover notifications if enabled
            if ($pushoverNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    if ($user['name']) {
                        $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                    } else {
                        $message = "The following subscriptions are up for cancellation:\n";
                    }

                    foreach ($perUser as $subscription) {
                        $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, "https://api.pushover.net/1/messages.json");
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'token' => $pushover['token'],
                        'user' => $pushover['user_key'],
                        'message' => $message,
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                    $result = curl_exec($ch);

                    if ($result === false) {
                        wallos_cron_problem('a Pushover cancellation notification was not delivered: '
                            . curl_error($ch));
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        wallos_cron_count('sent');
                        echo "Pushover Notifications sent<br />";
                    }
                    
                    unset($ch);
                }
            }

            // Ntfy notifications if enabled
            if ($ntfyNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($ntfy['host'], $db, $userId);
                if (!$ssrf) {
                    wallos_cron_problem('the configured Ntfy URL failed the SSRF check, '
                        . 'so the whole Ntfy channel was skipped');
                    echo "Ntfy notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        if ($user['name']) {
                            $message = $user['name'] . ", the following subscriptions are up for cancellation:\n";
                        } else {
                            $message = "The following subscriptions are up for cancellation:\n";
                        }

                        foreach ($perUser as $subscription) {
                            $message .= $subscription['name'] . " for " . $subscription['price'] . "\n";
                        }

                        // sendnotifications.php guards this with is_array();
                        // this file never did. An empty headers column, or one
                        // holding anything but a JSON object, makes json_decode
                        // answer null and array_keys(null) a TypeError — which
                        // ended the whole run, before every remaining user and
                        // every remaining channel, with nothing but a line in a
                        // file to show for it.
                        $headers = json_decode($ntfy["headers"], true);
                        $customheaders = [];

                        if (is_array($headers)) {
                            $customheaders = array_map(function ($key, $value) {
                                return "$key: $value";
                            }, array_keys($headers), $headers);
                        } elseif (trim((string) $ntfy["headers"]) !== '') {
                            wallos_cron_problem('the ntfy headers of user ' . $userId
                                . ' are not a JSON object, so they were not sent');
                        }

                        $ch = curl_init();

                        $ntfyHost = rtrim($ntfy["host"], '/');
                        $ntfyTopic = $ntfy['topic'];

                        curl_setopt($ch, CURLOPT_URL, $ntfyHost . '/' . $ntfyTopic);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                        if ($ntfy['ignore_ssl']) {
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        }
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $response = curl_exec($ch);
                        
                        if ($response === false) {
                            wallos_cron_problem('an ntfy cancellation notification was not delivered: '
                                . curl_error($ch));
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            wallos_cron_count('sent');
                            echo "Ntfy Notifications sent<br />";
                        }
                        
                        unset($ch);
                    }
                }
            }

            // Webhook notifications if enabled
            if ($webhookNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($webhook['url'], $db, $userId);
                if (!$ssrf) {
                    wallos_cron_problem('the configured webhook URL failed the SSRF check, '
                        . 'so the whole webhook channel was skipped');
                    echo "Webhook notification skipped: URL failed SSRF validation.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];
                
                        if ($user['name']) {
                            $payer = $user['name'];
                        }
                
                        foreach ($perUser as $subscription) {
                            // Ensure the payload is reset for each subscription
                            $payload = $webhook['cancelation_payload'];
                            $payload = str_replace("{{subscription_name}}", $subscription['name'], $payload);
                            $payload = str_replace("{{subscription_price}}", $subscription['price'], $payload);
                            $payload = str_replace("{{subscription_currency}}", $subscription['currency'], $payload);
                            $payload = str_replace("{{subscription_category}}", $subscription['category'], $payload);
                            $payload = str_replace("{{subscription_payer}}", $payer, $payload);
                            $payload = str_replace("{{subscription_date}}", $subscription['date'], $payload);
                            $payload = str_replace("{{subscription_url}}", $subscription['url'], $payload);
                            $payload = str_replace("{{subscription_notes}}", $subscription['notes'], $payload);
                
                            // Initialize cURL for each subscription
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $webhook['url']);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $webhook['request_method']);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                
                            // Add headers if they exist
                            if (!empty($webhook['headers'])) {
                                $customheaders = preg_split("/\r\n|\n|\r/", $webhook['headers']);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $customheaders);
                            }
                
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                            // Handle SSL settings
                            if ($webhook['ignore_ssl']) {
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            }
                            curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);
                
                            // Execute the cURL request
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                            if ($response === false || $httpCode >= 400) {
                                // curl_error() is empty on a 4xx, which is how
                                // this printed a reason-less error line.
                                wallos_cron_problem('a webhook cancellation notification was not delivered: '
                                    . ($response === false ? curl_error($ch) : 'HTTP ' . $httpCode));
                                echo "Error sending cancellation notifications: " . curl_error($ch) . "<br />";
                            } else {
                                wallos_cron_count('sent');
                                echo "Webhook Cancellation Notification sent for subscription: " . $subscription['name'] . "<br />";
                            }
                            
                            unset($ch);
                
                            usleep(1000000); // 1s delay between requests
                        }
                    }
                }
            }
            

        } else {
            if (php_sapi_name() !== 'cli') {
                echo "Nothing to notify.<br />";
            }
        }

    }

}

// The sentinel. A run that does not reach this is reported as stopped, which
// is what a die() in an include or an uncatchable fatal leaves behind and what
// nothing used to tell apart from a day with no cancellations.
wallos_cron_done();

?>
