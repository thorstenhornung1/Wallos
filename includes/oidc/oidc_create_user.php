<?php

// Try to extract first and last name from "name"
$fullName = $userInfo['name'] ?? '';
$parts = explode(' ', trim($fullName), 2);
$firstname = $parts[0] ?? '';
$lastname = $parts[1] ?? '';

// Defaults
//
// The provider's locale is used once, when the account is created. From then on
// the language belongs to the Wallos user and later logins never overwrite it.
require_once __DIR__ . '/../integration_config.php';

$language = wallos_resolve_language(
    $userInfo['locale'] ?? null,
    wallos_instance_default_language($db)
);
require_once __DIR__ . '/oidc_avatar.php';

// Default avatar. Replaced below when the provider sends an importable raster
// `picture` claim (see includes/oidc/oidc_avatar.php); a bad or absent picture
// leaves this default in place and never fails account creation.
$avatar = "images/avatars/0.svg";
$avatarImportOutcome = null;
$pictureClaim = $userInfo['picture'] ?? null;
$decodedPicture = wallos_oidc_decode_picture($pictureClaim);
if ($decodedPicture !== null) {
    $importedAvatar = wallos_oidc_write_avatar($oidcSub, $decodedPicture);
    if ($importedAvatar !== null) {
        $avatar = $importedAvatar;
        $avatarImportOutcome = 'imported (' . $decodedPicture['ext'] . ')';
    } else {
        $avatarImportOutcome = 'skipped (could not store the decoded image)';
    }
} elseif (is_string($pictureClaim) && $pictureClaim !== '') {
    $avatarImportOutcome = 'skipped (not an importable raster data-URI)';
}
$budget = 0;
$main_currency_id = 1; // Euro
$password = bin2hex(random_bytes(16)); // 32-character random password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$query = "INSERT INTO \"user\" (username, email, oidc_sub, main_currency, avatar, language, budget, firstname, lastname, password, api_key)
          VALUES (:username, :email, :oidc_sub, :main_currency, :avatar, :language, :budget, :firstname, :lastname, :password, :api_key)";
$stmt = $db->prepare($query);
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$stmt->bindValue(':oidc_sub', $oidcSub, SQLITE3_TEXT);
$stmt->bindValue(':main_currency', $main_currency_id, SQLITE3_INTEGER);
$stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
$stmt->bindValue(':language', $language, SQLITE3_TEXT);
$stmt->bindValue(':budget', $budget, SQLITE3_INTEGER);
$stmt->bindValue(':firstname', $firstname, SQLITE3_TEXT);
$stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
$stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
$stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);

if (!$stmt->execute()) {
    die("Failed to create user");
}

// Get the user data into $userData
$stmt = $db->prepare("SELECT * FROM \"user\" WHERE username = :username");
$stmt->bindValue(':username', $username, SQLITE3_TEXT);
$result = $stmt->execute();
$userData = $result->fetchArray(SQLITE3_ASSOC);
$newUserId = $userData['id'];

// Record ownership of an imported avatar now that the account has an id, and
// log the outcome the way the rest of the OIDC code does: the user id, never
// the image bytes.
if ($avatar !== "images/avatars/0.svg") {
    if (!wallos_oidc_register_avatar($db, $newUserId, $avatar)) {
        error_log('[Wallos OIDC] imported the avatar for new user ' . $newUserId
            . ' but could not record ownership');
    }
}
if ($avatarImportOutcome !== null) {
    error_log('[Wallos OIDC] profile picture ' . $avatarImportOutcome . ' for new user ' . $newUserId);
}

require_once __DIR__ . '/../user_provisioning.php';

// Household
if (!wallos_create_household_member($db, $newUserId, $username)) {
    error_log('Wallos OIDC provisioning: could not create the household member for user '
        . $newUserId . ': ' . $db->lastErrorMsg());
}

// Categories, in the language resolved for this account
wallos_create_default_categories($db, $newUserId, $language);

// Payment Methods
if (!wallos_create_default_payment_methods($db, $newUserId)) {
    error_log('Wallos OIDC provisioning: could not create the default payment methods for user '
        . $newUserId . ': ' . $db->lastErrorMsg());
}

// Currencies
if (!wallos_create_default_currencies($db, $newUserId)) {
    error_log('Wallos OIDC provisioning: could not create the default currencies for user '
        . $newUserId . ': ' . $db->lastErrorMsg());
}

// Get actual Euro currency ID
$stmt = $db->prepare("SELECT id FROM currencies WHERE code = 'EUR' AND user_id = :user_id");
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$result = $stmt->execute();
$currency = $result->fetchArray(SQLITE3_ASSOC);
if ($currency) {
    $stmt = $db->prepare("UPDATE \"user\" SET main_currency = :main_currency WHERE id = :user_id");
    $stmt->bindValue(':main_currency', $currency['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
    $stmt->execute();
}

$userData['main_currency'] = $currency['id'];

// Insert settings
$stmt = $db->prepare("INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                      VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)");
$stmt->bindValue(':user_id', $newUserId, SQLITE3_INTEGER);
$stmt->execute();

// Log the user in
require_once('oidc_login.php');