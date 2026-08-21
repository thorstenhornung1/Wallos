<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('sendnotifications');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ssrf_helper.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notification_settings.php';
wallos_cron_database($db);

require __DIR__ . '/../../includes/currency_formatter.php';
require __DIR__ . '/../../includes/budget_period_calculations.php';

require 'settimezone.php';

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
} else {
    echo "On Timezone: " . date_default_timezone_get() . "<br /><br />";
}

// Get all user ids
$query = "SELECT id, username FROM \"user\"";
$stmt = $db->prepare($query);
$usersToNotify = $stmt === false ? false : $stmt->execute();

if ($usersToNotify === false) {
    wallos_cron_fail('could not read the user list: ' . wallos_cron_reason($db));
}

// One query per provider table for everybody, instead of one per user per
// provider. Users with nothing enabled are skipped before any further work.
$notificationSettings = wallos_load_notification_settings($db);
$notificationTiming = wallos_load_notification_timing($db);
$usersWithNotifications = wallos_users_with_notifications($notificationSettings, $db);

function getDaysText($days)
{
    if ($days == 0) {
        return "Today";
    } elseif ($days == 1) {
        return "Tomorrow";
    } else {
        return "In " . $days . " days";
    }
}

function formatPrice($price, $currencyCode, $currencySymbol)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);

    if (strpos($formattedPrice, $currencyCode) !== false) {
        $formattedPrice = str_replace($currencyCode, $currencySymbol . ' ', $formattedPrice);
        $formattedPrice = preg_replace('/\s+/', ' ', $formattedPrice);
    }

    return $formattedPrice;
}

function buildNotificationMessage($name, $perUser, $periodSummaryLine, $includePeriodSummary)
{
    if (empty($perUser) && !$includePeriodSummary) {
        return "";
    }

    if (empty($perUser)) {
        return ($name ? $name . ", " : "") . $periodSummaryLine . "\n";
    }

    if ($name) {
        $message = $name . ", the following subscriptions are up for renewal:\n";
    } else {
        $message = "The following subscriptions are up for renewal:\n";
    }

    foreach ($perUser as $subscription) {
        $dayText = getDaysText($subscription['days']);
        $message .= $subscription['name'] . " for " . $subscription['formatted_price'] . " (" . $dayText . ")\n";
    }

    if ($includePeriodSummary) {
        $message .= "\n" . $periodSummaryLine . "\n";
    }

    return $message;
}

// Six questions for everybody, instead of six per person.
//
// This loop used to ask each account for its currencies, household members,
// categories, budget configuration and subscriptions one at a time. On SQLite
// that is a function call and the shape is invisible; on PostgreSQL it is a
// network round trip each, so the one job that runs unattended over every
// account grew with the number of accounts multiplied by the latency to the
// database — about 2.5 ms per account over loopback and 10 ms over an overlay
// network (issue #99).
//
// Only the accounts that will be processed: an installation with ten thousand
// accounts and forty using notifications reads forty accounts' worth of rows.
$notifiedUserIds = array_keys($usersWithNotifications);

$currenciesByUser = wallos_index_rows_by(
    wallos_load_rows_by_user($db, 'currencies', $notifiedUserIds), 'id');
$householdByUser = wallos_index_rows_by(
    wallos_load_rows_by_user($db, 'household', $notifiedUserIds), 'id');
$categoriesByUser = wallos_index_rows_by(
    wallos_load_rows_by_user($db, 'categories', $notifiedUserIds), 'id');
$activeSubscriptionsByUser = wallos_load_rows_by_user($db, 'subscriptions', $notifiedUserIds,
    'user_id, price, currency_id, next_payment, cycle, frequency, inactive, auto_renew',
    'user_id', 'inactive = 0');
$notifySubscriptionsByUser = wallos_load_rows_by_user($db, 'subscriptions', $notifiedUserIds,
    '*', 'user_id', 'notify = 1 AND inactive = 0');

$budgetByUser = [];
$budgetRows = wallos_load_rows_by_user($db, 'user', $notifiedUserIds,
    'id AS user_id, main_currency, period_budget, budget_period_type, budget_period_anchor_date',
    'id');

foreach ($budgetRows as $userId => $rows) {
    $budgetByUser[$userId] = $rows[0];
}

