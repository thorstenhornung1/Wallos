<?php
// All requests should be POST requests
// CSRF Token must be included and match the token stored on the session
// User must be logged in
//
// Each refusal sets a status code before it writes anything. Until 5.8.3 none
// of them did, so a request with no session, an expired session or a bad CSRF
// token was refused with HTTP 200 and a body explaining why (issue #97).
//
// The refusal was correct; its status code said the opposite. Anything reading
// status codes rather than parsing bodies — a reverse proxy, a monitoring
// probe, `curl -f`, a rate limiter counting 401s — was told the request had
// worked. That is the same direction of error as issue #87: the operation did
// not happen and the answer said it did.

require_once __DIR__ . '/../libs/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf_token($csrf)) {
    // 403 rather than 401: the caller may well be authenticated. What is
    // missing is proof that they meant to make this particular request.
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Invalid CSRF token"]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => translate('session_expired', $i18n)]);
    exit;
}