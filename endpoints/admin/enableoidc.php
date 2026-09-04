<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/oidc_settings.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$oidcEnabled = isset($data['oidcEnabled']) ? $data['oidcEnabled'] : 0;

if (wallos_has_oidc_env_value('OIDC_ENABLED')) {
    die(json_encode([
        "success" => false,
        "message" => "OIDC enablement is managed by the OIDC_ENABLED environment variable."
    ]));
}

// F3: the single-user no-login mode and OIDC are mutually exclusive; the
// reverse of the guard in set_admin_settings.php. Refuse turning OIDC on
// while login_disabled is active and OIDC is configured, so the pair cannot
// both be in force. See wallos_oidc_enable_conflicts_with_login_disabled().
if (wallos_oidc_enable_conflicts_with_login_disabled(
    $db, $oidcEnabled, wallos_get_effective_oidc_configuration($db)['is_configured'])) {
    die(json_encode([
        "success" => false,
        "message" => "OIDC cannot be enabled while the single-user no-login mode is active. "
            . "Disable it first: one grants access with no authentication, the other puts "
            . "the identity provider in charge."
    ]));
}

$stmt = $db->prepare('UPDATE admin SET oidc_oauth_enabled = :oidcEnabled WHERE id = 1');
$stmt->bindParam(':oidcEnabled', $oidcEnabled, SQLITE3_INTEGER);
$stmt->execute();

if ($db->changes() > 0) {
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
} else {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}
