<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/oidc_settings.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

// The interface names its fields in camelCase; the database does not. This map
// is the only place that difference exists — normalisation, validation and the
// write itself are shared with the API endpoint, so a field added to one is
// never silently unsaveable through the other.
$fieldMap = [
    'name' => 'oidcName',
    'client_id' => 'oidcClientId',
    'client_secret' => 'oidcClientSecret',
    'authorization_url' => 'oidcAuthUrl',
    'token_url' => 'oidcTokenUrl',
    'user_info_url' => 'oidcUserInfoUrl',
    'redirect_url' => 'oidcRedirectUrl',
    'logout_url' => 'oidcLogoutUrl',
    'user_identifier_field' => 'oidcUserIdentifierField',
    'scopes' => 'oidcScopes',
    'auth_style' => 'oidcAuthStyle',
    'auto_create_user' => 'oidcAutoCreateUser',
    'password_login_disabled' => 'oidcPasswordLoginDisabled',
    'require_email_verified' => 'oidcRequireEmailVerified',
    'admin_claim' => 'oidcAdminClaim',
    'admin_value' => 'oidcAdminValue',
    'post_logout_redirect_url' => 'oidcPostLogoutRedirectUrl',
    'issuer' => 'oidcIssuer',
];

$submitted = [];
foreach ($fieldMap as $field => $key) {
    if (isset($data[$key])) {
        $submitted[$field] = $data[$key];
    }
}

// Not in the map because it is not a column: the explicit request to clear the
// stored client secret. The secret field itself stays a placeholder — empty
// means "unchanged" — so switching a provider to a public client needs this
// flag. The rules live in wallos_save_oidc_settings().
if (!empty($data['oidcClearClientSecret'])) {
    $submitted['clear_client_secret'] = true;
}

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
// F3: refuse completing the OIDC configuration while it is enabled and the
// single-user no-login mode is active, since that save is the write that would
// make OIDC effective. See wallos_oidc_enable_conflicts_with_login_disabled().
if (wallos_oidc_enable_conflicts_with_login_disabled($db, $oidcConfiguration['enabled'], true)) {
    $db->close();
    die(json_encode([
        "success" => false,
        "message" => "OIDC configuration cannot be completed while the single-user "
            . "no-login mode is active. Disable it first."
    ]));
}

$result = wallos_save_oidc_settings($db, $submitted, $oidcConfiguration['managed_fields']);

$db->close();

if (!$result['success']) {
    die(json_encode([
        "success" => false,
        "message" => $result['error'] ?? translate('error', $i18n)
    ]));
}

die(json_encode([
    "success" => true,
    "message" => translate('success', $i18n)
]));
