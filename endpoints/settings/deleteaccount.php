<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

// One deletion routine, shared with endpoints/admin/deleteuser.php. This copy
// used to miss login_tokens, so a self-deleted account left a working
// remember-me token behind.
require_once '../../includes/user_deletion.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userIdToDelete = $data['userId'];

if ($userIdToDelete == 1 || $userIdToDelete != $userId) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

$deletion = wallos_delete_user($db, $userIdToDelete);

if (!$deletion['success']) {
    // Nothing was deleted rather than some of it: the routine rolls back. The
    // account is still usable, which is what the visitor is being told.
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

die(json_encode([
    "success" => true,
    "message" => translate('success', $i18n)
]));
