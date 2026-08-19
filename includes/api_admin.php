<?php

/**
 * Shared authorization for the admin API endpoints.
 *
 * Five endpoints each resolved the API key themselves and each ended with the
 * same `if ($user['id'] !== 1)`. Five copies of an authorization rule is five
 * chances to update four of them.
 */

require_once __DIR__ . '/user_roles.php';

/**
 * Resolve an API key to a user, and say whether that user may administer.
 *
 * Pure: it returns a verdict rather than emitting a response, so the decision
 * can be tested without a request.
 *
 * @param SQLite3     $db
 * @param string|null $apiKey
 * @return array{ok: bool, user: array|null, reason: string}
 *         reason is one of: ok, missing_key, unknown_key, not_admin
 */
function wallos_resolve_admin_api_user($db, $apiKey)
{
    if (!is_string($apiKey) || $apiKey === '') {
        return ['ok' => false, 'user' => null, 'reason' => 'missing_key'];
    }

    $stmt = $db->prepare("SELECT * FROM \"user\" WHERE api_key = :apiKey");
    if ($stmt === false) {
        return ['ok' => false, 'user' => null, 'reason' => 'unknown_key'];
    }
    $stmt->bindValue(':apiKey', $apiKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);

    if (!$user) {
        return ['ok' => false, 'user' => null, 'reason' => 'unknown_key'];
    }

    if (!wallos_user_is_admin($db, $user['id'])) {
        // The user exists and the key is valid — they are simply not an
        // administrator. The response deliberately does not distinguish this
        // from an unknown key any further than the reason code, so the API does
        // not confirm which keys are real.
        return ['ok' => false, 'user' => $user, 'reason' => 'not_admin'];
    }

    return ['ok' => true, 'user' => $user, 'reason' => 'ok'];
}

/**
 * The response body for a rejected admin API request.
 *
 * @param string $reason
 * @return array
 */
function wallos_admin_api_error($reason)
{
    switch ($reason) {
        case 'missing_key':
            return [
                'success' => false,
                'title' => 'Missing parameters',
                'message' => 'An API key is required.',
            ];
        case 'not_admin':
            return [
                'success' => false,
                'title' => 'Forbidden',
                'message' => 'This endpoint is restricted to administrators.',
            ];
        default:
            return [
                'success' => false,
                'title' => 'Unauthorized',
                'message' => 'Invalid API key.',
            ];
    }
}

/**
 * Resolve the caller or end the request.
 *
 * @param SQLite3     $db
 * @param string|null $apiKey
 * @return array the authenticated administrator
 */
function wallos_require_admin_api_user($db, $apiKey)
{
    $verdict = wallos_resolve_admin_api_user($db, $apiKey);

    if (!$verdict['ok']) {
        echo json_encode(wallos_admin_api_error($verdict['reason']));
        exit;
    }

    return $verdict['user'];
}
