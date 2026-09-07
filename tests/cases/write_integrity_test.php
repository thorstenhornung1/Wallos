<?php
/*
  Write integrity (#87 / #137 / #139): a write whose failure was reported as
  success.

  Fifteen sites answered "success" after a write whose result nobody looked at
  (#137); the currency ones were closed in #17, and this batch closes the rest.
  Each case here drives the real endpoint as its own process, makes the exact
  write fail with wallos_test_block_writes() — which refuses the statement on
  BOTH backends, where a SQLite-only RAISE(ABORT) would have left the PostgreSQL
  path untested — and asserts the endpoint now answers success:false.

  Broken-to-count: every one of these cases fails against the pre-fix code,
  because the endpoint answered success:true over the blocked write. That is the
  defect, reproduced; the fix is what turns the case green. Reverting any single
  write check turns its case red again while the DELETE/INSERT still "works".

  The child inherits this process's environment, so it opens the same database
  the fixture built: WALLOS_DB_PATH on SQLite, the per-case schema in PGOPTIONS
  on PostgreSQL. No fixed session id is used, so #148 does not apply.
*/

require_once WALLOS_ROOT . '/includes/user_roles.php';

/**
 * Runs a PHP snippet as its own process, inheriting this process's environment.
 *
 * @param string $body PHP without the opening tag.
 * @return string combined stdout/stderr
 */
function wi_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/wi-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    // The child must open the same database this case built: WALLOS_DB_PATH on
    // SQLite, the per-case schema in PGOPTIONS on PostgreSQL. getenv() carries
    // the whole environment (including PATH); the DB set is layered on top so a
    // putenv() the fixture made is certain to reach the child.
    $environment = getenv();
    foreach (wallos_test_subprocess_env() as $name => $value) {
        $environment[$name] = $value;
    }

    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, WALLOS_ROOT, $environment);

    if (!is_resource($process)) {
        @unlink($script);

        return 'could not start php';
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($script);

    return $stdout . "\n" . $stderr;
}

/**
 * The PHP that makes file_get_contents('php://input') return $body in a CLI
 * process, where the request body is otherwise empty. The endpoints under test
 * read their JSON from php://input, and only php://input — no other php:// stream
 * is opened by them or by the includes they load — so replacing the wrapper
 * wholesale is safe here.
 *
 * @param string $body
 * @return string
 */
function wi_input_wrapper($body)
{
    return 'class WiInput {'
        . ' public $context; public static $data = ""; private $pos = 0;'
        . ' public function stream_open($p,$m,$o,&$op){ $this->pos = 0; return true; }'
        . ' public function stream_read($n){ $r = substr(self::$data,$this->pos,$n); $this->pos += strlen($r); return $r; }'
        . ' public function stream_write($d){ return 0; }'
        . ' public function stream_eof(){ return $this->pos >= strlen(self::$data); }'
        . ' public function stream_tell(){ return $this->pos; }'
        . ' public function stream_seek($o,$w=SEEK_SET){ return false; }'
        . ' public function stream_stat(){ return array(); }'
        . ' public function stream_set_option($o,$a,$w){ return false; }'
        . ' public function stream_close(){ return true; }'
        . ' public function url_stat($p,$f){ return array(); }'
        . " }\n"
        . "stream_wrapper_unregister('php');\n"
        . "stream_wrapper_register('php','WiInput');\n"
        . 'WiInput::$data = ' . var_export($body, true) . ";\n";
}

/**
 * Drives one endpoint by absolute path as its own process and returns what it
 * printed.
 *
 * @param string      $endpoint absolute path to the endpoint script
 * @param int|null    $userId   a logged-in session for this user; null for an
 *                              API-key endpoint that authenticates from $_POST
 * @param array       $post     the POST bag (csrf_token is added for a session)
 * @param string|null $body     a php://input body, or null to leave it empty
 * @return string
 */
