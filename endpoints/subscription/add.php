<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/reference_validation.php';
require_once '../../includes/getsettings.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/logo_theme_variant.php';

if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function getLogoFromUrl($url, $uploadDir, $name, $settings, $i18n)
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!filter_var($currentUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $currentUrl)) {
            return ['success' => false, 'message' => 'Invalid URL format.'];
        }

        $parts = parse_url($currentUrl);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        
        $ip = gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false 
            || is_cgnat_ip($ip)) {
            return ['success' => false, 'message' => 'Invalid IP Address.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Manual handling to re-validate IPs
        
        curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 300 && $httpCode < 400) {
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            unset($ch);

            if (!$redirectUrl) {
                break;
            }

            $currentUrl = $redirectUrl;
            continue; 
        }

        if ($imageData !== false && $httpCode === 200) {
            $timestamp = time();
            $fileName = $timestamp . '-' . sanitizeFilename($name) . '.png';
            $uploadFile = $uploadDir . $fileName; // Note: Use the provided $uploadDir variable

            if (saveLogo($imageData, $uploadFile, $name, $settings)) {
                unset($ch);
                return ['success' => true, 'filename' => $fileName];
            }

            unset($ch);
            return ['success' => false, 'message' => translate('error_saving_logo', $i18n)];
        }

        $error = curl_error($ch);
        unset($ch);
        return ['success' => false, 'message' => translate('error_fetching_image', $i18n) . ': ' . $error];
    }

    return ['success' => false, 'message' => translate('error_fetching_image', $i18n)];
}

function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $tempFile);
        imagedestroy($image);

        $newImage = imagecreatefrompng($tempFile);
        if ($newImage !== false) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);

            if ($removeBackground) {
                require_once __DIR__ . '/../../includes/gd_background_removal.php';
                // On palette images imagecolorat() returns palette indexes, not RGB values
                if (!imageistruecolor($newImage)) {
                    imagepalettetotruecolor($newImage);
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                }
                // Paint out the corner color with ~10% fuzz
                $corner = imagecolorat($newImage, 0, 0);
                if ((($corner >> 24) & 0x7F) !== 127) {
                    gdRemoveBackgroundColor($newImage, ($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF);
                }
            }

            // Crop/trim transparent margins
            require_once __DIR__ . '/../../includes/gd_background_removal.php';
            $newImage = gdCropTransparent($newImage, 2);

            $saved = imagepng($newImage, $uploadFile);
            imagedestroy($newImage);
        } else {
            unlink($tempFile);
            return false;
        }

        unlink($tempFile);
        return $saved;
    }

    return false;
}

function resizeAndUploadLogo($uploadedFile, $uploadDir, $name, $settings)
{
    $targetWidth = 135;
    $targetHeight = 42;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                return "";
            }

            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            // Crop/trim transparent margins (ensure we update dimensions after cropping)
            require_once __DIR__ . '/../../includes/gd_background_removal.php';
            $image = gdCropTransparent($image, 2);
            $width = imagesx($image);
            $height = imagesy($image);

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int) $targetWidth;
                $newHeight = (int) (($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int) (($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int) $targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                $saved = imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $saved = imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                $saved = imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                $saved = imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);

            if (!$saved) {
                if (file_exists($uploadFile)) {
                    unlink($uploadFile);
                }
                return "";
            }

            return $fileName;
        }
    }

    return "";
}

$isEdit = isset($_POST['id']) && $_POST['id'] != "";
$name = validate($_POST["name"]);
$price = $_POST['price'];
$nextPayment = $_POST["next_payment"];
$autoRenew = isset($_POST['auto_renew']) ? true : false;
$startDate = $_POST["start_date"];
$notes = validate($_POST["notes"]);
$url = validate($_POST['url']);
$logoUrl = validate($_POST['logo-url']);
$logo = "";
$logoError = "";
$notify = isset($_POST['notifications']) ? true : false;
$notifyDaysBefore = $_POST['notify_days_before'];
$inactive = isset($_POST['inactive']) ? true : false;
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];

// Every id this form submits is a reference into the caller's own rows, and
// until issue #82 nothing here checked that: $_POST went straight into the
// insert. A tampered form could name another account's category, payment
// method or household member, and the row was written pointing at it. SQLite
// never noticed, and the guarded deletes elsewhere count referencing
// subscriptions for the owner only, so a category a stranger's subscription
// points at looks unused and can be deleted. The check runs here, before the
// logo is fetched, so an invalid request costs no outbound request.
$references = wallos_validate_subscription_input($db, $userId, $_POST);

if (!$references['valid']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Error',
        'field' => $references['field'],
        'message' => translate($references['translation'], $i18n)
    ]);
    exit();
}

$currencyId = $references['values']['currency_id'];
$frequency = $references['values']['frequency'];
$cycle = $references['values']['cycle'];
$paymentMethodId = $references['values']['payment_method_id'];
$payerUserId = $references['values']['payer_user_id'];
$categoryId = $references['values']['category_id'];

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

if ($replacementSubscriptionId !== null) {
    $ownerCheck = $db->prepare("SELECT id FROM subscriptions WHERE id = :id AND user_id = :userId");
    $ownerCheck->bindParam(':id', $replacementSubscriptionId, SQLITE3_INTEGER);
    $ownerCheck->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $ownerResult = $ownerCheck->execute();
    if (!$ownerResult || !$ownerResult->fetchArray()) {
        $replacementSubscriptionId = null;
    }
}

