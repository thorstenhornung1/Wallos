<?php
require_once __DIR__ . '/validate_endpoint.php';
require_once __DIR__ . '/user_roles.php';
// Check that the user administers this installation. The role is a row, not an
// id: being the first account in the database is not an authorization decision.
if (!wallos_user_is_admin($db, $userId)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}