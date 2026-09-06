<?php
/*
  Saving a currency API key: one provider request, and an atomic replacement
  (#142).

  The settings page validated the key with one request and then the frontend
  fired a second one at update_exchange.php to fetch the rates — two calls where
  one would do, two percent of a 100-call month spent on pressing Save. And the
  replacement deleted the old key before inserting the new one with no
  transaction, so a failure between the two left the account with no credential
  at all.

  The fix does the refresh in the same process as the validation, so the run
  cache answers it for free, and wraps the delete+insert in one transaction.

  No test here makes a request: the child defines wallos_provider_http_get()
  before the endpoint loads the client.
*/

require_once WALLOS_ROOT . '/includes/currency_provider.php';

/**
 * Invokes endpoints/currency/fixer_api_key.php as a logged-in POST, counting
 * how many times the transport was reached, with the response and the count
 * echoed for the parent to read.
 *
 * @param int    $userId
 * @param array  $post
 * @param string $body   the stubbed provider response body
 * @param string $status the stubbed HTTP status line
 * @return string merged stdout/stderr; carries "calls=N" on the success path
 */
function keysave_invoke($userId, array $post, $body, $status)
{
    $sessionDir = WALLOS_TEST_TMP . '/keysave-sess-' . uniqid('', true);
    mkdir($sessionDir, 0700, true);
    $sessionId = 'keysave' . bin2hex(random_bytes(8));

    $post['csrf_token'] = 'test-csrf-token';

    $lines = [];
    $lines[] = '$GLOBALS["transport_calls"] = 0;';
    $lines[] = 'function wallos_provider_http_get($url, $context) {';
    $lines[] = '    $GLOBALS["transport_calls"]++;';
    $lines[] = '    return ["body" => ' . var_export($body, true)
        . ', "headers" => [' . var_export($status, true) . ']];';
    $lines[] = '}';
    $lines[] = 'ini_set(' . var_export('session.save_path', true) . ', ' . var_export($sessionDir, true) . ');';
    $lines[] = 'session_id(' . var_export($sessionId, true) . ');';
    $lines[] = 'session_start();';
    $lines[] = '$_SESSION[' . var_export('loggedin', true) . '] = true;';
    $lines[] = '$_SESSION[' . var_export('userId', true) . '] = ' . (int) $userId . ';';
    $lines[] = '$_SESSION[' . var_export('username', true) . '] = ' . var_export('tester', true) . ';';
    $lines[] = '$_SESSION[' . var_export('csrf_token', true) . '] = ' . var_export('test-csrf-token', true) . ';';
    $lines[] = '$_POST = ' . var_export($post, true) . ';';
    $lines[] = '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('POST', true) . ';';
    $lines[] = '$_SERVER[' . var_export('PHP_SELF', true) . '] = ' . var_export('/endpoints/currency/fixer_api_key.php', true) . ';';
    $lines[] = 'chdir(' . var_export(WALLOS_ROOT . '/endpoints/currency', true) . ');';
    $lines[] = 'require ' . var_export('fixer_api_key.php', true) . ';';
    // Reached only on the success path, which ends in echo rather than die.
    $lines[] = 'echo "\ncalls=" . $GLOBALS["transport_calls"];';

    $script = WALLOS_TEST_TMP . '/keysave-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . implode("\n", $lines) . "\n");

    $output = [];
    $exit = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

    unlink($script);
    foreach (glob($sessionDir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($sessionDir);

    return implode("\n", $output);
}

/**
 * Stores an existing custom key for a user, the configuration a replacement
 * must protect.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $key
 */
function keysave_existing_key($db, $userId, $key)
{
    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                          VALUES (:key, 1, 'custom', :userId)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

wallos_test('saving a valid key costs exactly one provider request (#142)', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');

    $post = ['api_key' => 'the-new-key', 'provider' => '1', 'mode' => 'custom'];
    $out = keysave_invoke($userId, $post, '{"rates":{"EUR":1,"USD":1.1}}', 'HTTP/1.1 200 OK');

    assert_contains('"success":true', $out, 'the key saved (got: ' . $out . ')');
    // The validation request is the only one over the wire: the refresh that
    // follows is answered from the run cache in the same process, where before
    // the frontend spent a second request on update_exchange.php.
    assert_contains('calls=1', $out, 'exactly one provider request was made (got: ' . $out . ')');

    // The key is stored, and the rates the one request carried were written.
    assert_same('the-new-key', (string) $db->scalar('SELECT api_key FROM fixer WHERE user_id = 1'),
        'the new key is stored');
    $usd = (float) $db->scalar('SELECT rate FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'USD']);
    assert_true(abs($usd - 1.1) < 0.0001, 'the rates from that one request were written (got ' . $usd . ')');

    $db->close();
});

wallos_test('the run cache does not span processes, which is why the refresh must share the save\'s (#142)', function () {
    // Broken-to-count: the reason one request is only achievable in one process.
    // Two fetches in two processes — the shape of the old validate-then-refresh
    // frontend — each start with an empty static cache and each spend a call.
    // If this ever reported one, the single-process requirement would be moot
    // and the endpoint could safely go back to letting the frontend refresh.
    $script = WALLOS_TEST_TMP . '/keysave-two-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n"
        . '$GLOBALS["transport_calls"] = 0;' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1}}\', "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "k", "provider" => 1], "notes" => []];' . "\n"
        . '$a = wallos_fetch_exchange_rates($config, "EUR,USD");' . "\n"
        . 'echo $GLOBALS["transport_calls"];' . "\n");

    $one = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $one);
    $two = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $two);
    unlink($script);

    $total = (int) implode('', $one) + (int) implode('', $two);
    assert_same(2, $total, 'two processes cost two requests — the cache is per-process (got ' . $total . ')');
});

