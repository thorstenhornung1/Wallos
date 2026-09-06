<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$paymentMethods = $_POST['paymentMethodIds'];
$order = 1;

foreach ($paymentMethods as $paymentMethodId) {
    $sql = 'UPDATE payment_methods SET "order" = :order WHERE id = :paymentMethodId and user_id = :userId';
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':order', $order, SQLITE3_INTEGER);
    $stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    if ($stmt->execute() === false) {
        echo json_encode([
            "success" => false,
            "message" => translate("error", $i18n)
        ]);
        exit;
    }
    $order++;
}

$response = [
    "success" => true,
    "message" => translate("sort_order_saved", $i18n)
];
echo json_encode($response);

?>