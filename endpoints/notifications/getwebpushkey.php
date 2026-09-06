<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_session.php';
require_once '../../includes/webpush.php';

// A read, so the session guard above (not the POST + CSRF guard) is the right
// one: the applicationServerKey is login-gated but not a state change. It runs
// before anything can produce output.
header('Content-Type: application/json');

// Resolves the instance VAPID keypair, generating it on first use. Only the
// public key is returned — the browser subscribes with it; the private key
// never leaves the server.
$config = wallos_get_instance_webpush_config($db);

echo json_encode([
    "success" => true,
    "publicKey" => (string) $config['values']['public_key'],
    "configured" => (bool) $config['values']['deliverable'],
]);
