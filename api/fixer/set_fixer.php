<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of the user (for Wallos authentication).
- fixer_api_key: the Fixer.io or APILayer API key to save (optional; if empty/omitted, clears the key).
  Not read for provider '2', which has no key.
- provider: the provider type (optional; '0' for Fixer.io, '1' for APILayer.com,
  '2' for Frankfurter.dev, defaults to '0').

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).

Example response:
{
  "success": true,
  "title": "Fixer settings updated",
  "message": "Fixer API key has been saved."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/http_status.php';

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

$sql = "SELECT * FROM \"user\" WHERE api_key = :apiKey";
$stmt = $db->prepare($sql);
$stmt->bindValue(':apiKey', $apiKey, SQLITE3_TEXT);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

if (!$user) {
    echo json_encode([
        'success' => false,
        'title' => 'Unauthorized',
        'message' => 'Invalid API key.'
    ]);
    exit;
}

$userId = $user['id'];
$fixerApiKey = isset($_POST['fixer_api_key']) ? trim($_POST['fixer_api_key']) : '';
$provider = $_POST['provider'] ?? '0';

if (!in_array($provider, ['0', '1', '2', 0, 1, 2], true)) {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid provider',
        'message' => 'Provider must be 0 (Fixer.io), 1 (APILayer.com) or 2 (Frankfurter.dev).'
    ]);
    exit;
}
$provider = intval($provider);

// Frankfurter needs no account and no key, so there is no credential to
// validate and an empty key is the whole of a configuration rather than a
// half-finished one — which is why this comes before the empty-key branch
// below, whose job is to clear a credential that does exist (#140).
//
// provider_mode is written explicitly. It defaults to 'instance' (migration
// 000055), and a row saying "use Frankfurter" under a mode that means "use
// whatever the instance is configured with" would be stored, reported as
// saved, and then ignored.
if ($provider === 2) {
    $removeStmt = $db->prepare("DELETE FROM fixer WHERE user_id = :userId");
    $removed = false;

    if ($removeStmt !== false) {
        $removeStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $removed = $removeStmt->execute() !== false;
    }

    if (!$removed) {
        error_log('Wallos set_fixer: could not remove the previous provider key for user '
            . $userId . ': ' . $db->lastErrorMsg());

        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'The previous provider key could not be replaced.',
        ]);

        $db->close();
        exit;
    }

    $insertStmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                                VALUES ('', 2, 'custom', :userId)");
    $stored = false;

    if ($insertStmt !== false) {
        $insertStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stored = $insertStmt->execute() !== false;
    }

    echo json_encode($stored
        ? [
            'success' => true,
            'title' => 'Currency provider updated',
            'message' => 'Frankfurter is selected. It needs no API key.',
        ]
        : [
            'success' => false,
            'title' => 'Database error',
            'message' => 'Failed to save the currency provider.',
        ]);

    $db->close();
    exit;
}

// If key is empty, clear the settings
if ($fixerApiKey === '') {
    $removeSql = "DELETE FROM fixer WHERE user_id = :userId";
    $removeStmt = $db->prepare($removeSql);
    $removeStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $removeResult = $removeStmt->execute();

    if ($removeResult) {
        echo json_encode([
            'success' => true,
            'title' => 'Fixer settings cleared',
            'message' => 'Fixer API key has been removed.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'Failed to remove Fixer API settings.'
        ]);
    }
    $db->close();
    exit;
}

// Validate the API key against the provider
if ($provider === 1) {
    $testKeyUrl = "https://api.apilayer.com/fixer/latest?base=USD&symbols=EUR";
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'apikey: ' . $fixerApiKey,
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($testKeyUrl, false, $context);
} else {
    $testKeyUrl = "http://data.fixer.io/api/latest?access_key=" . urlencode($fixerApiKey);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($testKeyUrl, false, $context);
}

