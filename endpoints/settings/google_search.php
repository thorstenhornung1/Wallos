<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

if (!function_exists('wallos_serpapi_key_is_valid')) {
    /**
     * Checks a candidate SerpAPI key against the account endpoint (which does
     * not spend a search). Defined behind a guard so a test can pre-define a
     * stub before this endpoint loads — the same seam the currency provider's
     * transport uses (see tests/cases/currency_key_save_test.php).
     *
     * @param string $apiKey the candidate key to validate
     * @return bool true only when the account endpoint accepts the key
     */
    function wallos_serpapi_key_is_valid($apiKey)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://serpapi.com/account?api_key=' . urlencode($apiKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Wallos');
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        $apiData = json_decode($response ?: '', true);

        return $status === 200 && !isset($apiData['error']);
    }
}

$apiKey = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';

// This delete carries both paths: it clears the key when the field is empty,
// and it makes room for the insert further down when it is not. Only the
// insert was checked. So a failed delete either reports a key as cleared while
// it is still there and still being spent, or leaves the old key beside the new
// one for the reader to pick between.
$removeOldCredentials = "DELETE FROM google_search WHERE user_id = :userId";
$removeStmt = $db->prepare($removeOldCredentials);
$removeStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

// An empty field clears the key and disables the Google section.
if ($apiKey === '') {
    if ($removeStmt->execute() === false) {
        die(json_encode([
            "success" => false,
            "message" => translate('error', $i18n)
        ]));
    }
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
}

// Validate the candidate before touching the stored credential. A rejected key
// must not erase the working one that is already configured (#142 shape).
if (!wallos_serpapi_key_is_valid($apiKey)) {
    die(json_encode([
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ]));
}

// Atomic replacement (#142 shape, 5.15.0 QA #3): the delete and the insert are
// one transaction, so an insert that fails cannot leave the account with its
// old key gone and no new one stored. The candidate was already validated
// above, so this guards a database failure between the two statements, not a
// bad key.
$db->exec('BEGIN');

if ($removeStmt->execute() === false) {
    // Rolled back rather than left half-done: the working key stays in place.
    $db->exec('ROLLBACK');

    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

$insertCredentials = "INSERT INTO google_search (api_key, user_id) VALUES (:api_key, :userId)";
$stmt = $db->prepare($insertCredentials);
$stmt->bindParam(':api_key', $apiKey, SQLITE3_TEXT);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute() === false) {
    // The delete above is undone with it, restoring the previous credential.
    $db->exec('ROLLBACK');

    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

$db->exec('COMMIT');

die(json_encode([
    "success" => true,
    "message" => translate('api_key_saved', $i18n)
]));
