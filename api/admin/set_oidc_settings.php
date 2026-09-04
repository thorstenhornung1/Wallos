<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of an administrator.
- oidc_enabled: (optional) '1' to enable OIDC logins, '0' to disable.
- name: (optional) provider name.
- client_id: (optional) OAuth client ID.
- client_secret: (optional) OAuth client secret. An empty value means
  "unchanged"; to actually remove a stored secret, send clear_client_secret.
- clear_client_secret: (optional) '1' to clear the stored client secret, for a
  provider switched to a public client. Contradicts a non-empty client_secret
  in the same request, which is refused.
- authorization_url: (optional) authorization endpoint.
- token_url: (optional) token endpoint.
- user_info_url: (optional) userinfo endpoint.
- redirect_url: (optional) callback/redirect URL.
- logout_url: (optional) logout/end-session URL.
- admin_claim: (optional) claim naming the groups/roles the provider sends.
- admin_value: (optional) value within that claim which grants the admin role.
  Both are needed for admin mapping to run; either one empty turns it off.
- user_identifier_field: (optional) field identifier (e.g. sub).
- scopes: (optional) scope list.
- auth_style: (optional) authentication style (auto/header/params).
- auto_create_user: (optional) '1' to auto-register new OIDC users, '0' otherwise.
- password_login_disabled: (optional) '1' to disable password logins, '0' otherwise.
- require_email_verified: (optional) '1' to reject unverified emails, '0' otherwise.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).

Example response:
{
  "success": true,
  "title": "OIDC settings saved",
  "message": "OIDC configurations have been saved successfully."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/oidc_settings.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid request method',
        'message' => 'Only POST requests are allowed.'
    ]);
    exit;
}

$apiKey = $_POST['api_key'] ?? $_POST['apiKey'] ?? null;

// Authenticate user first
if (!$apiKey) {
    echo json_encode([
        'success' => false,
        'title' => 'Missing API key',
        'message' => 'API key is required.'
    ]);
    exit;
}

// Resolves the key and checks the admin role in one place, shared with the
// other admin endpoints.
require_once __DIR__ . '/../../includes/api_admin.php';
$user = wallos_require_admin_api_user($db, $apiKey);
$userId = $user['id'];

// 1. Handle OIDC Enabled Toggle
if (isset($_POST['oidc_enabled'])) {
    if (wallos_has_oidc_env_value('OIDC_ENABLED')) {
        echo json_encode([
            'success' => false,
            'title' => 'Environment override',
            'message' => 'OIDC enablement is managed by the OIDC_ENABLED environment variable.'
        ]);
        exit;
    }

    $oidcEnabled = ($_POST['oidc_enabled'] === '1' || $_POST['oidc_enabled'] === 1) ? 1 : 0;

    // F3: the single-user no-login mode and OIDC are mutually exclusive; the
    // reverse of the guard in set_admin_settings.php. Refuse turning OIDC on
    // while login_disabled is active and OIDC is configured — the pair would
    // then both be in force, one granting access with no authentication and the
    // other putting the identity provider in charge.
    if (wallos_oidc_enable_conflicts_with_login_disabled(
        $db, $oidcEnabled, wallos_get_effective_oidc_configuration($db)['is_configured'])) {
        echo json_encode([
            'success' => false,
            'title' => 'Validation error',
            'message' => 'OIDC cannot be enabled while the single-user no-login mode is active. '
                . 'Disable it first: one grants access with no authentication, the other puts the '
                . 'identity provider in charge.'
        ]);
        exit;
    }

    $stmtEnabled = $db->prepare('UPDATE admin SET oidc_oauth_enabled = :oidcEnabled WHERE id = 1');
    $stmtEnabled->bindParam(':oidcEnabled', $oidcEnabled, SQLITE3_INTEGER);
    $stmtEnabled->execute();
}

// 2. Handle OIDC detailed configurations
//
// The API names fields as the database does, so no mapping is needed here. The
// normalisation, the SSRF checks and the write itself are shared with the admin
// interface's endpoint, which is what keeps the two from drifting apart the way
// they had: this path used to store values untrimmed, so a client id pasted
// with a trailing space failed every later handshake as "invalid client".
$oidcConfiguration = wallos_get_effective_oidc_configuration($db);

// F3: refuse completing the OIDC configuration while it is enabled and the
// single-user no-login mode is active — that save is the write that would make
// OIDC effective. The toggle above already blocks the other order; this blocks
// finishing the configuration when the toggle is already on. See
// wallos_oidc_enable_conflicts_with_login_disabled().
if (wallos_oidc_enable_conflicts_with_login_disabled($db, $oidcConfiguration['enabled'], true)) {
    echo json_encode([
        'success' => false,
        'title' => 'Validation error',
        'message' => 'OIDC configuration cannot be completed while the single-user no-login mode '
            . 'is active. Disable it first: one grants access with no authentication, the other '
            . 'puts the identity provider in charge.'
    ]);
    exit;
}

$submitted = [];
foreach (array_keys(wallos_oidc_writable_fields()) as $field) {
    if (isset($_POST[$field])) {
        $submitted[$field] = $_POST[$field];
    }
}

// Not a writable column: the explicit request to clear the stored secret,
// since an empty client_secret means "unchanged" on both save paths.
if (isset($_POST['clear_client_secret'])) {
    $submitted['clear_client_secret'] =
        $_POST['clear_client_secret'] === '1' || $_POST['clear_client_secret'] === 1;
}

$result = wallos_save_oidc_settings($db, $submitted, $oidcConfiguration['managed_fields']);

if (!$result['success']) {
    // Named for what it is: 'Database error' used to label every refusal,
    // including requests the writer rejected on purpose — the 2026-08-30 QA
    // round read "Database error" over a deliberate contradiction refusal
    // (finding 3, docs/test-results-2026-08-30-local.md).
    $title = 'Database error';
    if (strpos((string) $result['error'], 'Security Error') === 0) {
        $title = 'Security Error';
    } elseif (strpos((string) $result['error'], 'submitted together') !== false) {
        $title = 'Invalid request';
    }

    echo json_encode([
        'success' => false,
        'title' => $title,
        'message' => $result['error']
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'title' => 'OIDC settings saved',
    'message' => 'OIDC configurations have been saved successfully.'
]);

$db->close();
?>
