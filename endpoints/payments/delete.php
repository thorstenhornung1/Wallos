<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/reference_validation.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$paymentMethodId = $data["id"] ?? null;

if (!wallos_is_integer_input($paymentMethodId)) {
    http_response_code(400);
    echo json_encode(array("success" => false, "message" => translate('error', $i18n)));
    exit();
}

$paymentMethodId = (int) $paymentMethodId;

// The three comparable endpoints count what still references the row before
// removing it — categories/category.php answers category_in_use, and household
// and currency do the same for theirs. This one asked nothing and answered
// success, leaving every subscription that used the method pointing at a row
// that no longer exists (issue #93). SQLite keeps those references
// indefinitely, because foreign keys are not enforced there; PostgreSQL
// does not, so the damage surfaces later as a migration this installation
// can no longer perform.
if (wallos_subscriptions_referencing($db, 'payment_methods', $paymentMethodId, $userId) > 0) {
    echo json_encode(array(
        "success" => false,
        "message" => translate('payment_method_in_use', $i18n)
    ));
    $db->close();
    exit();
}

$deleteQuery = "DELETE FROM payment_methods WHERE id = :paymentMethodId and user_id = :userId";
$deleteStmt = $db->prepare($deleteQuery);

if ($deleteStmt === false) {
    http_response_code(500);
    echo json_encode(array("success" => false, "message" => translate('error', $i18n)));
    $db->close();
    exit();
}

// bindValue without a type constant: the SQLite constants are what issue
// #41 is confining to the adapter, and both values are already integers.
$deleteStmt->bindValue(':paymentMethodId', $paymentMethodId);
$deleteStmt->bindValue(':userId', (int) $userId);

if ($deleteStmt->execute() === false) {
    http_response_code(500);
    echo json_encode(array("success" => false, "message" => translate('error', $i18n)));
    $db->close();
    exit();
}

// A DELETE that matched nothing is syntactically successful and semantically
// the opposite (issue #87). It means the id belongs to another account or to
// nobody — the system rows older installations carry with user_id 0 are the
// common case — and reporting that as "removed" tells the user their payment
// method is gone while it is still on the list after a reload.
if ($db->changes() === 0) {
    http_response_code(404);
    echo json_encode(array("success" => false, "message" => translate('error', $i18n)));
    $db->close();
    exit();
}

header('Content-Type: application/json');
echo json_encode(array(
    "success" => true,
    "message" => translate('payment_method_removed', $i18n)
));

$db->close();

?>
