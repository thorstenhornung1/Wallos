<?php

/**
 * OIDC back-channel logout endpoint.
 *
 * The provider posts a signed logout_token here to end a session without the
 * browser being involved — central logout, an administrator terminating the SSO
 * session, an account being disabled.
 *
 * Unauthenticated by design: the signature is the authentication. So the
 * responses say as little as possible, because this endpoint is reachable by
 * anyone and must not become a way to ask questions about the installation.
 *
 * It never deletes a user or any user-owned data. An identity disappearing at
 * the provider is not permission to destroy subscriptions or financial history.
 */

require_once 'includes/connect.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/oidc/backchannel.php';

header('Content-Type: application/json; charset=UTF-8');
// Required by the specification: the result must not be cached anywhere.
header('Cache-Control: no-store');

$fail = function ($status) use ($db) {
    // Deliberately without a reason. A caller who cannot produce a valid token
    // learns only that it was not accepted; the specific validation that failed
    // is in the server log, for the operator.
    http_response_code($status);
    echo json_encode(['error' => 'invalid_request']);
    $db->close();
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405);
}

$logoutToken = $_POST['logout_token'] ?? null;
if (!is_string($logoutToken) || $logoutToken === '') {
    $fail(400);
}

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
if ($oidcConfiguration['enabled'] !== 1 || !$oidcConfiguration['is_configured']) {
    $fail(400);
}

$settings = $oidcConfiguration['settings'];
$discovery = $oidcConfiguration['discovery_document'];

$jwksUri = is_array($discovery) ? ($discovery['jwks_uri'] ?? '') : '';
$jwks = wallos_oidc_fetch_jwks($jwksUri);
if ($jwks === null) {
    // Without the provider's keys nothing can be verified, and accepting the
    // token anyway would defeat the entire point of signing it.
    error_log('Wallos OIDC back-channel logout: signing keys are unavailable.');
    $fail(400);
}

$issuer = is_array($discovery) && !empty($discovery['issuer'])
    ? $discovery['issuer']
    : rtrim(trim((string) wallos_env('OIDC_ISSUER')), '/');

$verdict = wallos_oidc_validate_logout_token(
    $logoutToken,
    $jwks,
    ['issuer' => $issuer, 'audience' => $settings['client_id']],
    time()
);

if (!$verdict['valid']) {
    error_log('Wallos OIDC back-channel logout rejected: ' . $verdict['error']);
    $fail(400);
}

$revoked = wallos_oidc_revoke_sessions($db, $verdict['sub'], $verdict['sid']);

// A token that identifies no current session is still a valid token, and the
// specification asks for success: the desired state — that session is not
// signed in — already holds.
error_log('Wallos OIDC back-channel logout: ' . $revoked . ' session(s) revoked.');

http_response_code(200);
echo json_encode(['revoked' => $revoked]);
$db->close();