if ($logoUrl !== "") {
    $result = getLogoFromUrl($logoUrl, '../../images/uploads/logos/', $name, $settings, $i18n);
    if ($result['success']) {
        $logo = $result['filename'];
    } else {
        $logoError = $result['message'];
    }
} else {
    if (!empty($_FILES['logo']['name'])) {
        $fileType = mime_content_type($_FILES['logo']['tmp_name']);
        if (strpos($fileType, 'image') === false) {
            echo translate("fill_all_fields", $i18n);
            exit();
        }
        $logo = resizeAndUploadLogo($_FILES['logo'], '../../images/uploads/logos/', $name, $settings);
        if ($logo === "") {
            $logoError = translate('error_saving_logo', $i18n);
        }
    }
}

$logoTextColor = null;
$logoVariant = null;
$removeBackgroundEnabled = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

// Themed variant generation piggybacks on the same "remove background"
// setting: both only make sense for logos we're already reprocessing, and
// this avoids running pixel classification on every single upload.
if ($logo !== "" && $removeBackgroundEnabled) {
    $logoExtension = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
    $logoPath = '../../images/uploads/logos/' . $logo;

    if ($logoExtension === 'png' || $logoExtension === 'webp') {
        $sourceImage = $logoExtension === 'png' ? imagecreatefrompng($logoPath) : imagecreatefromwebp($logoPath);

        if ($sourceImage !== false) {
            imagealphablending($sourceImage, false);
            imagesavealpha($sourceImage, true);

            $logoTextColor = classifyLogoTextColor($sourceImage);

            if ($logoTextColor !== null) {
                $variantImage = generateThemedLogoVariant($sourceImage);
                $logoVariant = pathinfo($logo, PATHINFO_FILENAME) . '-variant.png';
                imagepng($variantImage, '../../images/uploads/logos/' . $logoVariant);
                imagedestroy($variantImage);
            }

            imagedestroy($sourceImage);
        }
    }
}

if (!$isEdit) {
    $sql = "INSERT INTO subscriptions (
                        name, logo, price, currency_id, next_payment, cycle, frequency, notes,
                        payment_method_id, payer_user_id, category_id, notify, inactive, url,
                        notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                        auto_renew, start_date, logo_text_color, logo_variant
                    ) VALUES (
                        :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes,
                        :paymentMethodId, :payerUserId, :categoryId, :notify, :inactive, :url,
                        :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                        :autoRenew, :startDate, :logoTextColor, :logoVariant
                    )";
} else {
    $id = $_POST['id'];
    $sql = "UPDATE subscriptions SET 
                        name = :name, 
                        price = :price, 
                        currency_id = :currencyId,
                        next_payment = :nextPayment, 
                        auto_renew = :autoRenew,
                        start_date = :startDate,
                        cycle = :cycle, 
                        frequency = :frequency, 
                        notes = :notes, 
                        payment_method_id = :paymentMethodId,
                        payer_user_id = :payerUserId, 
                        category_id = :categoryId, 
                        notify = :notify, 
                        inactive = :inactive, 
                        url = :url, 
                        notify_days_before = :notifyDaysBefore, 
                        cancellation_date = :cancellationDate, 
                        replacement_subscription_id = :replacement_subscription_id";

    if ($logo != "") {
        $sql .= ", logo = :logo, logo_text_color = :logoTextColor, logo_variant = :logoVariant";
    }

    $sql .= " WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
if ($logo != "") {
    $stmt->bindParam(':logo', $logo, SQLITE3_TEXT);
    $stmt->bindParam(':logoTextColor', $logoTextColor, SQLITE3_TEXT);
    $stmt->bindParam(':logoVariant', $logoVariant, SQLITE3_TEXT);
} elseif (!$isEdit) {
    // The INSERT names these three placeholders whether or not a logo
    // arrived, and PostgreSQL counts: an unbound named parameter is a quiet
    // NULL on SQLite and a refused statement there — the UI cannot create a
    // subscription without a logo on that backend (#115). Bound explicitly,
    // the row stores the same NULLs the unbound parameters used to produce.
    // The UPDATE branch needs nothing: its column list already carries the
    // same condition as these binds.
    $stmt->bindValue(':logo', null, SQLITE3_NULL);
    $stmt->bindValue(':logoTextColor', null, SQLITE3_NULL);
    $stmt->bindValue(':logoVariant', null, SQLITE3_NULL);
}
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':nextPayment', $nextPayment, SQLITE3_TEXT);
$stmt->bindParam(':autoRenew', $autoRenew, SQLITE3_INTEGER);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
$stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindParam(':inactive', $inactive, SQLITE3_INTEGER);
$stmt->bindParam(':url', $url, SQLITE3_TEXT);
$stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
$stmt->bindParam(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    $success['status'] = "Success";
    $text = $isEdit ? "updated" : "added";
    $success['message'] = translate('subscription_' . $text . '_successfuly', $i18n);
    if ($logoError !== "") {
        $success['logo_warning'] = $logoError;
    }
    header('Content-Type: application/json');
    echo json_encode($success);
    exit();
} else {
    echo translate('error', $i18n) . ": " . $db->lastErrorMsg();
}
$db->close();
?>