wallos_test('a rejected key leaves the previous configuration intact (#142)', function () {
    $db = wallos_test_open_database();
    $userId = 2;
    wallos_test_create_user($db, $userId, 'bob');
    keysave_existing_key($db, $userId, 'old-working-key');

    // The new key fails validation (the provider refuses); nothing is deleted.
    $post = ['api_key' => 'a-bad-key', 'provider' => '1', 'mode' => 'custom'];
    $out = keysave_invoke($userId, $post,
        '{"message":"invalid_access_key"}', 'HTTP/1.1 401 Unauthorized');

    assert_contains('"success":false', $out, 'the save is refused (got: ' . $out . ')');
    assert_same('old-working-key', (string) $db->scalar('SELECT api_key FROM fixer WHERE user_id = 2'),
        'the previously stored key survives a rejected replacement');

    $db->close();
});

wallos_test('an insert failure after the delete rolls back to the previous key (#142)', function () {
    // The atomicity the transaction exists for: the key validated, so the code
    // reaches the delete+insert, and the insert is made to fail. Without the
    // transaction the delete would already have destroyed the working key; with
    // it, the rollback restores exactly what was there.
    if (wallos_test_skip_unless_sqlite('the write block runs on both backends but this case reads back on SQLite')) {
        return;
    }

    $db = wallos_test_open_database();
    $userId = 6;
    wallos_test_create_user($db, $userId, 'carol');
    keysave_existing_key($db, $userId, 'old-working-key');

    // Block the INSERT the replacement makes; the DELETE before it still runs.
    wallos_test_block_writes($db, 'fixer', 'INSERT');

    $post = ['api_key' => 'a-valid-key', 'provider' => '1', 'mode' => 'custom'];
    $out = keysave_invoke($userId, $post, '{"rates":{"EUR":1,"USD":1.1}}', 'HTTP/1.1 200 OK');

    wallos_test_unblock_writes($db, 'fixer');

    assert_contains('"success":false', $out, 'the save reports the failure (got: ' . $out . ')');
    assert_same('old-working-key', (string) $db->scalar('SELECT api_key FROM fixer WHERE user_id = 6'),
        'the delete was rolled back, not left half-applied — the old key is intact');

    $db->close();
});
