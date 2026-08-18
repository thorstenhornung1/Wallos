<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of an administrator.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- oidc_settings: an object containing the OIDC settings.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "oidc_settings",
  "oidc_settings": {
    "name": "Authentik",
    "client_id": "CJMLcyyS94cUMXkitNZuokayArnn23TXxpeUv48E",
    "client_secret": "SzfQBIibfN0gEAgCORrKnGnrYe9yqASWAYUuu1byelVosCHlnoqAdWlMDppblyuByb38Zw78AAlgMmdK6SWpGjOU4IiqaoltkAEh52trcqCB8briP1TqqXZdar4xfhVw",
    "authorization_url": "https://auth.bellamylab.com/application/o/authorize/",
    "token_url": "https://auth.bellamylab.com/application/o/token/",
    "user_info_url": "https://auth.bellamylab.com/application/o/userinfo/",
    "redirect_url": "http://localhost:80/wallos",
    "logout_url": "https://auth.bellamylab.com/application/o/wallos/end-session/",
    "user_identifier_field": "sub",
    "scopes": "openid email profile",
    "auth_style": "auto",
    "created_at": "2025-07-20 20:31:50",
    "updated_at": "2025-07-20 20:31:50",
    "auto_create_user": 0,
    "password_login_disabled": 0
  },
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/oidc_settings.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;

    // Resolves the key and checks the admin role in one place, shared with the
    // other admin endpoints.
    require_once __DIR__ . '/../../includes/api_admin.php';
    $user = wallos_require_admin_api_user($db, $apiKey);
    $userId = $user['id'];

    $oidcConfiguration = wallos_get_effective_oidc_configuration($db);
    $oidc_settings = $oidcConfiguration['settings'];

    // The client secret is a credential and does not leave the server. This
    // endpoint used to read oauth_settings directly, where a secret supplied
    // through OIDC_CLIENT_SECRET_FILE simply was not present; resolving the
    // effective configuration made it present, and returning the whole array
    // then handed a file-mounted secret to anyone with an admin API key.
    //
    // Presence is what a caller legitimately needs — whether it is configured,
    // and from where. diagnostics.php has always reported it this way.
    $oidc_settings['client_secret_set'] = trim((string) $oidc_settings['client_secret']) !== '';
    unset($oidc_settings['client_secret']);

    $response = [
        "success" => true,
        "title" => "oidc_settings",
        "oidc_settings" => $oidc_settings,
        "oidc_enabled" => $oidcConfiguration['enabled'],
        "managed_fields" => $oidcConfiguration['managed_fields'],
        "notes" => $oidcConfiguration['notes']
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
