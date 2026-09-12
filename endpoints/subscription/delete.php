<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/logo_cleanup.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];

// Which logo files this subscription names, read before its row goes. A
// prepare that fails means no sweep rather than a fatal (#87): a leftover file
// costs disk, and the deletion itself is the thing the caller asked for.
$logoStmt = $db->prepare("SELECT logo, logo_variant FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId");
$logoRow = false;

if ($logoStmt !== false) {
    $logoStmt->bindParam(':subscriptionId', $subscriptionId);
    $logoStmt->bindParam(':userId', $userId);
    $logoResult = $logoStmt->execute();
    $logoRow = $logoResult ? $logoResult->fetchArray() : false;
}

$deleteQuery = "DELETE FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId";
$deleteStmt = $db->prepare($deleteQuery);
$deleteStmt->bindParam(':subscriptionId', $subscriptionId);
$deleteStmt->bindParam(':userId', $userId);

if ($deleteStmt->execute()) {
    $query = "UPDATE subscriptions SET replacement_subscription_id = NULL WHERE replacement_subscription_id = :subscriptionId AND user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':subscriptionId', $subscriptionId);
    $stmt->bindParam(':userId', $userId);
    if ($stmt->execute() === false) {
        echo json_encode([
            "success" => false,
            "message" => translate('error_deleting_subscription', $i18n)
        ]);
    } else {
        // The rows are gone; drop the logo files nothing else names. Inside
        // the success branch on purpose — a subscription that is still there
        // must keep the image it is displayed with.
        if ($logoRow !== false) {
            deleteLogoFileIfUnused($db, $logoRow['logo'], '../../images/uploads/logos/');
            deleteLogoFileIfUnused($db, $logoRow['logo_variant'], '../../images/uploads/logos/');
        }

        echo json_encode([
            "success" => true,
            "message" => translate('subscription_deleted', $i18n)
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error_deleting_subscription', $i18n)
    ]);
}
$db->close();