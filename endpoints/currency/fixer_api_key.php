<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/currency_provider.php';

$newApiKey = isset($_POST["api_key"]) ? trim($_POST["api_key"]) : "";
$provider = isset($_POST["provider"]) ? $_POST["provider"] : 0;
$providerId = wallos_parse_currency_provider($provider);

// Clients that predate the instance/custom choice keep their own credentials.
// A provider that needs no key arrives with an empty one, which used to be the
// signal for "clear my credentials" — so it only means that when the chosen
// provider actually authenticates.
$keylessProvider = $providerId !== null && !wallos_currency_provider_needs_key($providerId);
$defaultMode = ($newApiKey === "" && !$keylessProvider) ? 'instance' : 'custom';
$mode = wallos_normalize_mode($_POST['mode'] ?? $defaultMode);

$stmt = $db->prepare("SELECT COUNT(*) AS count FROM fixer WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
$rowExists = $row && $row['count'] > 0;

if ($mode === 'instance') {
    $instanceConfig = wallos_get_instance_currency_config($db);

    if (!$instanceConfig['valid']) {
        die(json_encode([
            "success" => false,
            "message" => translate('instance_currency_provider_not_configured', $i18n)
        ]));
    }

    // The stored key is kept untouched so switching back does not lose it.
    $query = $rowExists
        ? "UPDATE fixer SET provider_mode = 'instance' WHERE user_id = :userId"
        : "INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES ('', 0, 'instance', :userId)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    if ($stmt->execute()) {
        die(json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]));
    }

    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

if ($keylessProvider) {
    // Frankfurter is configured by being chosen: there is no credential to
    // store and none to verify, so nothing is asked of the network here. The
    // row is updated rather than replaced precisely so the stored Fixer key
    // survives — looking at a provider that needs no key must never be the way
    // somebody loses the key they will switch back to.
    $query = $rowExists
        ? "UPDATE fixer SET provider = :provider, provider_mode = 'custom' WHERE user_id = :userId"
        : "INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES ('', :provider, 'custom', :userId)";
    $stmt = $db->prepare($query);

    if ($stmt === false) {
        die(json_encode([
            "success" => false,
            "message" => translate('failed_to_store_api_key', $i18n)
        ]));
    }

    // Cast instead of type-declared: both values are integers, both backends
    // infer that from the PHP type, and new code has no reason to widen the
    // SQLite boundary the audit exists to shrink (#20).
    $stmt->bindValue(':provider', (int) $providerId);
    $stmt->bindValue(':userId', (int) $userId);

    if ($stmt->execute() === false) {
        die(json_encode([
            "success" => false,
            "message" => translate('failed_to_store_api_key', $i18n)
        ]));
    }

    wallos_reset_config_cache($db);

    die(json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]));
}

if ($newApiKey === "") {
    // Submitting an empty key removes the stored credential, as before. This is
    // the "second DELETE FROM fixer" #142 asked about: it needs no transaction
    // because it is one statement, not the delete-then-insert pair below, so
    // there is no half-applied state for a rollback to undo. Its result is read
    // rather than discarded so a failed removal is not reported as a success.
    $stmt = $db->prepare("DELETE FROM fixer WHERE user_id = :userId");

    if ($stmt === false) {
        die(json_encode([
            "success" => false,
            "message" => translate('failed_to_store_api_key', $i18n)
        ]));
    }

    $stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);

    if ($stmt->execute() === false) {
        die(json_encode([
            "success" => false,
            "message" => translate('failed_to_store_api_key', $i18n)
        ]));
    }

    die(json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]));
}

$config = wallos_currency_config_from_input($provider, $newApiKey);

if (!$config['valid']) {
    die(json_encode([
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ]));
}

// Verified with the same client the scheduled updates use — and with the
// user's real currency list rather than a synthetic USD, so the one request it
// costs is the one the refresh below reuses from the run cache instead of
// paying for a second time (#142). It also proves the provider can price the
// currencies this account actually holds, not just that the key authenticates.
$codes = "";
$codesStmt = $db->prepare('SELECT code FROM currencies WHERE user_id = :userId');

if ($codesStmt !== false) {
    // Bare bind and bare fetch keep this new read off the SQLite boundary
    // audit (#20); both backends answer them the same.
    $codesStmt->bindValue(':userId', $userId);
    $codesResult = $codesStmt->execute();
    while ($codesResult && $codeRow = $codesResult->fetchArray()) {
        $codes .= $codeRow['code'] . ",";
    }
    $codes = rtrim($codes, ',');
}

// An account with no currencies still needs its key proved; USD is a code every
// provider prices, used only when there is nothing of the user's own to ask for.
if ($codes === "") {
    $codes = "USD";
}

$mainCurrencyCode = wallos_user_main_currency_code($db, $userId);
$requestBase = wallos_currency_request_base($config, $mainCurrencyCode);
$test = wallos_fetch_exchange_rates($config, $codes, $requestBase);

if (!$test['success']) {
    die(json_encode([
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ]));
}

// Atomic replacement (#142): the delete and the insert are one transaction, so
// an insert that fails cannot leave the account with its old key gone and no
// new one stored. The key was already validated above, so this guards a
// database failure between the two statements, not a bad key.
$db->exec('BEGIN');

$stmt = $db->prepare("DELETE FROM fixer WHERE user_id = :userId");
$removed = false;

if ($stmt !== false) {
    $stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);
    $removed = $stmt->execute() !== false;
}

$stored = false;

if ($removed) {
    $insertNewKey = "INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES (:api_key, :provider, 'custom', :userId)";
    $stmt = $db->prepare($insertNewKey);

    if ($stmt !== false) {
        $stmt->bindValue(":api_key", $config['values']['api_key'], SQLITE3_TEXT);
        $stmt->bindValue(":provider", $config['values']['provider'], SQLITE3_INTEGER);
        $stmt->bindValue(":userId", $userId, SQLITE3_INTEGER);
        $stored = $stmt->execute() !== false;
    }
}

if (!$stored) {
    // Rolled back rather than committed half-done: the previous configuration
    // is restored intact, exactly the state the user is told they are still in.
    $db->exec('ROLLBACK');

    die(json_encode([
        "success" => false,
        "message" => translate('failed_to_store_api_key', $i18n)
    ]));
}

$db->exec('COMMIT');

wallos_reset_config_cache($db);

// The refresh runs here, in this process, using the response the validation
// already paid for: the run cache in wallos_fetch_exchange_rates() answers the
// update's fetch (same credential, base and codes) with no request over the
// wire, so a saved key costs exactly one provider call and the frontend no
// longer has to trigger update_exchange.php afterwards (#142). Best effort — a
// key that stored and validated is a success even if the write of the rates it
// carried does not land; the scheduled refresh will catch up.
wallos_update_exchange_rates_for_user($db, $userId);

wallos_store_currency_usage($db, $config, $userId, $test['usage']);

// The verification above went over the wire with this key. If it was a
// direct-fixer free-tier key it will have fallen back to http, and the settings
// page must say so — recorded here so the warning is on screen the moment the
// key is saved rather than only after the first scheduled refresh (#141).
wallos_currency_record_scheme($db, $config, $test);

// The verification above was a real provider request with the user's own key,
// so it counts against them — recorded after the insert, because only now is
// there a row to keep the figure in. A key the provider rejected also cost a
// call, but its row was just deleted; that one request goes unrecorded rather
// than growing a place to store it.
if (!empty($test['transport'])) {
    wallos_count_currency_call($db, $config, $userId);
}

echo json_encode(["success" => true, "message" => translate('api_key_saved', $i18n)]);