// Populated by PHP only when a response arrived. With ignore_errors set above,
// a false response now means nothing answered at all, rather than answering no.
$status = wallos_http_status_code(isset($http_response_header) ? $http_response_header : null);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'title' => 'Validation error',
        'message' => wallos_provider_failure_message($status, null)
    ]);
    exit;
}

// Parse headers for APILayer limit info
$usageLimit = null;
$usageRemaining = null;
if ($provider === 1 && isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (stripos($header, 'x-ratelimit-limit-month:') === 0) {
            $usageLimit = (int) trim(substr($header, strlen('x-ratelimit-limit-month:')));
        } elseif (stripos($header, 'x-ratelimit-remaining-month:') === 0) {
            $usageRemaining = (int) trim(substr($header, strlen('x-ratelimit-remaining-month:')));
        }
    }
}

$apiData = json_decode($response, true);
if (isset($apiData['success']) && $apiData['success'] == true) {
    // Delete existing settings first
    //
    // Checked, because the insert that follows is: a failed delete leaves the
    // account with two provider keys and nothing saying which one is used
    // (issue #87). The insert already reported its own failure, which made this
    // the one half of the pair that could fail quietly.
    $removeSql = "DELETE FROM fixer WHERE user_id = :userId";
    $removeStmt = $db->prepare($removeSql);
    $removed = false;

    if ($removeStmt !== false) {
        $removeStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $removed = $removeStmt->execute() !== false;
    }

    if (!$removed) {
        error_log('Wallos set_fixer: could not remove the previous provider key for user '
            . $userId . ': ' . $db->lastErrorMsg());

        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'The previous provider key could not be replaced.',
        ]);

        exit;
    }

    // Insert new settings
    $insertSql = "INSERT INTO fixer (api_key, provider, user_id) VALUES (:api_key, :provider, :userId)";
    $stmtInsert = $db->prepare($insertSql);
    $stmtInsert->bindParam(':api_key', $fixerApiKey, SQLITE3_TEXT);
    $stmtInsert->bindParam(':provider', $provider, SQLITE3_INTEGER);
    $stmtInsert->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $resultInsert = $stmtInsert->execute();

    if ($resultInsert) {
        // If usage limits are parsed and supported by the db schema
        if ($usageLimit !== null && $usageRemaining !== null
            && $db->columnExists('fixer', 'usage_used') > 0) {
            $usageStmt = $db->prepare("UPDATE fixer SET usage_used = :used, usage_limit = :limit, usage_updated_at = :updatedAt WHERE user_id = :userId");
            $usageStmt->bindValue(':used', $usageLimit - $usageRemaining, SQLITE3_INTEGER);
            $usageStmt->bindValue(':limit', $usageLimit, SQLITE3_INTEGER);
            $usageStmt->bindValue(':updatedAt', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $usageStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

            // Quota is what the settings page shows to explain why refreshes
            // stopped. A figure that silently stayed where it was is worse than
            // none at all — but the key itself is saved, so this reports rather
            // than failing the request.
            if ($usageStmt->execute() === false) {
                error_log('Wallos set_fixer: the provider key was saved but its quota was not recorded for user '
                    . $userId . ': ' . $db->lastErrorMsg());
            }
        }

        echo json_encode([
            'success' => true,
            'title' => 'Fixer settings updated',
            'message' => 'Fixer API settings have been saved successfully.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'Failed to save Fixer API settings.'
        ]);
    }
} else {
    // A quota that ran out and a provider having a bad day are not the key
    // being wrong, and telling an admin to replace a working key is worse than
    // saying nothing: they will replace it, and the new one will fail too.
    $providerFault = ($status === 429 || ($status !== null && $status >= 500));

    echo json_encode([
        'success' => false,
        'title' => $providerFault ? 'Currency provider unavailable' : 'Invalid Fixer API key',
        'message' => wallos_provider_failure_message($status, $apiData)
    ]);
}

$db->close();
?>