while ($userToNotify = $usersToNotify->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToNotify['id'];
    if (php_sapi_name() !== 'cli') {
        echo "For user: " . $userToNotify['username'] . "<br /><br />";
    }

    $days = 1;
    $periodSummaryAtPeriodStart = 0;
    $emailNotificationsEnabled = false;
    $gotifyNotificationsEnabled = false;
    $telegramNotificationsEnabled = false;
    $webhookNotificationsEnabled = false;
    $pushoverNotificationsEnabled = false;
    $pushplusNotificationsEnabled = false;
    $mattermostNotificationsEnabled = false;
    $discordNotificationsEnabled = false;
    $ntfyNotificationsEnabled = false;
    $serverchanNotificationsEnabled = false;

    if (!isset($usersWithNotifications[$userId])) {
        if (php_sapi_name() !== 'cli') {
            echo "No notifications enabled for this user<br /><br />";
        }
        continue;
    }

    // Notification timing (how many days before the subscription ends should the notification be sent)
    if (isset($notificationTiming[$userId])) {
        $days = $notificationTiming[$userId]['days'];
        $periodSummaryAtPeriodStart = $notificationTiming[$userId]['period_summary_at_period_start'];
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

    if ($row = $notificationSettings['gotify'][$userId] ?? null) {
        $gotifyNotificationsEnabled = $row['enabled'];
        $gotify['serverUrl'] = $row["url"];
        $gotify['appToken'] = $row["token"];
        $gotify['ignore_ssl'] = $row["ignore_ssl"];
    }

    if ($row = $notificationSettings['telegram'][$userId] ?? null) {
        $telegramNotificationsEnabled = $row['enabled'];
        $telegram['botToken'] = $row["bot_token"];
        $telegram['chatId'] = $row["chat_id"];
    }

    if ($row = $notificationSettings['pushplus'][$userId] ?? null) {
        $pushplusNotificationsEnabled = $row['enabled'];
        $pushplus['token'] = $row["token"];
    }

    if ($row = $notificationSettings['mattermost'][$userId] ?? null) {
        $mattermostNotificationsEnabled = $row['enabled'];
        $mattermost['webhook_url'] = $row['webhook_url'];
        $mattermost['bot_username'] = $row['bot_username'];
        $mattermost['bot_icon_emoji'] = $row['bot_icon_emoji'];
    }

    if ($row = $notificationSettings['pushover'][$userId] ?? null) {
        $pushoverNotificationsEnabled = $row['enabled'];
        $pushover['user_key'] = $row["user_key"];
        $pushover['token'] = $row["token"];
    }

    if ($row = $notificationSettings['ntfy'][$userId] ?? null) {
        $ntfyNotificationsEnabled = $row['enabled'];
        $ntfy['host'] = $row["host"];
        $ntfy['topic'] = $row["topic"];
        $ntfy['headers'] = $row["headers"];
        $ntfy['ignore_ssl'] = $row["ignore_ssl"];
    }

    if ($row = $notificationSettings['webhook'][$userId] ?? null) {
        $webhookNotificationsEnabled = $row['enabled'];
        $webhook['url'] = $row["url"];
        $webhook['request_method'] = $row["request_method"];
        $webhook['headers'] = $row["headers"];
        $webhook['payload'] = $row["payload"];
        $webhook['ignore_ssl'] = $row["ignore_ssl"];
    }

    if ($row = $notificationSettings['serverchan'][$userId] ?? null) {
        $serverchanNotificationsEnabled = $row['enabled'];
        $serverchan['sendkey'] = $row['sendkey'];
    }

    $notificationsEnabled = $emailNotificationsEnabled || $gotifyNotificationsEnabled || $telegramNotificationsEnabled ||
        $webhookNotificationsEnabled || $pushoverNotificationsEnabled || $discordNotificationsEnabled || $pushplusNotificationsEnabled ||
        $mattermostNotificationsEnabled || $ntfyNotificationsEnabled || $serverchanNotificationsEnabled;

    // If no notifications are enabled, no need to run
    if (!$notificationsEnabled) {
        if (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
        continue;
    } else {
        // Loaded for every account before the loop; see the block above it.
        $currencies = $currenciesByUser[$userId] ?? [];

        $household = $householdByUser[$userId] ?? [];

        $categories = $categoriesByUser[$userId] ?? [];

        $currentDate = new DateTime('now');

        $userBudgetConfig = $budgetByUser[$userId] ?? [];

        $mainCurrencyId = $userBudgetConfig['main_currency'];
        $budgetPeriodType = sanitizeBudgetPeriodType($userBudgetConfig['budget_period_type'] ?? 'monthly');
        $budgetPeriodAnchorDate = sanitizeBudgetAnchorDate($userBudgetConfig['budget_period_anchor_date'] ?? getDefaultBudgetAnchorDate());
        $activeBudgetPeriod = getActiveBudgetPeriod($currentDate, $budgetPeriodType, $budgetPeriodAnchorDate);
        $isPeriodStart = $activeBudgetPeriod['start']->format('Y-m-d') === $currentDate->format('Y-m-d');

        // Filtered here rather than in SQL: the rows are already in memory, and
        // a second query per account is the thing this is removing.
        $periodSubscriptions = [];

        foreach ($activeSubscriptionsByUser[$userId] ?? [] as $row) {
            if ((int) $row['inactive'] === 0) {
                $periodSubscriptions[] = $row;
            }
        }

        $amountNeededThisPeriod = computeAmountNeededInPeriod($periodSubscriptions, $currentDate, $activeBudgetPeriod['end'], $db, $userId);
        $mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'USD';
        $mainCurrencySymbol = $currencies[$mainCurrencyId]['symbol'] ?? '$';
        $periodSummaryLine = translate('amount_for_pay_period', $i18n) . ": " . formatPrice($amountNeededThisPeriod, $mainCurrencyCode, $mainCurrencySymbol);

        if (!empty($userBudgetConfig['period_budget']) && $userBudgetConfig['period_budget'] > 0) {
            $remaining = max(0, $userBudgetConfig['period_budget'] - $amountNeededThisPeriod);
            $periodSummaryLine .= " | " . translate('remaining', $i18n) . ": " . formatPrice($remaining, $mainCurrencyCode, $mainCurrencySymbol);
        }

        $sendPeriodStartSummaryOnly = $periodSummaryAtPeriodStart === 1 && $isPeriodStart;

        // The notify list for this account, out of the rows loaded before the
        // loop. Sorted here by the same key the query used — payer, ascending —
        // because the grouping below builds one message per payer and the order
        // decides how it reads.
        $subscriptionsToConsider = $notifySubscriptionsByUser[$userId] ?? [];
        usort($subscriptionsToConsider, function ($left, $right) {
            return (int) ($left['payer_user_id'] ?? 0) <=> (int) ($right['payer_user_id'] ?? 0);
        });

        $notify = [];
        $i = 0;
        foreach ($subscriptionsToConsider as $rowSubscription) {
            if ((int) $rowSubscription['notify'] !== 1 || (int) $rowSubscription['inactive'] !== 0) {
                continue;
            }

            if ($rowSubscription['notify_days_before'] !== -1) {
                $daysToCompare = $rowSubscription['notify_days_before'];
            } else {
                $daysToCompare = $days;
            }
            $nextPaymentDate = new DateTime($rowSubscription['next_payment']);

            $difference = $currentDate->diff($nextPaymentDate)->days;
            if ($nextPaymentDate > $currentDate) {
                $difference += 1;
            }

            if ($difference === $daysToCompare && $nextPaymentDate->format('Y-m-d') >= $currentDate->format('Y-m-d')) {
                echo "Subscription: " . $rowSubscription['name'] . "<br />";
                echo "Next payment date: " . $nextPaymentDate->format('Y-m-d') . "<br />";
                echo "Current date: " . $currentDate->format('Y-m-d') . "<br />";
                echo "Difference: " . $difference . "<br /><br />";
                $notify[$rowSubscription['payer_user_id']][$i]['name'] = html_entity_decode($rowSubscription['name'], ENT_QUOTES, 'UTF-8');
                $notify[$rowSubscription['payer_user_id']][$i]['price'] = $rowSubscription['price'] . $currencies[$rowSubscription['currency_id']]['symbol'];
                $notify[$rowSubscription['payer_user_id']][$i]['currency'] = $currencies[$rowSubscription['currency_id']]['name'];
                $notify[$rowSubscription['payer_user_id']][$i]['currency_symbol'] = $currencies[$rowSubscription['currency_id']]['symbol'];
                $notify[$rowSubscription['payer_user_id']][$i]['formatted_price'] = formatPrice($rowSubscription['price'], $currencies[$rowSubscription['currency_id']]['code'], $currencies[$rowSubscription['currency_id']]['symbol']);
                $notify[$rowSubscription['payer_user_id']][$i]['category'] = $categories[$rowSubscription['category_id']]['name'];
                $notify[$rowSubscription['payer_user_id']][$i]['payer'] = $household[$rowSubscription['payer_user_id']]['name'];
                $notify[$rowSubscription['payer_user_id']][$i]['date'] = $rowSubscription['next_payment'];
                $notify[$rowSubscription['payer_user_id']][$i]['days'] = $daysToCompare;
                $notify[$rowSubscription['payer_user_id']][$i]['url'] = $rowSubscription['url'];
                $notify[$rowSubscription['payer_user_id']][$i]['notes'] = $rowSubscription['notes'];
                $i++;
            }
        }

        if (empty($notify) && $sendPeriodStartSummaryOnly) {
            $defaultPayerUserId = array_key_first($household);
            if ($defaultPayerUserId !== null) {
                $notify[$defaultPayerUserId] = [];
            }
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
                    $message = buildNotificationMessage("", $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                    if ($message === "") {
                        continue;
                    }

                    // Built per message and re-validated at send time: a save-time
                    // check alone is bypassable via DNS rebinding between when the
                    // host was saved and when the cron fires.
                    $transport = wallos_build_mailer($emailConfig, $db);

                    if (!$transport['success']) {
                        // break leaves this recipient loop only; the run goes on
                        // to Discord and the rest, so this is a channel failing
                        // rather than the job failing. It is still the case that
                        // a payment reminder somebody asked for was not sent.
                        wallos_cron_problem('the mail transport of user ' . $userId
                            . ' is unusable, so no email notification was sent: '
                            . $transport['message']);
                        echo "Email notifications not sent: " . $transport['message'] . "<br />";
                        break;
                    }

                    $mail = $transport['mailer'];

                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    $emailaddress = !empty($user['email']) ? $user['email'] : $defaultEmail;
                    $name = !empty($user['name']) ? $user['name'] : $defaultName;

                    // PHPMailer is constructed with exceptions enabled, so
                    // addAddress, addCC and send all throw. Uncaught, the first
                    // malformed address or refused SMTP session ended the whole
                    // run — every later user, and every other channel, silently
                    // skipped. Per recipient the damage is one notification.
                    try {
                        $mail->addAddress($emailaddress, $name);

                        if (!empty($emailConfig['values']['other_emails'])) {
                            $list = explode(';', $emailConfig['values']['other_emails']);

                            // Avoid duplicate emails
                            $list = array_unique($list);
                            $list = array_filter($list, function ($value) use ($emailaddress) {
                                return $value !== $emailaddress;
                            });

                            foreach ($list as $value) {
                                $mail->addCC(trim($value));
                            }
                        }

                        $mail->Subject = 'Wallos Notification';
                        $mail->Body = $message;

                        if ($mail->send()) {
                            wallos_cron_count('sent');
                            echo "Email Notifications sent<br />";
                        } else {
                            wallos_cron_problem('an email notification was not delivered: '
                                . $mail->ErrorInfo);
                            echo "Error sending notifications: " . $mail->ErrorInfo . "<br />";
                        }
                    } catch (Exception $error) {
                        wallos_cron_problem('an email notification was not delivered: '
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
                    echo "SSRF attempt detected for Discord webhook URL. Notifications not sent.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        $title = translate('wallos_notification', $i18n);

                        $name = $user['name'] ?? "";
                        $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                        if ($message === "") {
                            continue;
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
                            wallos_cron_problem('a Discord notification was not delivered: '
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
                    echo "SSRF attempt detected for Gotify server URL. Notifications not sent.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        $name = $user['name'] ?? "";
                        $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                        if ($message === "") {
                            continue;
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
                            wallos_cron_problem('a Gotify notification was not delivered: '
                                . curl_error($ch));
                            echo "Error sending notifications: " . curl_error($ch) . "<br />";
                        } else {
                            wallos_cron_count('sent');
                            echo "Gotify Notifications sent<br />";
                        }

                        unset($ch);
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
                        $name = $user['name'];
                    } else {
                        $name = "";
                    }
                    $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                    if ($message === "") {
                        continue;
                    }

                    $data = array(
                        'chat_id' => $telegram['chatId'],
                        'text' => mb_convert_encoding($message, 'UTF-8', 'auto')
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
                        wallos_cron_problem('a Telegram notification was not delivered: '
                            . curl_error($ch));
                        echo "Error sending notifications: " . curl_error($ch) . "<br />";
                    } else {
                        wallos_cron_count('sent');
                        echo "Telegram Notifications sent<br />";
                    }

                    unset($ch);
                }
            }


            // PushPlus notifications if enabled
            if ($pushplusNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    // Build Message Content
                    $name = $user['name'] ?? "";
                    $messageContent = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                    if ($messageContent === "") {
                        continue;
                    }

                    // Prepare PushPlus Data
                    $data = array(
                        'token' => $pushplus['token'],
                        'title' => '订阅续期提醒 - Wallos',
                        'content' => mb_convert_encoding($messageContent, 'UTF-8', 'auto'),
                        'template' => 'json'
                    );

                    $data_string = json_encode($data);

                    $ch = curl_init('https://www.pushplus.plus/send');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type: application/json'
                        ),
                    );

                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($result === false) {
                        wallos_cron_problem('a PushPlus notification was not delivered: '
                            . curl_error($ch));
                        echo "Error sending PushPlus notifications: " . curl_error($ch) . "<br />";
                    } else {
                        $resultData = json_decode($result, true);
                        if (isset($resultData['code']) && $resultData['code'] == 200) {
                            wallos_cron_count('sent');
                            echo "PushPlus Notifications sent successfully<br />";
                        } else {
                            $errorMsg = isset($resultData['msg']) ? $resultData['msg'] : 'Unknown error';
                            wallos_cron_problem('PushPlus accepted the request and refused the message: '
                                . $errorMsg);
                            echo "PushPlus API error: " . $errorMsg . "<br />";
                        }
                    }
                    unset($ch);
                }
            }

            // Mattermost notifications if enabled
            if ($mattermostNotificationsEnabled) {
                $ssrf = is_url_safe_for_ssrf($mattermost['webhook_url'], $db, $userId);
                if (!$ssrf) {
                    wallos_cron_problem('the configured Mattermost URL failed the SSRF check, '
                        . 'so the whole Mattermost channel was skipped');
                    echo "SSRF attempt detected for Mattermost webhook URL. Notifications not sent.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        // Build Message Content
                        $name = $user['name'] ?? "";
                        $messageContent = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                        if ($messageContent === "") {
                            continue;
                        }
                        // Prepare Mattermost Data
                        $webhook_url = $mattermost['webhook_url'];
                        $data = array(
                            'username' => $mattermost['bot_username'],
                            'icon_emoji' => $mattermost['bot_icon_emoji'],
                            'text' => mb_convert_encoding($messageContent, 'UTF-8', 'auto'),
                        );

                        $data_string = json_encode($data);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $webhook_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt(
                            $ch,
                            CURLOPT_HTTPHEADER,
                            array(
                                'Content-Type: application/json'
                            ),
                        );
                        curl_setopt($ch, CURLOPT_RESOLVE, ["{$ssrf['host']}:{$ssrf['port']}:{$ssrf['ip']}"]);

                        $result = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                        if ($result === false) {
                            wallos_cron_problem('a Mattermost notification was not delivered: '
                                . curl_error($ch));
                            echo "Error sending Mattermost notifications: " . curl_error($ch) . "<br />";
                        } else {
                            // $httpCode, which was read on the line above and then
                            // discarded. A Mattermost incoming webhook answers the
                            // bare string "ok" with status 200 and no JSON, so the
                            // old test — a decoded code field equal to 200 — was
                            // false for every successful send, and every one of
                            // them was printed as an API error.
                            $resultData = json_decode($result, true);
                            if ($httpCode >= 200 && $httpCode < 300) {
                                wallos_cron_count('sent');
                                echo "Mattermost Notifications sent successfully<br />";
                            } else {
                                $errorMsg = isset($resultData['msg']) ? $resultData['msg'] : 'HTTP ' . $httpCode;
                                wallos_cron_problem('Mattermost refused a notification: ' . $errorMsg);
                                echo "Mattermost API error: " . $errorMsg . "<br />";
                            }
                        }
                        unset($ch);
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
                        $name = $user['name'];
                    } else {
                        $name = "";
                    }
                    $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                    if ($message === "") {
                        continue;
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
                        wallos_cron_problem('a Pushover notification was not delivered: '
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
                    echo "SSRF attempt detected for Ntfy host URL. Notifications not sent.<br />";
                } else {
                    foreach ($notify as $userId => $perUser) {
                        // Get name of user from household table
                        // $notify is keyed by household member; they are already loaded.
                        $user = $household[$userId] ?? [];

                        $name = $user['name'] ?? "";
                        $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                        if ($message === "") {
                            continue;
                        }

                        $headers = json_decode($ntfy["headers"], true);
                        $customheaders = [];

                        if (is_array($headers)) {
                            $customheaders = array_map(function ($key, $value) {
                                return "$key: $value";
                            }, array_keys($headers), $headers);
                        }

                        $ch = curl_init();

                        $ntfyHost = rtrim($ntfy["host"], '/');
                        $ntfyTopic = $ntfy['topic'];

                        curl_setopt($ch, CURLOPT_URL, $ntfyHost . '/' . $ntfyTopic);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
                        $ntfyHeaders = array_merge(['Content-Type: text/plain; charset=utf-8'], $customheaders);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $ntfyHeaders);
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
                            wallos_cron_problem('an ntfy notification was not delivered: '
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
                    echo "SSRF attempt detected for webhook URL. Notifications not sent.<br />";
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
                            $payload = $webhook['payload'];
                            $payload = str_replace("{{days_until}}", $days, $payload);
                            $payload = str_replace("{{subscription_name}}", $subscription['name'], $payload);
                            $payload = str_replace("{{subscription_price}}", $subscription['formatted_price'], $payload);
                            $payload = str_replace("{{subscription_currency}}", $subscription['currency'], $payload);
                            $payload = str_replace("{{subscription_category}}", $subscription['category'], $payload);
                            $payload = str_replace("{{subscription_payer}}", $payer, $payload); // Use $payer instead of $subscription['payer']
                            $payload = str_replace("{{subscription_date}}", $subscription['date'], $payload);
                            $payload = str_replace("{{subscription_days_until_payment}}", $subscription['days'], $payload);
                            $payload = str_replace("{{subscription_url}}", $subscription['url'], $payload);
                            $payload = str_replace("{{subscription_notes}}", $subscription['notes'], $payload);
                
                            // Initialize cURL for each subscription
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $webhook['url']);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $webhook['request_method']);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                
                            // Add headers if they exist
                            if (!empty($webhook['headers'])) {
                                $customheaders = json_decode($webhook["headers"], true);
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
                                // curl_error() is empty for a 404 or a 500, which
                                // is how this reported "Error sending
                                // notifications: " with nothing after the colon.
                                wallos_cron_problem('a webhook notification was not delivered: '
                                    . ($response === false ? curl_error($ch) : 'HTTP ' . $httpCode));
                                echo "Error sending notifications: " . curl_error($ch) . "<br />";
                            } else {
                                wallos_cron_count('sent');
                                echo "Webhook Notification sent for subscription: " . $subscription['name'] . "<br />";
                            }

                            unset($ch);
                
                            usleep(1000000); // 1s delay between requests
                        }
                    }
                }
            }

            // Serverchan notifications if enabled
            if ($serverchanNotificationsEnabled) {
                foreach ($notify as $userId => $perUser) {
                    // Get name of user from household table
                    // $notify is keyed by household member; they are already loaded.
                    $user = $household[$userId] ?? [];

                    $title = 'Wallos Notification';
                    $name = $user['name'] ?? "";
                    $message = buildNotificationMessage($name, $perUser, $periodSummaryLine, $sendPeriodStartSummaryOnly);
                    if ($message === "") {
                        continue;
                    }

                    // Build Serverchan request
                    $postdata = http_build_query(array('text' => $title, 'desp' => $message));

                    $sendkey = $serverchan['sendkey'];
                    if (strpos($sendkey, 'sctp') === 0) {
                        preg_match('/^sctp(\d+)t/', $sendkey, $matches);
                        $num = $matches[1] ?? '';
                        $url = "https://{$num}.push.ft07.com/send/{$sendkey}.send";
                    } else {
                        $url = "https://sctapi.ftqq.com/{$sendkey}.send";
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/x-www-form-urlencoded'
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($response === false || $httpCode >= 400) {
                        $errorMessage = $response === false ? curl_error($ch) : $httpCode;
                        unset($ch);
                        wallos_cron_problem('a Serverchan notification was not delivered: '
                            . $errorMessage);
                        echo "Error sending Serverchan notifications: " . $errorMessage . "<br />";
                    } else {
                        unset($ch);
                        wallos_cron_count('sent');
                        echo "Serverchan Notifications sent<br />";
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

// Reached the end of every user and every channel. Without this the run is
// reported as having stopped, which is exactly what a die() deeper down would
// leave behind and what nothing used to distinguish from a quiet night.
wallos_cron_done();
