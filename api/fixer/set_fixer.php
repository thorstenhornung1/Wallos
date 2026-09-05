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
require_once '../../includes/currency_provider.php';

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
    // The row is updated, never replaced, so the stored Fixer key survives.
    // Choosing a provider that needs no key must not be the way somebody
    // loses the key they will switch back to — and the settings page has
    // always behaved this way, so the two paths would otherwise give the same
    // product two answers.
    $countStmt = $db->prepare("SELECT COUNT(*) AS count FROM fixer WHERE user_id = :userId");
    $rowExists = false;

    if ($countStmt !== false) {
        // Cast rather than type-declared: both backends infer the type from
        // the PHP value, and new code has no reason to widen the SQLite
        // boundary the audit exists to shrink (#20).
        $countStmt->bindValue(':userId', (int) $userId);
        $countResult = $countStmt->execute();
        $countRow = $countResult ? $countResult->fetchArray(SQLITE3_ASSOC) : false;
        $rowExists = $countRow && $countRow['count'] > 0;
    }

    $insertStmt = $db->prepare($rowExists
        ? "UPDATE fixer SET provider = 2, provider_mode = 'custom' WHERE user_id = :userId"
        : "INSERT INTO fixer (api_key, provider, provider_mode, user_id)
           VALUES ('', 2, 'custom', :userId)");
    $stored = false;

    if ($insertStmt !== false) {
        $insertStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stored = $insertStmt->execute() !== false;
    }

    if (!$stored) {
        error_log('Wallos set_fixer: could not select Frankfurter for user '
            . $userId . ': ' . $db->lastErrorMsg());
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

// Validate the API key against the provider through the same client the
// scheduled and manual refreshes use. It makes the one request, reports whether
// it succeeded, and returns the APILayer rate-limit headers it read — the
// monthly pair and the daily one beside it — so that parsing lives in exactly
// one place and no plaintext fixer.io test URL survives here (#150). mode
// 'custom' attributes the figure to this account's own key rather than to the
// instance settings.
$config = [
    'valid' => true,
    'mode' => 'custom',
    'values' => ['provider' => $provider, 'api_key' => $fixerApiKey],
    'notes' => [],
];
$test = wallos_fetch_exchange_rates($config, 'EUR,USD');

if (!$test['success']) {
    // A quota that ran out and a provider having a bad day are not the key
    // being wrong, and telling an admin to replace a working key is worse than
    // saying nothing: they will replace it, and the new one will fail too. The
    // shared client already phrased the reason; this only sorts it into the
    // right title off the status it carried back.
    $providerFault = ($test['status'] === 429
        || ($test['status'] !== null && $test['status'] >= 500));

    echo json_encode([
        'success' => false,
        'title' => $providerFault ? 'Currency provider unavailable' : 'Invalid Fixer API key',
        'message' => $test['message'],
    ]);

    $db->close();
    exit;
}

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
    // Cast rather than type-declared: both backends infer the type from the PHP
    // value, and new code has no reason to widen the SQLite boundary the audit
    // exists to shrink (#20).
    $removeStmt->bindValue(':userId', (int) $userId);
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

// Insert new settings. provider_mode is written 'custom' so config resolution
// reads this account's own key; without it the row defaults to 'instance'
// (migration 000055) and the key just saved is stored and then ignored.
$insertSql = "INSERT INTO fixer (api_key, provider, provider_mode, user_id)
              VALUES (:api_key, :provider, 'custom', :userId)";
$stmtInsert = $db->prepare($insertSql);
$stmtInsert->bindValue(':api_key', (string) $fixerApiKey);
$stmtInsert->bindValue(':provider', (int) $provider);
$stmtInsert->bindValue(':userId', (int) $userId);
$resultInsert = $stmtInsert->execute();

if (!$resultInsert) {
    echo json_encode([
        'success' => false,
        'title' => 'Database error',
        'message' => 'Failed to save Fixer API settings.'
    ]);

    $db->close();
    exit;
}

// The verification above was a real provider request with this account's own
// key, so it counts against them — recorded now that the row exists to keep the
// figure in. An answer served from the per-run cache carries no transport and
// is not a request, so it is not counted.
if ($test['transport']) {
    wallos_count_currency_call($db, $config, $userId);
}

// The APILayer month-and-day usage the shared client read from the same
// response, stored against this account's own fixer row (mode 'custom') so the
// settings page can show it. The store keeps this path's header capture in step
// with the scheduled refresh instead of a second, month-only copy of it.
wallos_store_currency_usage($db, $config, $userId, $test['usage']);

echo json_encode([
    'success' => true,
    'title' => 'Fixer settings updated',
    'message' => 'Fixer API settings have been saved successfully.'
]);

$db->close();
?>
