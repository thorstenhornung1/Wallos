<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/integration_config.php';
require_once '../../includes/user_provisioning.php';

// The same two lists registration.php and the OIDC provisioning need, from
// the one place that has them.
$currencies = wallos_default_currencies();


function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$loggedInUserId = $userId;

$email = validate($data['email']);
$username = validate($data['username']);
$password = $data['password'];

if (empty($username) || empty($password) || empty($email)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

$stmt = $db->prepare('SELECT COUNT(*) FROM "user" WHERE username = :username OR email = :email');
$stmt->bindValue(':username', $username, SQLITE3_INTEGER);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$result = $stmt->execute();
$row = $result->fetchArray();
// Error if user exist
if ($row[0] > 0) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

// Get main currency and language from admin user
$stmt = $db->prepare('SELECT main_currency, language FROM "user" WHERE id = :id');
$stmt->bindValue(':id', $loggedInUserId, SQLITE3_TEXT);
$result = $stmt->execute();
$row = $result->fetchArray();
$currency = $row['main_currency'] ?? 1;
$instanceLanguage = wallos_get_instance_language_config($db);
$language = ($instanceLanguage['source']['language'] ?? 'default') === 'default'
    ? wallos_resolve_language($row['language'] ?? null)   // inherit from the admin, as before
    : $instanceLanguage['values']['language'];
$avatar = "images/avatars/0.svg";

// Get code for main currency
$stmt = $db->prepare('SELECT code FROM currencies WHERE id = :id');
$stmt->bindValue(':id', $currency, SQLITE3_TEXT);
$row = $stmt->execute();
$main_currency = $row->fetchArray()['code'];

$query = "INSERT INTO \"user\" (username, email, password, main_currency, avatar, language, budget, api_key) VALUES (:username, :email, :password, :main_currency, :avatar, :language, :budget, :api_key)";
$stmt = $db->prepare($query);
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
$stmt->bindValue(':main_currency', 1, SQLITE3_TEXT);
$stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
$stmt->bindValue(':language', $language, SQLITE3_TEXT);
$stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
$stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {

    // Get id of the newly created user
    $newUserId = $db->lastInsertRowID();

    // Add username as household member for that user
    if (!wallos_create_household_member($db, $newUserId, $username)) {
        error_log('Wallos adduser: could not create the household member for user '
            . $newUserId . ': ' . $db->lastErrorMsg());
    }

    if ($newUserId > 1) {

        // Add categories for that user, in the language the account gets
        wallos_create_default_categories($db, $newUserId, $language);

        // Add payment methods and currencies for that user, and say so when
        // either fails: an account holding a third of its currencies is not an
        // account to report as created (issue #87).
        if (!wallos_create_default_payment_methods($db, $newUserId)) {
            error_log('Wallos adduser: could not create the default payment methods for user '
                . $newUserId . ': ' . $db->lastErrorMsg());
        }

        if (!wallos_create_default_currencies($db, $newUserId)) {
            error_log('Wallos adduser: could not create the default currencies for user '
                . $newUserId . ': ' . $db->lastErrorMsg());
        }

        // Retrieve main currency id
        $query = "SELECT id FROM currencies WHERE code = :code AND user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':code', $main_currency, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $currency = $result->fetchArray(SQLITE3_ASSOC);

        // The code came from the administrator's own main currency, which they
        // may well have renamed or added themselves, so it need not appear in
        // the fixed list the loop above inserted. Reading ['id'] off a lookup
        // that found nothing yields NULL, and main_currency is NOT NULL with a
        // foreign key on it: the new account's first currency is the fallback,
        // because an account without a main currency cannot be used at all.
        $mainCurrencyId = $currency
            ? $currency['id']
            : $db->scalar('SELECT id FROM currencies WHERE user_id = :user_id ORDER BY id LIMIT 1',
                [':user_id' => $newUserId]);

        if ($mainCurrencyId !== null) {
            // Update user main currency
            //
            // Checked for the same reason registration.php checks it: this is
            // what moves the account off the currency it was created against,
            // and failing silently leaves main_currency naming a row that
            // belongs to somebody else (#82, #93).
            $query = "UPDATE \"user\" SET main_currency = :main_currency WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $moved = false;

            if ($stmt !== false) {
                $stmt->bindValue(':main_currency', $mainCurrencyId, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
                $moved = $stmt->execute() !== false;
            }

            if (!$moved) {
                error_log('Wallos adduser: user ' . $newUserId . ' still points at the currency it was '
                    . 'created against, which belongs to another account: ' . $db->lastErrorMsg());
            }
        }

        // Add settings for that user
        $query = "INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)";
        $stmt = $db->prepare($query);
        $settingsWritten = false;

        if ($stmt !== false) {
            $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
            $settingsWritten = $stmt->execute() !== false;
        }

        if (!$settingsWritten) {
            error_log('Wallos adduser: could not create the settings row for user '
                . $newUserId . ': ' . $db->lastErrorMsg());
        }

        // If email verification is required add the user to the email_verification table
        $query = "SELECT * FROM admin";
        $stmt = $db->prepare($query);
        $result = $stmt->execute();
        $settings = $result->fetchArray(SQLITE3_ASSOC);
    }

    $db->close();

    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
}