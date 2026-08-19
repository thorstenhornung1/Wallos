<?php

/**
 * Builds a deliberately awkward Wallos instance.
 *
 *   php dev/stress-seed.php [users] [subscriptions-per-user]
 *
 * The point is not volume — dev/seed.php already does volume. The point is
 * coverage and nastiness: every table populated, every notification channel
 * configured, every subscription state represented, and values chosen to break
 * things that only break on real data.
 *
 * That last part is what a migration between two databases actually needs.
 * Row counts prove nothing if every row is 'seed-1'. These rows contain
 * apostrophes, backslashes, emoji, CJK text, right-to-left text, newlines,
 * NULLs where they are allowed, empty strings where they are not the same as
 * NULL, prices at both ends of the range, and dates far outside the plausible.
 *
 * Everything it writes is prefixed `stress-` and removed by --clean.
 */

require_once __DIR__ . '/../includes/database/connection.php';

$users = isset($argv[1]) ? max(1, (int) $argv[1]) : 12;
$perUser = isset($argv[2]) ? max(1, (int) $argv[2]) : 25;
$clean = in_array('--clean', $argv, true);

$db = wallos_database_connect();
$driver = $db->driver();

/**
 * Values that survive a round trip only if quoting, encoding and NULL handling
 * are all correct. Each one has broken a real migration somewhere.
 */
function stress_awkward_strings()
{
    return [
        "O'Brien's \"Streaming\" Service",       // both quote characters
        'back\\slash and %percent% and _under_',  // escape and LIKE metacharacters
        "line one\nline two\ttabbed",             // control characters
        'Ünïcödé Ströme mit Ümläuten',            // Latin-1 supplement
        '日本語のサブスクリプション',                    // CJK
        'خدمة الاشتراك',                          // right-to-left
        'emoji 🎬📺🎵 in the middle',              // outside the BMP
        '   leading and trailing   ',             // whitespace that trim() eats
        str_repeat('very long name ', 40),        // past any sensible column width
        '',                                       // empty, not NULL
        'NULL',                                   // the word, not the value
        '0',                                      // falsy string
        '2026-02-30',                             // a date that does not exist
        '-1',                                     // negative where positive is meant
        '<script>alert(1)</script>',              // HTML that must stay text
        'SELECT * FROM "user"; DROP TABLE user;--',  // SQL that must stay text
    ];
}

/**
 * @param SQLite3|WallosDatabase $db
 * @param string                 $sql
 * @param array                  $values
 * @return bool
 */
function stress_insert($db, $sql, $values)
{
    $statement = $db->prepare($sql);
    if ($statement === false) {
        fwrite(STDERR, "  ! prepare failed: " . substr($sql, 0, 70) . "\n");
        return false;
    }

    foreach ($values as $name => $value) {
        $statement->bindValue($name, $value);
    }

    return $statement->execute() !== false;
}

/**
 * Tables Wallos writes one row per user into, with the columns that exist.
 *
 * Kept as data rather than forty hand-written inserts so that a table added
 * later shows up as missing coverage rather than silently going untested.
 *
 * @return array
 */
function stress_per_user_rows($index, $awkward)
{
    $pick = $awkward[$index % count($awkward)];
    $enabled = $index % 3 === 0 ? 0 : 1;

    return [
        'settings' => [
            'dark_theme' => $index % 3, 'monthly_price' => $index % 2, 'convert_currency' => $index % 2,
            'remove_background' => $index % 2, 'color_theme' => ['blue', 'green', 'red', 'yellow'][$index % 4],
            'hide_disabled' => $index % 2, 'disabled_to_bottom' => $index % 2,
            'show_original_price' => $index % 2, 'mobile_nav' => $index % 2, 'week_starts_sunday' => $index % 2,
        ],
        'custom_colors' => [
            'main_color' => '#1a2b3c', 'accent_color' => '#4d5e6f', 'hover_color' => '#708192',
        ],
        'custom_css_style' => [
            'css' => "/* stress */ body { content: \"" . addslashes($pick) . "\"; }",
        ],
        'notification_settings' => [
            'days' => ($index % 30) + 1, 'period_summary_at_period_start' => $index % 2,
        ],
        'email_notifications' => [
            'enabled' => $enabled, 'smtp_address' => 'smtp.example.com', 'smtp_port' => 587,
            'smtp_username' => 'user' . $index, 'smtp_password' => 'pw' . $index,
            'from_email' => 'from' . $index . '@example.com', 'encryption' => 'tls',
        ],
        'telegram_notifications' => [
            'enabled' => $enabled, 'bot_token' => 'token-' . $index, 'chat_id' => (string) (100000 + $index),
        ],
        'webhook_notifications' => [
            'enabled' => $enabled, 'headers' => '{"X-Test":"' . $index . '"}',
            'url' => 'https://hooks.example.com/' . $index, 'request_method' => 'POST',
            'payload' => '{"text":"' . $index . '"}', 'ignore_ssl' => $index % 2,
            'cancelation_payload' => '{"text":"cancelled"}',
        ],
        'gotify_notifications' => [
            'enabled' => $enabled, 'url' => 'https://gotify.example.com', 'token' => 'g-' . $index,
            'ignore_ssl' => $index % 2,
        ],
        'pushover_notifications' => [
            'enabled' => $enabled, 'user_key' => 'uk-' . $index, 'token' => 'pt-' . $index,
        ],
        'ntfy_notifications' => [
            'enabled' => $enabled, 'host' => 'https://ntfy.sh', 'topic' => 'wallos-' . $index,
            'headers' => '{}', 'ignore_ssl' => $index % 2,
        ],
        'discord_notifications' => [
            'enabled' => $enabled, 'webhook_url' => 'https://discord.example.com/' . $index,
            'bot_username' => 'Wallos', 'bot_avatar_url' => '',
        ],
        'mattermost_notifications' => [
            'enabled' => $enabled, 'webhook_url' => 'https://mm.example.com/' . $index,
            'bot_username' => 'Wallos', 'bot_icon_emoji' => ':moneybag:',
        ],
        'pushplus_notifications' => ['enabled' => $enabled, 'token' => 'pp-' . $index],
        'serverchan_notifications' => ['enabled' => $enabled, 'sendkey' => 'sc-' . $index],
    ];
}

