<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of an administrator.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- admin_settings: an object containing the admin settings.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "admin_settings",
  "admin_settings": {
    "registrations_open": 1,
    "max_users": 100,
    "require_email_verification": 1,
    "server_url": "http://example.com",
    "smtp_address": "smtp.example.com",
    "smtp_port": 587,
    "smtp_username": "admin@example.com",
    "smtp_password": "********",
    "from_email": "no-reply@example.com",
    "encryption": "tls",
    "login_disabled": 0,
    "latest_version": "v1.0.0",
    "update_notification": 1,
    "oidc_oauth_enabled": 0,
    "local_webhook_notifications_allowlist": "localhost,127.0.0.1",
    "allow_standard_users_local_webhooks": 0
  },
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/integration_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;

    // Resolves the key and checks the admin role in one place, shared with the
    // other admin endpoints.
    require_once __DIR__ . '/../../includes/api_admin.php';
    $user = wallos_require_admin_api_user($db, $apiKey);
    $userId = $user['id'];

    $sql = "SELECT * FROM \"admin\"";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $admin_settings = $result->fetchArray(SQLITE3_ASSOC);

    if ($admin_settings) {
        unset($admin_settings['id']);
        // if the smtp_password is set, hide it
        if (isset($admin_settings['smtp_password'])) {
            $admin_settings['smtp_password'] = "********";
        }
    }

    // Effective instance integrations, reported with their source instead of
    // their secrets. The legacy fields above stay for compatibility.
    $smtpConfiguration = wallos_get_instance_smtp_config($db);
    $currencyConfiguration = wallos_get_instance_currency_config($db);
    $aiConfiguration = wallos_get_instance_ai_config($db);

    $admin_settings['smtp'] = wallos_smtp_public_payload($smtpConfiguration)
        + ['username' => $smtpConfiguration['values']['username'], 'source' => $smtpConfiguration['source']];

    $admin_settings['currency_provider'] = [
        'provider' => (int) $currencyConfiguration['values']['provider'] === 1 ? 'apilayer' : 'fixer',
        'api_key' => wallos_secret_status($currencyConfiguration, 'api_key'),
        'source' => $currencyConfiguration['source'],
        'valid' => $currencyConfiguration['valid'],
    ];

    $admin_settings['ai_provider'] = [
        'provider' => $aiConfiguration['values']['type'],
        'base_url' => $aiConfiguration['values']['url'],
        'model' => $aiConfiguration['values']['model'],
        'api_key' => wallos_secret_status($aiConfiguration, 'api_key'),
        'source' => $aiConfiguration['source'],
        'valid' => $aiConfiguration['valid'],
    ];

    $response = [
        "success" => true,
        "title" => "admin_settings",
        "admin_settings" => $admin_settings,
        "notes" => array_merge($smtpConfiguration['notes'], $currencyConfiguration['notes'], $aiConfiguration['notes'])
    ];

    echo json_encode($response);

    $db->close();

} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}

?>