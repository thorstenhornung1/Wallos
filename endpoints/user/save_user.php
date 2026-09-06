<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/reference_validation.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/oidc_settings.php';
require_once '../../includes/oidc/oidc_profile_sync.php';
require_once '../../includes/currency_provider.php';

if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

$demoMode = getenv('DEMO_MODE');

$query = "SELECT main_currency FROM \"user\" WHERE id = :userId";
$stmt = $db->prepare($query);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$mainCurrencyId = $row['main_currency'];

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'jtif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function resizeAndUploadAvatar($uploadedFile, $uploadDir, $name)
{
    $targetWidth = 80;
    $targetHeight = 80;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-avatars-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            // Load the image based on its format
            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                // Handle other image formats as needed
                return "";
            }

            // Enable alpha channel (transparency) for PNG images
            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int)$targetWidth;
                $newHeight = (int)(($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int)(($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int)$targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);
            return "images/uploads/logos/avatars/" . $fileName;
        }
    }

    return "";
}

if (
    isset($_SESSION['username']) 
    && isset($_POST['firstname'])
    && isset($_POST['lastname'])
    && isset($_POST['email']) && $_POST['email'] !== ""
    && isset($_POST['avatar']) && $_POST['avatar'] !== ""
    && isset($_POST['main_currency']) && $_POST['main_currency'] !== ""
    && isset($_POST['language']) && $_POST['language'] !== ""
) {

    $firstname = validate($_POST['firstname']);
    $lastname = validate($_POST['lastname']);
    $email = validate($_POST['email']);

    $query = "SELECT firstname, lastname, email, language, oidc_sub FROM \"user\" WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    $oldEmail = $user['email'];

    // OIDC governs a linked account's profile fields centrally (#154):
    // profile.php shows firstname/lastname/email/language read-only for a
    // linked user and the login re-asserts the IdP claims. A crafted POST could
    // still bypass the client-side readonly and change one of them until the
    // next login (#156), so for a linked user on an OIDC-effective instance the
    // stored value wins for every field in the SAME managed set profile.php
    // locks -- the submitted value is ignored, silently, without ever failing
    // the save. Non-managed fields and local users are untouched. The managed
    // set is resolved exactly as profile.php resolves it, reusing the one
    // helper so the server lock matches the UI lock field-for-field.
    $oidcConfiguration = wallos_get_effective_oidc_configuration($db);
    $oidcSettings = $oidcConfiguration['settings'];
    $oidcLinked = trim((string) ($user['oidc_sub'] ?? '')) !== '';
    $oidcEffective = (int) $oidcConfiguration['enabled'] === 1 && $oidcConfiguration['is_configured'];
    $providerManagedFields = ($oidcEffective && $oidcLinked)
        ? wallos_oidc_managed_profile_fields($oidcSettings)
        : [];

    // Applied before the e-mail uniqueness check below so a tampered address
    // reverts to the stored one and never trips a false email_exists.
    if (in_array('firstname', $providerManagedFields, true)) {
        $firstname = (string) ($user['firstname'] ?? '');
    }
    if (in_array('lastname', $providerManagedFields, true)) {
        $lastname = (string) ($user['lastname'] ?? '');
    }
    if (in_array('email', $providerManagedFields, true)) {
        $email = (string) ($user['email'] ?? '');
    }

    if ($oldEmail != $email) {
        $query = "SELECT email FROM \"user\" WHERE email = :email AND id != :userId";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $otherUser = $result->fetchArray(SQLITE3_ASSOC);

        if ($otherUser) {
            $response = [
                "success" => false,
                "message" => translate('email_exists', $i18n)
            ];
            echo json_encode($response);
            exit();
        }
    }

    $avatar = filter_var($_POST['avatar'], FILTER_SANITIZE_URL);
    // main_currency is a foreign key into the caller's own currency rows, and
    // the only thing asked of it was that the field was not empty. "abc"
    // reached the statement layer as 0, which PostgreSQL rejects with a
    // constraint name and SQLite stores as an id no currency has, and another
    // account's currency id passed unnoticed on both.
    if (!wallos_is_integer_input($_POST['main_currency'])
        || !wallos_reference_is_owned($db, 'currencies', (int) $_POST['main_currency'], $userId)) {
        $response = [
            "success" => false,
            "message" => translate('invalid_currency', $i18n)
        ];
        echo json_encode($response);
        exit();
    }

    $main_currency = (int) $_POST['main_currency'];
    $language = wallos_resolve_language($_POST['language'] ?? null);
    if (in_array('language', $providerManagedFields, true)) {
        $language = (string) ($user['language'] ?? '');
    }

    if (!empty($_FILES['profile_pic']["name"])) {
        $file = $_FILES['profile_pic'];

        $fileType = mime_content_type($_FILES['profile_pic']['tmp_name']);
        if (strpos($fileType, 'image') === false) {
            $response = [
                "success" => false,
                "message" => translate('fill_all_fields', $i18n)
            ];
            echo json_encode($response);
            exit();
        }
        $name = $file['name'];
        $avatar = resizeAndUploadAvatar($_FILES['profile_pic'], '../../images/uploads/logos/avatars/', $name);

        if ($avatar !== "") {
            // The uploaded file is already on disk; this row is what makes it
            // the account's own. Migration 000044 added the table to stop one
            // account deleting another's avatar, so a row that never appears
            // means an uploaded file nobody owns — and the avatar list never
            // offers it back (issue #87).
            $stmt = $db->prepare("INSERT INTO uploaded_avatars (user_id, path) VALUES (:userId, :path)");
            $recorded = false;

            if ($stmt !== false) {
                $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                $stmt->bindParam(':path', $avatar, SQLITE3_TEXT);
                $recorded = $stmt->execute() !== false;
            }

            if (!$recorded) {
                error_log('Wallos save_user: stored the avatar at ' . $avatar . ' for user ' . $userId
                    . ' but could not record it: ' . $db->lastErrorMsg());
            }
        }
    }

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $password = $_POST['password'];
        if (isset($_POST['confirm_password'])) {
            $confirm = $_POST['confirm_password'];
            if ($password != $confirm) {
                $response = [
                    "success" => false,
                    "message" => translate('passwords_dont_match', $i18n)
                ];
                echo json_encode($response);
                exit();
            }
        } else {
            $response = [
                "success" => false,
                "message" => translate('passwords_dont_match', $i18n)
            ];
            echo json_encode($response);
            exit();
        }
    }

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $sql = "UPDATE \"user\" SET avatar = :avatar, firstname = :firstname, lastname = :lastname, email = :email, password = :password, main_currency = :main_currency, language = :language WHERE id = :userId";
    } else {
        $sql = "UPDATE \"user\" SET avatar = :avatar, firstname = :firstname, lastname = :lastname, email = :email, main_currency = :main_currency, language = :language WHERE id = :userId";
    }

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':avatar', $avatar, SQLITE3_TEXT);
    $stmt->bindParam(':firstname', $firstname, SQLITE3_TEXT);
    $stmt->bindParam(':lastname', $lastname, SQLITE3_TEXT);
    $stmt->bindParam(':email', $email, SQLITE3_TEXT);
    $stmt->bindParam(':main_currency', $main_currency, SQLITE3_INTEGER);
    $stmt->bindParam(':language', $language, SQLITE3_TEXT);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

    if (isset($_POST['password']) && $_POST['password'] != "" && !$demoMode) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bindParam(':password', $hashedPassword, SQLITE3_TEXT);
    }

    $result = $stmt->execute();

    if ($result) {
        $cookieExpire = time() + (30 * 24 * 60 * 60);
        $oldLanguage = isset($_COOKIE['language']) ? $_COOKIE['language'] : "en";
        $root = str_replace('/endpoints/user', '', dirname($_SERVER['PHP_SELF']));
        $root = $root == '' ? '/' : $root;
        setcookie('language', $language, [
            'path' => $root,
            'expires' => $cookieExpire,
            'samesite' => 'Lax'
        ]);
        $_SESSION['firstname'] = $firstname;
        $_SESSION['avatar'] = $avatar;
        $_SESSION['main_currency'] = $main_currency;

        $reload = $oldLanguage != $language;

        // Changing the main currency moves the base every stored rate is
        // relative to, so the rates have to be refetched against the new one.
        // Routed onto the shared client (#143): its answer is read rather than
        // discarded, its writes are one transaction, its guard validates the
        // response before touching it, and it asks only for this user's codes —
        // none of which the old in-file copy did, so a refusal used to be
        // reported as "user details saved" over rates still in the old base.
        // The old main-currency code is handed across so a currency the new
        // base cannot re-price is blanked rather than left stale (#149).
        $rateOutcome = ['success' => true, 'message' => ''];

        if ($main_currency != $mainCurrencyId) {
            $previousMainCurrencyCode = null;
            $prevStmt = $db->prepare('SELECT code FROM currencies WHERE id = :id AND user_id = :userId');

            if ($prevStmt !== false) {
                $prevStmt->bindValue(':id', $mainCurrencyId, SQLITE3_INTEGER);
                $prevStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                $prevResult = $prevStmt->execute();
                $prevRow = $prevResult ? $prevResult->fetchArray(SQLITE3_ASSOC) : false;
                $previousMainCurrencyCode = $prevRow ? $prevRow['code'] : null;
            }

            $rateOutcome = wallos_update_exchange_rates_for_user($db, $userId, $previousMainCurrencyCode);
        }

        if ($rateOutcome['success']) {
            $response = [
                "success" => true,
                "message" => translate('user_details_saved', $i18n),
                "reload" => $reload
            ];
        } else {
            // The user row was saved, but no rate was converted to the new base.
            // Reporting success here is the defect #143 is about, so the outcome
            // is a failure carrying the provider's own words rather than a fixed
            // "saved" string over totals that are now wrong by the cross rate.
            $response = [
                "success" => false,
                "message" => $rateOutcome['message'] !== ''
                    ? $rateOutcome['message']
                    : translate('error_updating_user_data', $i18n),
                "reload" => $reload
            ];
        }
        echo json_encode($response);
    } else {
        $response = [
            "success" => false,
            "message" => translate('error_updating_user_data', $i18n)
        ];
        echo json_encode($response);
    }

    exit();
} else {
    $response = [
        "success" => false,
        "message" => translate('fill_all_fields', $i18n)
    ];
    echo json_encode($response);
    exit();
}