// ---------------------------------------------------------------------- clean

if ($clean) {
    $db->exec("DELETE FROM subscriptions WHERE name LIKE 'stress-%'");
    $db->exec("DELETE FROM \"user\" WHERE username LIKE 'stress-%'");
    foreach (['settings', 'custom_colors', 'custom_css_style', 'notification_settings',
              'email_notifications', 'telegram_notifications', 'webhook_notifications',
              'gotify_notifications', 'pushover_notifications', 'ntfy_notifications',
              'discord_notifications', 'mattermost_notifications', 'pushplus_notifications',
              'serverchan_notifications', 'categories', 'currencies', 'payment_methods',
              'household', 'user_roles', 'totp', 'login_tokens', 'oidc_sessions',
              'password_resets', 'email_verification', 'ai_recommendations', 'ai_settings',
              'fixer', 'google_search', 'last_exchange_update', 'total_yearly_cost',
              'uploaded_avatars'] as $table) {
        $db->exec("DELETE FROM $table WHERE user_id NOT IN (SELECT id FROM \"user\")");
    }
    echo "stress data removed\n";
    $db->close();
    exit(0);
}

// ----------------------------------------------------------------------- seed

$awkward = stress_awkward_strings();
$cycles = [1, 2, 3, 4];      // daily, weekly, monthly, yearly
$frequencies = [1, 2, 3, 6, 12];

printf("Seeding %d users x %d subscriptions on %s\n", $users, $perUser, $driver);

$db->beginTransaction();
$created = [];

