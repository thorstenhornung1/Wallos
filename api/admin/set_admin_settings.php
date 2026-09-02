<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of an administrator.
- registrations_open: (optional) '1' or '0' (allow new signups).
- max_users: (optional) maximum allowed users (integer).
- require_email_verification: (optional) '1' or '0'.
- server_url: (optional) url of this wallos instance.
- smtp_address: (optional) SMTP server address.
- smtp_port: (optional) SMTP port (integer).
- smtp_username: (optional) SMTP login username.
- smtp_password: (optional) SMTP login password.
- from_email: (optional) outgoing email address.
- encryption: (optional) 'tls' or 'ssl'.
- login_disabled: (optional) '1' or '0' (disable standard login).
- update_notification: (optional) '1' or '0' (check for wallos updates).
- oidc_oauth_enabled: (optional) '1' or '0' (enable OIDC login).
- local_webhook_notifications_allowlist: (optional) comma-separated IP/hosts allowlist.
- allow_standard_users_local_webhooks: (optional) '1' or '0' (let standard users target allowlisted internal addresses).

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).

Example response:
{
  "success": true,
  "title": "Admin settings saved",
  "message": "Global admin settings have been updated successfully."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/oidc_settings.php';
require_once '../../includes/integration_config.php';

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

// Fetch current admin settings
$adminSql = "SELECT * FROM 'admin' WHERE id = 1";
$adminResult = $db->query($adminSql);
$adminSettings = $adminResult->fetchArray(SQLITE3_ASSOC);

if (!$adminSettings) {
    echo json_encode([
        'success' => false,
        'title' => 'Configuration error',
        'message' => 'Settings row not found in the database.'
    ]);
    exit;
}

// Validation & Checks
$registrations_open = isset($_POST['registrations_open']) ? intval($_POST['registrations_open']) : intval($adminSettings['registrations_open']);
$login_disabled = isset($_POST['login_disabled']) ? intval($_POST['login_disabled']) : intval($adminSettings['login_disabled']);

if ($login_disabled === 1) {
    if ($registrations_open === 1) {
        echo json_encode([
            'success' => false,
            'title' => 'Validation error',
            'message' => 'Registrations cannot be open if password login is disabled.'
        ]);
        exit;
    }

    $userCount = $db->querySingle("SELECT COUNT(*) FROM \"user\"");
    if ($userCount > 1) {
        echo json_encode([
            'success' => false,
            'title' => 'Validation error',
            'message' => 'Password login cannot be disabled if there is more than one user.'
        ]);
        exit;
    }
}

$require_email_verification = isset($_POST['require_email_verification']) ? intval($_POST['require_email_verification']) : intval($adminSettings['require_email_verification']);
$server_url = isset($_POST['server_url']) ? trim($_POST['server_url']) : $adminSettings['server_url'];

if ($require_email_verification === 1 && empty($server_url)) {
    echo json_encode([
        'success' => false,
        'title' => 'Validation error',
        'message' => 'Email verification requires a server URL.'
    ]);
    exit;
}

// SMTP checks. Environment managed fields keep their environment value, so the
// submitted value is neither validated nor stored for them.
$smtpConfiguration = wallos_get_instance_smtp_config($db);

$smtp_address = empty($smtpConfiguration['managed']['host'])
    ? ($_POST['smtp_address'] ?? $adminSettings['smtp_address'])
    : $smtpConfiguration['values']['host'];
$smtp_port = empty($smtpConfiguration['managed']['port'])
    ? ($_POST['smtp_port'] ?? $adminSettings['smtp_port'])
    : $smtpConfiguration['values']['port'];

if (!empty($smtp_address) && !empty($smtp_port)) {
    $smtp_port_int = intval($smtp_port);
    if ($smtp_port_int < 1 || $smtp_port_int > 65535) {
        echo json_encode([
            'success' => false,
            'title' => 'Validation error',
            'message' => 'SMTP port must be a valid number between 1 and 65535.'
        ]);
        exit;
    }

    if (!validate_smtp_host($smtp_address, $smtp_port_int, $db)) {
        echo json_encode([
            'success' => false,
            'title' => 'Security Block',
            'message' => 'Security Error: SMTP host must not target link-local or loopback addresses.'
        ]);
        exit;
    }
}

// Build Update Query
$fields = [];
$params = [];

// The map says which POST keys may be written and whether each is a number.
// It used to say so in the file-backed backend's type constants, which issue
// #41 keeps inside the adapter; the tag is the endpoint's own vocabulary now,
// and the value is cast before it is bound, so no type has to travel with it.
$columnsMap = [
    'registrations_open' => 'int',
    'max_users' => 'int',
    'require_email_verification' => 'int',
    'server_url' => 'text',
    'smtp_address' => 'text',
    'smtp_port' => 'int',
    'smtp_username' => 'text',
    'smtp_password' => 'text',
    'from_email' => 'text',
    'smtp_from_name' => 'text',
    'encryption' => 'text',
    'login_disabled' => 'int',
    'update_notification' => 'int',
    'oidc_oauth_enabled' => 'int',
    'local_webhook_notifications_allowlist' => 'text',
    'allow_standard_users_local_webhooks' => 'int'
];

if (wallos_get_effective_ssrf_allowlist($db)['is_managed']) {
    unset($columnsMap['local_webhook_notifications_allowlist']);
}

if (isset(wallos_get_effective_oidc_configuration($db)['managed_fields']['enabled'])) {
    unset($columnsMap['oidc_oauth_enabled']);
}

$managedSmtpColumns = [
    'host' => 'smtp_address',
    'port' => 'smtp_port',
    'encryption' => 'encryption',
    'username' => 'smtp_username',
    'password' => 'smtp_password',
    'from_email' => 'from_email',
    'from_name' => 'smtp_from_name',
];

foreach ($managedSmtpColumns as $field => $column) {
    if (!empty($smtpConfiguration['managed'][$field])) {
        unset($columnsMap[$column]);
    }
}

foreach ($columnsMap as $postKey => $dataType) {
    if (isset($_POST[$postKey])) {
        $fields[] = "$postKey = :$postKey";
        $params[$postKey] = $dataType === 'int'
            ? intval($_POST[$postKey])
            : $_POST[$postKey];
    }
}

if (!empty($fields)) {
    $sqlUpdate = "UPDATE admin SET " . implode(', ', $fields) . " WHERE id = 1";
    $stmtUpdate = $db->prepare($sqlUpdate);
    foreach ($params as $key => $value) {
        $stmtUpdate->bindValue(':' . $key, $value);
    }
    $resultUpdate = $stmtUpdate->execute();

    if (!$resultUpdate) {
        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'Failed to save admin settings.'
        ]);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'title' => 'Admin settings saved',
    'message' => 'Global admin settings have been updated successfully.'
]);

$db->close();
?>