function wi_invoke($endpoint, $userId, array $post, $body = null, $preamble = null)
{
    $lines = [];

    if ($preamble !== null) {
        // Runs in the child before the endpoint loads — used to pre-define a
        // guarded seam (e.g. wallos_serpapi_key_is_valid) so an outbound call
        // is answered without touching the network.
        $lines[] = $preamble;
    }

    if ($body !== null) {
        $lines[] = wi_input_wrapper($body);
    }

    $sessionDir = null;
    if ($userId !== null) {
        $sessionDir = WALLOS_TEST_TMP . '/wi-sess-' . uniqid('', true);
        mkdir($sessionDir, 0700, true);
        $sessionId = 'wi' . bin2hex(random_bytes(8));
        $post['csrf_token'] = 'wi-csrf-token';

        $lines[] = 'ini_set(' . var_export('session.save_path', true) . ', ' . var_export($sessionDir, true) . ');';
        $lines[] = 'session_id(' . var_export($sessionId, true) . ');';
        $lines[] = 'session_start();';
        $lines[] = '$_SESSION[' . var_export('loggedin', true) . '] = true;';
        $lines[] = '$_SESSION[' . var_export('userId', true) . '] = ' . (int) $userId . ';';
        $lines[] = '$_SESSION[' . var_export('username', true) . '] = ' . var_export('tester', true) . ';';
        $lines[] = '$_SESSION[' . var_export('csrf_token', true) . '] = ' . var_export('wi-csrf-token', true) . ';';
    }

    $lines[] = '$_POST = ' . var_export($post, true) . ';';
    $lines[] = '$_REQUEST = ' . var_export($post, true) . ';';
    $lines[] = '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('POST', true) . ';';
    $lines[] = 'chdir(' . var_export(dirname($endpoint), true) . ');';
    $lines[] = 'require ' . var_export($endpoint, true) . ';';

    $output = wi_run_php(implode("\n", $lines));

    if ($sessionDir !== null) {
        foreach (glob($sessionDir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($sessionDir);
    }

    return $output;
}

/**
 * A subscription owned by $userId, pointing at the account's fixture references.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param array          $refs   from wallos_test_user_references()
 * @param int|null       $replacement  a replacement_subscription_id to record
 * @return int the new subscription id
 */
function wi_insert_subscription($db, $userId, array $refs, $replacement = null)
{
    // Bare binds throughout, so a test fixture does not widen the SQLite
    // boundary the audit ratchets (#20); both backends infer the type.
    $stmt = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payment_method_id,
         payer_user_id, category_id, notify, inactive, auto_renew, replacement_subscription_id, user_id)
        VALUES (:name, 1.0, :currency, :next, 3, 1, :method, :payer, :category, 0, 0, 0, :replacement, :user)');
    $stmt->bindValue(':name', 'wi subscription');
    $stmt->bindValue(':currency', (int) wallos_test_currency_id($userId, 0));
    $stmt->bindValue(':next', '2099-01-01');
    $stmt->bindValue(':method', (int) $refs['payment_method']);
    $stmt->bindValue(':payer', (int) $refs['household']);
    $stmt->bindValue(':category', (int) $refs['category']);
    $stmt->bindValue(':replacement', $replacement === null ? null : (int) $replacement);
    $stmt->bindValue(':user', (int) $userId);
    $stmt->execute();

    return (int) $db->scalar('SELECT id FROM subscriptions WHERE name = :n ORDER BY id DESC LIMIT 1',
        [':n' => 'wi subscription']);
}

// --- settings: DELETE-then-INSERT, no unique key (#137 shape a) -------------

wallos_test('customcss reports a failed clear rather than doubling the row (#137)', function () {
    // The DELETE and the INSERT have no unique key between them, so a DELETE that
    // failed and an INSERT that succeeded left two rows and reported the save.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    // An existing row for the DELETE to remove: without one the DELETE matches
    // nothing, which is a successful no-op the block never touches.
    $db->exec("INSERT INTO custom_css_style (css, user_id) VALUES ('.old{}', 1)");
    wallos_test_block_writes($db, 'custom_css_style', 'DELETE');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/customcss.php', 1,
        [], '{"customCss":".x{color:red}"}');

    wallos_test_unblock_writes($db, 'custom_css_style');

    assert_contains('"success":false', $out,
        'a failed clear is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('customtheme reports a failed clear rather than doubling the row (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO custom_colors (main_color, accent_color, hover_color, user_id)
               VALUES ('#000000', '#111111', '#222222', 1)");
    wallos_test_block_writes($db, 'custom_colors', 'DELETE');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/customtheme.php', 1,
        [], '{"mainColor":"#112233","accentColor":"#445566","hoverColor":"#778899"}');

    wallos_test_unblock_writes($db, 'custom_colors');

    assert_contains('"success":false', $out,
        'a failed clear is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('google_search reports a failed key clear as failure (#137)', function () {
    // The empty-key path clears the credential and answers success without ever
    // reading the DELETE it depends on.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO google_search (api_key, user_id) VALUES ('old-serp-key', 1)");
    wallos_test_block_writes($db, 'google_search', 'DELETE');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/google_search.php', 1,
        ['api_key' => '']);

    wallos_test_unblock_writes($db, 'google_search');

    assert_contains('"success":false', $out,
        'a failed clear is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('google_search keeps the working key when the candidate is rejected (#142 shape)', function () {
    // Broken-to-count: main deletes the stored credential up front and only then
    // validates the candidate, so a rejected key leaves the account with none.
    // The fix validates first and touches the row only once the key is accepted.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO google_search (api_key, user_id) VALUES ('old-serp-key', 1)");

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/google_search.php', 1,
        ['api_key' => 'rejected-candidate'], null,
        'function wallos_serpapi_key_is_valid($k) { return false; }');

    assert_contains('"success":false', $out,
        'a rejected key is reported as failure on ' . $db->driver() . ' (' . $out . ')');
    assert_same('old-serp-key',
        (string) $db->scalar('SELECT api_key FROM google_search WHERE user_id = 1'),
        'the working key survives a rejected candidate on ' . $db->driver());

    $db->close();
});

wallos_test('google_search replaces the key when the candidate is accepted', function () {
    // The happy path still stores the new key once validation passes.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO google_search (api_key, user_id) VALUES ('old-serp-key', 1)");

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/google_search.php', 1,
        ['api_key' => 'accepted-candidate'], null,
        'function wallos_serpapi_key_is_valid($k) { return true; }');

    assert_contains('"success":true', $out,
        'an accepted key is reported as success on ' . $db->driver() . ' (' . $out . ')');
    assert_same('accepted-candidate',
        (string) $db->scalar('SELECT api_key FROM google_search WHERE user_id = 1'),
        'the accepted key replaces the old one on ' . $db->driver());

    $db->close();
});

wallos_test('google_search keeps the working key when the accepted candidate cannot be stored (#142 shape)', function () {
    // Broken-to-count: the validated candidate deletes the working key and then
    // fails to insert. Without a transaction the DELETE commits and the account
    // is left with no credential; the atomic replacement rolls the DELETE back
    // so the working key survives (5.15.0 QA #3 — the residual of the #142
    // "validate before destroy" shape, this time between the two writes).
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO google_search (api_key, user_id) VALUES ('old-serp-key', 1)");
    wallos_test_block_writes($db, 'google_search', 'INSERT');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/settings/google_search.php', 1,
        ['api_key' => 'new-serp-key'], null,
        'function wallos_serpapi_key_is_valid($k) { return true; }');

    wallos_test_unblock_writes($db, 'google_search');

    assert_contains('"success":false', $out,
        'a store that could not be written is reported as failure on ' . $db->driver() . ' (' . $out . ')');
    assert_same('old-serp-key',
        (string) $db->scalar('SELECT api_key FROM google_search WHERE user_id = 1'),
        'the working key survives a store that fails on ' . $db->driver());

    $db->close();
});

// endpoints/ai/save_settings.php is the fifth DELETE-then-INSERT and its result
// check went in with this batch, but it has no runtime case here, on purpose and
// after being tried: unlike the four above, ai_settings carries a UNIQUE index
// on user_id (idx_ai_settings_user, includes/database/pgsql/schema.sql:504, and
// the SQLite fixture enforces it too). A blocked DELETE therefore leaves the old
// row in place, the INSERT collides with the index and fails, and the endpoint
// already answered success:false before this fix — so there is no reported
// success to break-to-count. #137 lists ai_settings among the tables with "no
// unique constraint on user_id", which the index at :504 contradicts; the check
// is kept as defense-in-depth and audited (dev/write-audit-baseline.txt) rather
// than asserted here, where the case could only pass on the unfixed code too.

// --- sort loops: $result = execute() nobody read (#137 shape b) -------------

wallos_test('payments sort reports a failed reorder as failure (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);
    wallos_test_block_writes($db, 'payment_methods', 'UPDATE', 'order');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/payments/sort.php', 1,
        ['paymentMethodIds' => [(string) $refs['payment_method']]]);

    wallos_test_unblock_writes($db, 'payment_methods');

    assert_contains('"success":false', $out,
        'a reorder that could not be written is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('category sort reports a failed reorder as failure (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);
    wallos_test_block_writes($db, 'categories', 'UPDATE', 'order');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/categories/category.php', 1,
        ['action' => 'sort', 'categoryIds' => [(string) $refs['category']]]);

    wallos_test_unblock_writes($db, 'categories');

    assert_contains('"success":false', $out,
        'a reorder that could not be written is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

// --- delete cascades: the UPDATE after a checked DELETE (#137 shape c) -------

wallos_test('subscription delete reports a failed cascade as failure (#137)', function () {
    // The DELETE is checked; the UPDATE that nulls the dangling replacement
    // pointers was not, so a subscription could be deleted, its references left
    // pointing at a row that is gone, and the whole thing reported as deleted.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);
    $target = wi_insert_subscription($db, 1, $refs);
    wi_insert_subscription($db, 1, $refs, $target); // B points its replacement at the target

    wallos_test_block_writes($db, 'subscriptions', 'UPDATE', 'replacement_subscription_id');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/subscription/delete.php', 1,
        [], json_encode(['id' => $target]));

    wallos_test_unblock_writes($db, 'subscriptions');

    assert_contains('"success":false', $out,
        'a failed cascade is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('the subscriptions API reports a failed delete cascade as failure (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);

    $key = 'wi-api-key';
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', $key);
    $stmt->execute();

    $target = wi_insert_subscription($db, 1, $refs);
    wi_insert_subscription($db, 1, $refs, $target);

    wallos_test_block_writes($db, 'subscriptions', 'UPDATE', 'replacement_subscription_id');

    $out = wi_invoke(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php', null,
        ['api_key' => $key, 'action' => 'delete', 'id' => (string) $target]);

    wallos_test_unblock_writes($db, 'subscriptions');

    assert_contains('"success":false', $out,
        'a failed cascade is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

// --- admin OIDC toggle: the discarded UPDATE before a top-level success (#137)

wallos_test('the OIDC settings API reports a failed enablement toggle as failure (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $key = 'wi-admin-key';
    $stmt = $db->prepare('UPDATE "user" SET api_key = :k WHERE id = 1');
    $stmt->bindValue(':k', $key);
    $stmt->execute();

    // The toggle writes the admin row; block it and the request used to sail on
    // to the configuration save and answer success.
    wallos_test_block_writes($db, 'admin', 'UPDATE', 'oidc_oauth_enabled');

    $out = wi_invoke(WALLOS_ROOT . '/api/admin/set_oidc_settings.php', null,
        ['api_key' => $key, 'oidc_enabled' => '0']);

    wallos_test_unblock_writes($db, 'admin');

    assert_contains('"success":false', $out,
        'a failed toggle is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

// --- AI endpoints: the write after a completion that has to succeed first ----
//
// generate_recommendations and translate_categories reach their writes only
// after a provider completion. The test container has no route to a real
// provider, so a stub is stood up on loopback and the account is made an admin
// with 127.0.0.1 on the SSRF allowlist — the two things the host-provider path
// checks before it will call a private address. ai_complete then reaches the
// stub, and the DELETE/INSERT/UPDATE the endpoint runs afterwards is what these
// cases block.

/**
 * A free loopback TCP port, picked by letting the OS assign one and releasing it.
 *
 * @return int
 */
function wi_free_port()
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) {
        return 0;
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * A loopback HTTP stub answering every request with $content wrapped as an
 * OpenAI-style chat completion, so ai_complete()'s openai-compatible branch
 * reads it as the model's reply.
 *
 * @param string $content the model's text — a JSON array, in practice
 * @return array{proc: resource, port: int, router: string}|null
 */
function wi_start_stub($content)
{
    $reply = json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]);
    $router = WALLOS_TEST_TMP . '/wi-stub-' . uniqid('', true) . '.php';
    file_put_contents($router,
        "<?php\nheader('Content-Type: application/json');\necho " . var_export($reply, true) . ";\n");

    $port = wi_free_port();
    if ($port === 0) {
        @unlink($router);

        return null;
    }

    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], $descriptors, $pipes, WALLOS_TEST_TMP);
    if (!is_resource($proc)) {
        @unlink($router);

        return null;
    }

    // Wait until the listener answers, so the endpoint does not race its startup.
    for ($i = 0; $i < 200; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
        if ($probe) {
            fclose($probe);
            break;
        }
        usleep(20000);
    }

    return ['proc' => $proc, 'port' => $port, 'router' => $router];
}

/**
 * @param array|null $stub
 * @return void
 */
function wi_stop_stub($stub)
{
    if ($stub === null) {
        return;
    }

    proc_terminate($stub['proc']);
    proc_close($stub['proc']);
    @unlink($stub['router']);
}

/**
 * Configures $userId to run a custom openai-compatible provider pointed at the
 * stub, and permits the loopback address the provider lives on.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param int            $port
 * @return void
 */
function wi_configure_ai_stub($db, $userId, $port)
{
    wallos_grant_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
    $db->exec("UPDATE admin SET local_webhook_notifications_allowlist = '127.0.0.1' WHERE id = 1");

    $stmt = $db->prepare("INSERT INTO ai_settings
        (user_id, type, enabled, api_key, model, url, run_schedule, provider_mode)
        VALUES (:u, 'openai-compatible', 1, '', 'stub-model', :url, 'manual', 'custom')");
    $stmt->bindValue(':u', (int) $userId);
    $stmt->bindValue(':url', 'http://127.0.0.1:' . $port);
    $stmt->execute();
}

wallos_test('translate_categories reports a failed rename as failure (#137)', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("UPDATE \"user\" SET language = 'de' WHERE id = 1");

    $categoryId = (int) $db->scalar('SELECT id FROM categories WHERE user_id = 1 AND id != 1 ORDER BY id LIMIT 1');
    assert_true($categoryId > 0, 'the fixture gave the account a category to translate');

    $stub = wi_start_stub('[{"id":' . $categoryId . ',"name":"Kategorie"}]');
    assert_true($stub !== null, 'the provider stub started');
    wi_configure_ai_stub($db, 1, $stub['port']);

    wallos_test_block_writes($db, 'categories', 'UPDATE', 'name');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/ai/translate_categories.php', 1, []);

    wallos_test_unblock_writes($db, 'categories');
    wi_stop_stub($stub);

    assert_contains('"success":false', $out,
        'a rename that could not be written is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('generate_recommendations reports a failed clear as failure (#137)', function () {
    // The DELETE that clears the previous recommendations, blocked with a row in
    // place for it to remove.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);
    wi_insert_subscription($db, 1, $refs);
    $db->exec("INSERT INTO ai_recommendations (user_id, type, title, description, savings)
               VALUES (1, 'subscription', 'old', 'old', '')");

    $stub = wi_start_stub('[{"title":"Cancel one","description":"You can save","savings":"10 EUR"}]');
    assert_true($stub !== null, 'the provider stub started');
    wi_configure_ai_stub($db, 1, $stub['port']);

    wallos_test_block_writes($db, 'ai_recommendations', 'DELETE');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/ai/generate_recommendations.php', 1, []);

    wallos_test_unblock_writes($db, 'ai_recommendations');
    wi_stop_stub($stub);

    assert_contains('"success":false', $out,
        'a failed clear is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});

wallos_test('generate_recommendations reports a failed store as failure (#137)', function () {
    // The INSERT loop that stores the new recommendations, blocked directly.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $refs = wallos_test_user_references($db, 1);
    wi_insert_subscription($db, 1, $refs);

    $stub = wi_start_stub('[{"title":"Cancel one","description":"You can save","savings":"10 EUR"}]');
    assert_true($stub !== null, 'the provider stub started');
    wi_configure_ai_stub($db, 1, $stub['port']);

    wallos_test_block_writes($db, 'ai_recommendations', 'INSERT');

    $out = wi_invoke(WALLOS_ROOT . '/endpoints/ai/generate_recommendations.php', 1, []);

    wallos_test_unblock_writes($db, 'ai_recommendations');
    wi_stop_stub($stub);

    assert_contains('"success":false', $out,
        'a failed store is reported as failure on ' . $db->driver() . ' (' . $out . ')');

    $db->close();
});
