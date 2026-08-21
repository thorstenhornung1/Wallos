<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userIdToDelete = $data['userId'];

// Deleting the last administrator would leave nobody able to administer the
// installation. That, not a particular id, is what the previous guard on the
// first account was really protecting.
require_once '../../includes/user_roles.php';

// One deletion routine, shared with endpoints/settings/deleteaccount.php. The
// hundred lines of DELETE statements that used to live here were a transcription
// of the ones that live there, and the two copies had already drifted apart.
require_once '../../includes/user_deletion.php';

if (wallos_is_last_admin($db, $userIdToDelete)) {
    // Named, because this refusal is one the administrator can act on: give
    // somebody else the role first. "Error" here is indistinguishable from a
    // database failure they can do nothing about (issue #100).
    http_response_code(409);
    die(json_encode([
        "success" => false,
        "message" => translate('cannot_delete_last_admin', $i18n)
    ]));
}

$deletion = wallos_delete_user($db, $userIdToDelete);

if (!$deletion['success']) {
    // The routine rolls its transaction back before answering false, so this
    // reports an account that is still entirely there rather than a half
    // dismantled one. The reason is in the container log; the administrator
    // gets the translated message, not the name of a constraint.
    // The reason is in the container log; the administrator gets the
    // translated message rather than the name of a constraint. Generic on
    // purpose: there is nothing they can do differently.
    http_response_code(500);
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

die(json_encode([
    "success" => true,
    "message" => translate('success', $i18n)
]));