for ($u = 1; $u <= $users; $u++) {
    $username = 'stress-user-' . $u;
    $awkwardName = $awkward[$u % count($awkward)];

    stress_insert($db, 'INSERT INTO "user" (username, email, password, main_currency, avatar, language, budget, firstname, lastname, api_key)
        VALUES (:username, :email, :password, 1, :avatar, :language, :budget, :firstname, :lastname, :apiKey)', [
        ':username' => $username,
        ':email' => 'stress' . $u . '@example.com',
        ':password' => password_hash('StressPass' . $u . '!', PASSWORD_DEFAULT),
        ':avatar' => 'images/avatars/0.svg',
        ':language' => ['en', 'de', 'pt-BR', 'zh-CN', 'ar', 'sr-Latn'][$u % 6],
        ':budget' => $u % 4 === 0 ? 0 : $u * 100,
        ':firstname' => $awkwardName,
        ':lastname' => $awkward[($u + 3) % count($awkward)],
        ':apiKey' => bin2hex(random_bytes(32)),
    ]);

    $userId = (int) $db->lastInsertRowID();
    $created[] = $userId;

    // Currencies: one main plus a few, with rates spanning the plausible range.
    $currencyIds = [];
    foreach ([['EUR', 'Euro', '€', 1.0], ['USD', 'US Dollar', '$', 1.0842],
              ['JPY', 'Japanese Yen', '¥', 163.77], ['KWD', 'Kuwaiti Dinar', 'د.ك', 0.3321]] as $c) {
        stress_insert($db, 'INSERT INTO currencies (name, symbol, code, rate, user_id)
            VALUES (:name, :symbol, :code, :rate, :userId)',
            [':name' => $c[1], ':symbol' => $c[2], ':code' => $c[0], ':rate' => $c[3], ':userId' => $userId]);
        $currencyIds[] = (int) $db->lastInsertRowID();
    }
    $db->exec('UPDATE "user" SET main_currency = ' . $currencyIds[0] . ' WHERE id = ' . $userId);

    // Categories, including ones named awkwardly.
    $categoryIds = [];
    foreach (['Streaming', $awkwardName, 'Utilities', 'Software'] as $order => $name) {
        stress_insert($db, 'INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :userId)',
            [':name' => $name, ':order' => $order, ':userId' => $userId]);
        $categoryIds[] = (int) $db->lastInsertRowID();
    }

    // Payment methods, some disabled.
    $paymentIds = [];
    foreach (['Visa', 'PayPal', $awkward[($u + 7) % count($awkward)]] as $order => $name) {
        stress_insert($db, 'INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
            VALUES (:name, :icon, :enabled, :order, :userId)',
            [':name' => $name, ':icon' => 'images/uploads/icons/visa.png',
             ':enabled' => $order === 2 ? 0 : 1, ':order' => $order, ':userId' => $userId]);
        $paymentIds[] = (int) $db->lastInsertRowID();
    }

    // Household members, some with an email and some without.
    $householdIds = [];
    foreach ([$username, $awkwardName, 'Kind ' . $u] as $i => $name) {
        stress_insert($db, 'INSERT INTO household (name, email, user_id) VALUES (:name, :email, :userId)',
            [':name' => $name, ':email' => $i === 2 ? '' : 'member' . $i . '-' . $u . '@example.com',
             ':userId' => $userId]);
        $householdIds[] = (int) $db->lastInsertRowID();
    }

    // One row in each per-user table.
    foreach (stress_per_user_rows($u, $awkward) as $table => $columns) {
        $names = array_keys($columns);
        $placeholders = array_map(fn($n) => ':' . $n, $names);
        $values = [];
        foreach ($columns as $name => $value) {
            $values[':' . $name] = $value;
        }
        $values[':user_id'] = $userId;

        stress_insert($db,
            'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ', user_id) VALUES ('
            . implode(', ', $placeholders) . ', :user_id)', $values);
    }

    // Security and session state.
    if ($u % 3 === 0) {
        stress_insert($db, 'INSERT INTO totp (user_id, totp_secret, backup_codes, failed_attempts)
            VALUES (:userId, :secret, :codes, :attempts)',
            [':userId' => $userId, ':secret' => 'JBSWY3DPEHPK3PXP',
             ':codes' => json_encode(['11111111', '22222222']), ':attempts' => 0]);
    }
    stress_insert($db, 'INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)',
        [':userId' => $userId, ':token' => bin2hex(random_bytes(32))]);
    if ($u % 4 === 0) {
        stress_insert($db, 'INSERT INTO oidc_sessions (user_id, sid, session_id, login_token)
            VALUES (:userId, :sid, :sessionId, :token)',
            [':userId' => $userId, ':sid' => 'sid-' . $u, ':sessionId' => 'php-' . $u, ':token' => 'tok-' . $u]);
    }
    if ($u % 5 === 0) {
        stress_insert($db, 'INSERT INTO password_resets (user_id, email, token, email_sent)
            VALUES (:userId, :email, :token, :sent)',
            [':userId' => $userId, ':email' => 'stress' . $u . '@example.com',
             ':token' => bin2hex(random_bytes(16)), ':sent' => 1]);
    }
    if ($u % 6 === 0) {
        stress_insert($db, 'INSERT INTO email_verification (user_id, email, token, email_sent)
            VALUES (:userId, :email, :token, :sent)',
            [':userId' => $userId, ':email' => 'stress' . $u . '@example.com',
             ':token' => bin2hex(random_bytes(16)), ':sent' => 0]);
    }

    // Roles: a local admin, an OIDC admin, and one holding both.
    if ($u === 1) {
        stress_insert($db, "INSERT INTO user_roles (user_id, role, source) VALUES (:userId, 'admin', 'local')",
            [':userId' => $userId]);
    } elseif ($u === 2) {
        stress_insert($db, "INSERT INTO user_roles (user_id, role, source) VALUES (:userId, 'admin', 'oidc')",
            [':userId' => $userId]);
    } elseif ($u === 3) {
        stress_insert($db, "INSERT INTO user_roles (user_id, role, source) VALUES (:userId, 'admin', 'local')",
            [':userId' => $userId]);
        stress_insert($db, "INSERT INTO user_roles (user_id, role, source) VALUES (:userId, 'admin', 'oidc')",
            [':userId' => $userId]);
    }

    // Integrations. fixer.provider is the numeric provider selector — 0 is
    // Fixer.io and 1 is APILayer — which is what every write in the application
    // binds and what the column is declared as. Seeding the provider's name
    // here instead put text in an INTEGER column, which SQLite accepts silently
    // and PostgreSQL refuses, so the fixture broke the migration it exists to
    // exercise. provider_mode below is the one that takes a word.
    stress_insert($db, 'INSERT INTO fixer (api_key, provider, user_id, usage_used, usage_limit, provider_mode)
        VALUES (:key, :provider, :userId, :used, :limit, :mode)',
        [':key' => 'fixer-' . $u, ':provider' => $u % 2, ':userId' => $userId,
         ':used' => $u * 3, ':limit' => 1000, ':mode' => 'custom']);
    if ($u % 2 === 0) {
        stress_insert($db, 'INSERT INTO google_search (user_id, api_key) VALUES (:userId, :key)',
            [':userId' => $userId, ':key' => 'gs-' . $u]);
    }
    stress_insert($db, 'INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url)
        VALUES (:userId, :type, :enabled, :key, :model, :url)',
        [':userId' => $userId, ':type' => 'chatgpt', ':enabled' => $u % 2,
         ':key' => 'ai-' . $u, ':model' => 'gpt-4o-mini', ':url' => '']);
    stress_insert($db, 'INSERT INTO ai_recommendations (user_id, type, title, description, savings)
        VALUES (:userId, :type, :title, :description, :savings)',
        [':userId' => $userId, ':type' => 'duplicate', ':title' => $awkwardName,
         ':description' => str_repeat('recommendation text ', 20), ':savings' => 12.34]);

    stress_insert($db, 'INSERT INTO last_exchange_update (date, user_id) VALUES (:date, :userId)',
        [':date' => date('Y-m-d'), ':userId' => $userId]);
    stress_insert($db, 'INSERT INTO total_yearly_cost (user_id, date, cost, currency)
        VALUES (:userId, :date, :cost, :currency)',
        [':userId' => $userId, ':date' => date('Y-m-d'), ':cost' => 1234.56, ':currency' => 'EUR']);
    if ($u % 7 === 0) {
        stress_insert($db, 'INSERT INTO uploaded_avatars (user_id, path) VALUES (:userId, :path)',
            [':userId' => $userId, ':path' => 'images/uploads/avatars/stress-' . $u . '.png']);
    }

    // Subscriptions across every state that renders differently.
    for ($s = 1; $s <= $perUser; $s++) {
        $inactive = $s % 9 === 0 ? 1 : 0;
        $cancelled = $s % 11 === 0;
        $price = [0.01, 9.99, 19.999, 1234.56, 99999.99][$s % 5];

        stress_insert($db, 'INSERT INTO subscriptions
            (name, logo, price, currency_id, next_payment, cycle, frequency, notes, payer_user_id,
             category_id, payment_method_id, notify, inactive, url, notify_days_before, user_id,
             cancellation_date, start_date, auto_renew, logo_variant)
            VALUES (:name, :logo, :price, :currency, :next, :cycle, :frequency, :notes, :payer,
             :category, :payment, :notify, :inactive, :url, :notifyDays, :userId, :cancel, :start, :autoRenew, :variant)', [
            ':name' => 'stress-' . $u . '-' . $s . ' ' . $awkward[$s % count($awkward)],
            ':logo' => $s % 4 === 0 ? '' : 'images/uploads/logos/logo.png',
            ':price' => $price,
            ':currency' => $currencyIds[$s % count($currencyIds)],
            ':next' => date('Y-m-d', strtotime('+' . (($s * 3) % 400) . ' days')),
            ':cycle' => $cycles[$s % count($cycles)],
            ':frequency' => $frequencies[$s % count($frequencies)],
            ':notes' => $s % 3 === 0 ? $awkward[($s + 5) % count($awkward)] : '',
            ':payer' => $householdIds[$s % count($householdIds)],
            ':category' => $categoryIds[$s % count($categoryIds)],
            ':payment' => $paymentIds[$s % count($paymentIds)],
            ':notify' => $s % 2,
            ':inactive' => $inactive,
            ':url' => $s % 5 === 0 ? 'https://example.com/' . $s : '',
            ':notifyDays' => ($s % 14) + 1,
            ':userId' => $userId,
            ':cancel' => $cancelled ? date('Y-m-d', strtotime('+30 days')) : null,
            ':start' => $s % 6 === 0 ? date('Y-m-d', strtotime('-' . ($s * 30) . ' days')) : null,
            ':autoRenew' => $cancelled ? 0 : 1,
            ':variant' => ['', 'light', 'dark'][$s % 3],
        ]);
    }
}

$db->commit();

printf("Created %d users, %d subscriptions\n", count($created), count($created) * $perUser);
printf("Run dev/stress-verify.php to record a fingerprint of this data.\n");
$db->close();
