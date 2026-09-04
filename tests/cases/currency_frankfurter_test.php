<?php
/*
  Frankfurter v2 as a provider that needs no API key (#140).

  Two things separate it from the two providers that came before, and both are
  load-bearing here.

  It has no credential, so "configured" can no longer mean "has an API key".
  The check that decided otherwise made an unconfigured installation and a
  keyless-by-design one the same thing, and the scheduled job answered that by
  printing "No currency provider configured" and exiting zero — a silent
  nothing, which is the failure this issue exists to remove.

  And it prices in whatever base it is asked for, so the request base is the
  user's own main currency rather than EUR for everybody. The per-run cache
  keyed on provider and API key alone (#9, #117); with a base in play that key
  no longer identifies an answer, and a USD user would be served EUR-based
  rates that are wrong by the whole EUR/USD spread.

  MEASURED AGAINST THE LIVE SERVICE on 2026-09-04, so the fixtures below are
  transcripts rather than guesses. Recorded here so nobody has to probe again:

    GET /v2/rates?base=USD&quotes=EUR,GBP,CHF   200
      [{"date":"2026-09-04","base":"USD","quote":"CHF","rate":0.80984},
       {"date":"2026-09-04","base":"USD","quote":"EUR","rate":0.86117},
       {"date":"2026-09-04","base":"USD","quote":"GBP","rate":0.74065}]
      A flat array of records — not the {"rates":{...}} object of the retired
      api.frankfurter.app, which answers 301 now.

    base=EUR&quotes=EUR              200  [{...,"quote":"EUR","rate":1.0}]
      A base asked for itself comes back at exactly 1.0. Stored from the
      local rule anyway; see the case below.

    base=EUR&quotes=USD,BTC          200  only the USD record
      A well-formed code it does not price is dropped in silence. No error,
      no mention of the code. This is why the caller has to compare what it
      asked for against what came back.

    base=BTC&quotes=USD              200  []
      An unknown BASE is not an error either — it is an empty array with a
      200. Decodes to a perfectly good array, so a bare is_array() check
      accepts it and the user ends up with no rates and no complaint.

    base=EUR&quotes=USD,TOOLONG      422  {"status":422,"message":"invalid currency: TOOLONG"}
    base=TOOLONG&quotes=USD          422  {"status":422,"message":"invalid currency: TOOLONG"}
    base=EUR&quotes=USD,%20GBP       422  {"status":422,"message":"invalid currency:  GBP"}
      A malformed code takes the WHOLE request down, base or quote alike. In
      a shared union request that is one user's invented currency (#133)
      stopping every other user's rates, so the client sends only well-formed
      codes and names the rest.

    GET /v2/nonsense                 404  {"status":404,"message":"not found"}

    Response headers carry no rate-limit fields at all — no quota to read and
    none to display.

  No test here makes a request. The client's one network touch is
  wallos_provider_http_get(), guarded by function_exists, so the child
  processes below define their own transport before loading the client, the
  same way currency_quota_test.php and currency_union_test.php do.
*/

require_once WALLOS_ROOT . '/includes/currency_provider.php';

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * Local to this file on purpose: the runner loads only the case files the
 * filter matches, so a helper from another file may not exist. The script path
 * is generated here and quoted; nothing a request could reach.
 *
 * @param string $body PHP code, without the opening tag.
 * @return array{output: string, status: int}
 */
function frankfurter_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/frankfurter-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

/**
 * A transport that answers like the live service: it reads the base out of the
 * URL and prices every requested quote against it.
 *
 * The two rate tables are transcripts rather than a formula, because a formula
 * would divide the same numbers the code under test divides and would then
 * agree with a wrong base by construction.
 *
 * @return string PHP source defining wallos_provider_http_get().
 */
function frankfurter_transport()
{
    // The 2026-09-04 fixings, per unit of base. GBP is 0.86005 per EUR and
    // 0.74065 per USD — a 16% gap, so a base mix-up cannot hide inside a
    // rounding tolerance.
    return '$GLOBALS["transport_calls"] = 0;' . "\n"
        . '$GLOBALS["seen_urls"] = [];' . "\n"
        . '$GLOBALS["rates"] = [' . "\n"
        . '    "EUR" => ["EUR" => 1.0, "USD" => 1.1612, "GBP" => 0.86005, "CHF" => 0.94039],' . "\n"
        . '    "USD" => ["EUR" => 0.86117, "USD" => 1.0, "GBP" => 0.74065, "CHF" => 0.80984],' . "\n"
        . '    "GBP" => ["EUR" => 1.16272, "USD" => 1.35015, "GBP" => 1.0, "CHF" => 1.09341],' . "\n"
        . '];' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["transport_calls"]++;' . "\n"
        . '    $GLOBALS["seen_urls"][] = $url;' . "\n"
        . '    $query = [];' . "\n"
        . '    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);' . "\n"
        . '    $base = strtoupper((string) ($query["base"] ?? ""));' . "\n"
        . '    if (!isset($GLOBALS["rates"][$base])) {' . "\n"
        // An unknown base is a 200 and an empty array, exactly as measured.
        . '        return ["body" => "[]", "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '    }' . "\n"
        . '    $records = [];' . "\n"
        . '    foreach (array_filter(explode(",", (string) ($query["quotes"] ?? ""))) as $quote) {' . "\n"
        . '        $quote = strtoupper(trim($quote));' . "\n"
        . '        if (!preg_match("/^[A-Z]{3}$/", $quote)) {' . "\n"
        // A malformed code takes the whole request down, as measured.
        . '            return ["body" => json_encode(["status" => 422, "message" => "invalid currency: " . $quote]),' . "\n"
        . '                    "headers" => ["HTTP/1.1 422 Unprocessable Entity"]];' . "\n"
        . '        }' . "\n"
        . '        if (!isset($GLOBALS["rates"][$base][$quote])) {' . "\n"
        // A code it does not price is dropped in silence, as measured.
        . '            continue;' . "\n"
        . '        }' . "\n"
        . '        $records[] = ["date" => "2026-09-04", "base" => $base, "quote" => $quote,' . "\n"
        . '                      "rate" => $GLOBALS["rates"][$base][$quote]];' . "\n"
        . '    }' . "\n"
        . '    return ["body" => json_encode($records), "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n";
}

/**
 * Gives a user one more currency than the two the fixture seeds.
 *
 * @param SQLite3|WallosDatabase $db
 * @param int                    $userId
 * @param int                    $id
 * @param string                 $code
 */
function frankfurter_add_currency($db, $userId, $id, $code)
{
    $stmt = $db->prepare('INSERT INTO currencies (id, name, symbol, code, rate, user_id)
                          VALUES (:id, :name, :symbol, :code, 1.0, :userId)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $code, SQLITE3_TEXT);
    $stmt->bindValue(':symbol', $code, SQLITE3_TEXT);
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Points a user's main currency at one of their own currency rows.
 *
 * @param SQLite3|WallosDatabase $db
 * @param int                    $userId
 * @param string                 $code
 */
function frankfurter_set_main($db, $userId, $code)
{
    $id = (int) $db->scalar('SELECT id FROM currencies WHERE user_id = :u AND code = :c',
        [':u' => $userId, ':c' => $code]);

    $stmt = $db->prepare('UPDATE "user" SET main_currency = :id WHERE id = :u');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Configures the instance to use Frankfurter, storing no key at all.
 *
 * @param SQLite3|WallosDatabase $db
 */
function frankfurter_configure_instance($db)
{
    wallos_set_instance_setting($db, 'currency', 'provider', 'frankfurter');
}

/**
 * @param SQLite3|WallosDatabase $db
 * @param int                    $userId
 * @param string                 $code
 * @return float
 */
function frankfurter_rate($db, $userId, $code)
{
    return (float) $db->scalar('SELECT rate FROM currencies WHERE user_id = :u AND code = :c',
        [':u' => $userId, ':c' => $code]);
}

/* -------------------------------------------------------------------------
   The cache key. This is the case the change is most likely to get wrong.
   ------------------------------------------------------------------------- */

wallos_test('the run cache distinguishes bases', function () {
    // Two users tracking the SAME three currencies, so every covering-subset
    // rule in the cache matches, and differing only in which of them is their
    // main currency. Under a key of provider|api_key the second user is served
    // the first user's answer: one request instead of two, priced in a base
    // that is not theirs. GBP is 0.86005 per EUR and 0.74065 per USD, so the
    // wrong answer is wrong by 16% rather than by a rounding step.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    frankfurter_add_currency($db, 1, 999011, 'GBP');
    frankfurter_add_currency($db, 2, 999021, 'GBP');
    frankfurter_set_main($db, 2, 'USD');
    frankfurter_configure_instance($db);

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";' . "\n"
        . 'echo "bases=" . implode(",", array_map(function ($u) {' . "\n"
        . '    $q = []; parse_str((string) parse_url($u, PHP_URL_QUERY), $q); return $q["base"] ?? "?";' . "\n"
        . '}, $GLOBALS["seen_urls"])) . "\n";'
    );

    assert_contains('calls=2', $run['output'],
        'one request per distinct base, not one answer shared across bases (got: ' . $run['output'] . ')');
    assert_contains('bases=EUR,USD', $run['output'],
        'and each base was actually asked for (got: ' . $run['output'] . ')');

    // The consequence, which is the reason the key matters at all.
    assert_true(abs(frankfurter_rate($db, 1, 'GBP') - 0.86005) < 0.000001,
        'alice is priced in EUR, her main currency (got ' . frankfurter_rate($db, 1, 'GBP') . ')');
    assert_true(abs(frankfurter_rate($db, 2, 'GBP') - 0.74065) < 0.000001,
        'bob is priced in USD, his main currency (got ' . frankfurter_rate($db, 2, 'GBP') . ')');

    $db->close();
});

wallos_test('the shared union is grouped by main currency', function () {
    // The #9 union survives, one group per base rather than one for everyone:
    // four users, two main currencies, two requests. Grouping blindly would
    // spend one call and price half of them wrong; not grouping at all would
    // spend four.
    $db = wallos_test_open_database();
    foreach ([[1, 'alice', 'EUR'], [2, 'bob', 'EUR'], [3, 'carol', 'USD'], [4, 'dave', 'USD']] as $user) {
        wallos_test_create_user($db, $user[0], $user[1]);
        frankfurter_add_currency($db, $user[0], 999000 + $user[0] * 10 + 1, 'GBP');
        frankfurter_set_main($db, $user[0], $user[2]);
    }
    // One user tracks a fourth currency, so the group's union is wider than any
    // single member's list and the covering-subset rule is what answers them.
    frankfurter_add_currency($db, 2, 999999, 'CHF');
    frankfurter_configure_instance($db);

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";'
    );

    assert_contains('calls=2', $run['output'],
        'four users behind two bases cost two requests (got: ' . $run['output'] . ')');
    assert_same(4, substr_count($run['output'], 'Rates updated successfully'),
        'and all four were refreshed from them');

    assert_true(abs(frankfurter_rate($db, 2, 'CHF') - 0.94039) < 0.000001,
        'the widest member of the EUR group got its extra currency');
    assert_true(abs(frankfurter_rate($db, 4, 'GBP') - 0.74065) < 0.000001,
        'and the USD group was priced in USD');

    $db->close();
});

/* -------------------------------------------------------------------------
   No key is a complete configuration.
   ------------------------------------------------------------------------- */

wallos_test('a Frankfurter configuration is valid with no API key', function () {
    // "Configured" used to mean "has an API key", which makes the provider
    // that has no key permanently unconfigured — and the job says so and exits
    // zero rather than failing.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    $config = wallos_get_effective_currency_config($db, 1);

    assert_true($config['valid'],
        'no key is a complete Frankfurter configuration'
            . ($config['notes'] ? ' — it objected: ' . $config['notes'][0] : ''));
    assert_same(2, (int) $config['values']['provider'], 'and the provider is Frankfurter');
    assert_same('', trim((string) $config['values']['api_key']), 'with no key stored');

    // Fixer is unchanged: no key still means not configured.
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_reset_config_cache($db);
    assert_true(!wallos_get_effective_currency_config($db, 1)['valid'],
        'a keyed provider without its key is still refused');

    $db->close();
});

wallos_test('WALLOS_CURRENCY_PROVIDER accepts frankfurter with no key variable', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    putenv('WALLOS_CURRENCY_PROVIDER=frankfurter');
    wallos_reset_config_cache($db);
    $config = wallos_get_effective_currency_config($db, 1);

    assert_true($config['valid'], 'the environment can select it alone'
        . ($config['notes'] ? ' — it objected: ' . $config['notes'][0] : ''));
    assert_same(2, (int) $config['values']['provider'], 'and it resolves to provider 2');

    $db->close();
});

wallos_test('a keyless installation refreshes instead of reporting nothing to do', function () {
    // The acceptance line "a fresh installation converts currencies with no
    // external registration", and the exact shape of the silent failure it
    // replaces: the job used to print this and count the user as skipped.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
    );

    assert_not_contains('No currency provider configured', $run['output'],
        'a keyless Frankfurter installation is configured');
    assert_contains('Rates updated successfully', $run['output'],
        'and its rates are refreshed (got: ' . $run['output'] . ')');
    assert_true(abs(frankfurter_rate($db, 1, 'USD') - 1.1612) < 0.000001,
        'with the rates the provider gave');

    $db->close();
});

/* -------------------------------------------------------------------------
   The main currency, and what a failure must not do.
   ------------------------------------------------------------------------- */

wallos_test('the main currency is stored as exactly 1.0, not as the provider said', function () {
    // Frankfurter does answer 1.0 for a base asked about itself, so this holds
    // by agreement today. It is enforced locally anyway: the identity is a
    // fact about the base, not a number to accept from a response, and a
    // provider drifting to 0.99999 would put every total off by a hair with
    // nothing on screen to explain it.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    $run = frankfurter_run_php(
        // A transport that lies about the base's own rate.
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => json_encode([' . "\n"
        . '        ["date" => "2026-09-04", "base" => "EUR", "quote" => "EUR", "rate" => 0.5],' . "\n"
        . '        ["date" => "2026-09-04", "base" => "EUR", "quote" => "USD", "rate" => 1.1612],' . "\n"
        . '    ]), "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
    );

    assert_contains('Rates updated successfully', $run['output'],
        'the refresh went through (got: ' . $run['output'] . ')');
    assert_same(1.0, frankfurter_rate($db, 1, 'EUR'),
        'the main currency is 1.0 whatever the provider claimed');
    assert_true(abs(frankfurter_rate($db, 1, 'USD') - 1.1612) < 0.000001,
        'and the other rates are not rescaled by the claim either');

    $db->close();
});

wallos_test('a failed request changes no rate and does not advance the date', function () {
    // Measured shapes: 404 {"status":404,...}, 422 {"status":422,...} and a
    // 503. Each one must leave the stored rates and last_exchange_update
    // exactly where they were — old rates that say they are old beat wrong
    // rates that say they are fresh.
    foreach ([['404', 'not found'], ['422', 'invalid currency: TOOLONG'], ['503', 'unavailable']] as $case) {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'alice');
        frankfurter_configure_instance($db);

        $stmt = $db->prepare('UPDATE currencies SET rate = 7.5 WHERE user_id = 1 AND code = :c');
        $stmt->bindValue(':c', 'USD', SQLITE3_TEXT);
        $stmt->execute();

        $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:d, 1)');
        $stmt->bindValue(':d', '2020-01-01', SQLITE3_TEXT);
        $stmt->execute();

        $run = frankfurter_run_php(
            'function wallos_provider_http_get($url, $context) {' . "\n"
            . '    return ["body" => ' . var_export(json_encode(['status' => (int) $case[0], 'message' => $case[1]]), true) . ',' . "\n"
            . '            "headers" => ["HTTP/1.1 ' . $case[0] . ' Refused"]];' . "\n"
            . '}' . "\n"
            . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
        );

        assert_contains('update failed', $run['output'],
            'HTTP ' . $case[0] . ' is reported as a failure (got: ' . $run['output'] . ')');
        assert_contains($case[1], $run['output'],
            'HTTP ' . $case[0] . ' carries the provider\'s own words');
        assert_same(7.5, frankfurter_rate($db, 1, 'USD'),
            'HTTP ' . $case[0] . ' left the stored rate alone');
        assert_same('2020-01-01',
            (string) $db->scalar('SELECT date FROM last_exchange_update WHERE user_id = 1'),
            'HTTP ' . $case[0] . ' did not advance last_exchange_update');

        $db->close();
    }
});

wallos_test('an unknown base is a failure, not an empty success', function () {
    // The measured trap: base=BTC answers 200 with []. That decodes to a
    // perfectly good array, so a bare is_array() check calls it a success,
    // writes nothing, and marks the rates as refreshed today — after which
    // the freshness skip keeps anyone from noticing until tomorrow.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (\'2020-01-01\', 1)');
    $stmt->execute();

    $run = frankfurter_run_php(
        'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    return ["body" => "[]", "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
    );

    assert_contains('update failed', $run['output'],
        'an empty answer is not a successful refresh (got: ' . $run['output'] . ')');
    assert_contains('EUR', $run['output'],
        'and the base it refused is named');
    assert_same('2020-01-01',
        (string) $db->scalar('SELECT date FROM last_exchange_update WHERE user_id = 1'),
        'nothing was marked as refreshed');

    $db->close();
});

wallos_test('a currency Frankfurter does not price keeps its rate and is named', function () {
    // No cryptocurrency: BTC and ETH are in neither scope of /v2/currencies,
    // while XAU and XAG are, so the gap is specifically crypto. The rate
    // endpoint drops such a code in silence, which is the one outcome a user
    // cannot tell from a rate that simply did not move.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_add_currency($db, 1, 999011, 'BTC');
    frankfurter_configure_instance($db);

    $stmt = $db->prepare('UPDATE currencies SET rate = 0.00002 WHERE user_id = 1 AND code = \'BTC\'');
    $stmt->execute();

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
    );

    assert_contains('Rates updated successfully', $run['output'],
        'the currencies it does price are still refreshed (got: ' . $run['output'] . ')');
    assert_contains('BTC', $run['output'],
        'and the one it does not is named rather than left to be noticed');
    assert_same(0.00002, frankfurter_rate($db, 1, 'BTC'),
        'its previous rate is kept');
    assert_true(abs(frankfurter_rate($db, 1, 'USD') - 1.1612) < 0.000001,
        'while the rest were updated');

    $db->close();
});

wallos_test('a malformed code is left out rather than taking every user down', function () {
    // Measured: one bad code 422s the whole request, base or quote alike. A
    // currency in Wallos is three free-text fields, so an invented code is
    // accepted and stored (#133) — and in a shared union request that one
    // user's "Lunarium" would stop every other user's rates.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_add_currency($db, 1, 999011, 'LUNARIUM');
    frankfurter_configure_instance($db);

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "urls=" . implode(" ", $GLOBALS["seen_urls"]) . "\n";'
    );

    assert_contains('Rates updated successfully', $run['output'],
        'the well-formed currencies were still priced (got: ' . $run['output'] . ')');
    assert_not_contains('LUNARIUM&', $run['output'],
        'the malformed code never reached the request');
    assert_contains('LUNARIUM', $run['output'],
        'but it is named in the output rather than dropped in silence');

    $db->close();
});

/* -------------------------------------------------------------------------
   Freshness, quota, and leaving fixer alone.
   ------------------------------------------------------------------------- */

wallos_test('the freshness skip applies to Frankfurter too', function () {
    // A provider with no quota is not a reason to fetch on every container
    // start (#117). The job runs at every deploy.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    $stmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:d, 1)');
    $stmt->bindValue(':d', (new DateTime())->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();

    $run = frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "calls=" . $GLOBALS["transport_calls"] . "\n";'
    );

    assert_contains('calls=0', $run['output'],
        'rates refreshed today are not fetched again (got: ' . $run['output'] . ')');

    $db->close();
});

wallos_test('no quota is invented for a provider that has none', function () {
    // local_calls stays useful — it is what this installation sent. usage_used
    // and usage_limit are the provider's own figures, and Frankfurter reports
    // none, so nothing may be written there for a bar to be drawn from.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_configure_instance($db);

    frankfurter_run_php(
        frankfurter_transport()
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';'
    );

    $instance = wallos_get_instance_settings($db, 'currency');

    assert_same(1, (int) ($instance['local_calls'] ?? 0),
        'the request this installation made is counted');
    assert_same('', (string) ($instance['usage_limit'] ?? ''),
        'but no quota limit was invented');
    assert_same('', (string) ($instance['usage_used'] ?? ''),
        'and no consumption figure either');

    $db->close();
});

wallos_test('fixer is untouched: same URL, same EUR base, same answer', function () {
    // Nobody is migrated and fixer stays first-class. The apilayer arm must
    // still ask api.apilayer.com for an EUR base whatever the user's main
    // currency is, and read the {"rates":{...}} object it answers with.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    frankfurter_add_currency($db, 1, 999011, 'GBP');
    frankfurter_set_main($db, 1, 'USD');
    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $run = frankfurter_run_php(
        '$GLOBALS["seen_urls"] = [];' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["seen_urls"][] = $url;' . "\n"
        . '    return ["body" => \'{"rates":{"EUR":1,"USD":1.1612,"GBP":0.86005}}\',' . "\n"
        . '            "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/updateexchange.php', true) . ';' . "\n"
        . 'echo "urls=" . implode(" ", $GLOBALS["seen_urls"]) . "\n";'
    );

    assert_contains('api.apilayer.com', $run['output'], 'fixer still goes to its own host');
    assert_contains('base=EUR', $run['output'], 'and still asks for an EUR base');
    assert_not_contains('frankfurter', $run['output'], 'nothing routed it through the new provider');

    // The EUR-based answer is still divided out per user, as it always was:
    // alice's main currency is USD, so GBP is 0.86005 / 1.1612.
    assert_true(abs(frankfurter_rate($db, 1, 'GBP') - (0.86005 / 1.1612)) < 0.000001,
        'the EUR answer is still converted to the user\'s main currency');
    assert_same(1.0, frankfurter_rate($db, 1, 'USD'), 'and the main currency is 1.0');

    $db->close();
});

wallos_test('the symbols endpoint answers from the v2 currency catalogue', function () {
    // dev/currency-symbols.php asks a provider which stored codes it refuses,
    // which is what makes "switching provider names what it will not price"
    // possible. Frankfurter's answer to that question is /v2/currencies, whose
    // records are iso_code/name rather than fixer's {"symbols":{...}} object.
    $run = frankfurter_run_php(
        '$GLOBALS["seen_urls"] = [];' . "\n"
        . 'function wallos_provider_http_get($url, $context) {' . "\n"
        . '    $GLOBALS["seen_urls"][] = $url;' . "\n"
        . '    return ["body" => json_encode([' . "\n"
        . '        ["iso_code" => "USD", "iso_numeric" => "840", "name" => "United States Dollar",' . "\n"
        . '         "symbol" => "$", "start_date" => "1999-01-04", "end_date" => "2026-09-04"],' . "\n"
        . '        ["iso_code" => "EUR", "iso_numeric" => "978", "name" => "Euro",' . "\n"
        . '         "symbol" => "E", "start_date" => "1999-01-04", "end_date" => "2026-09-04"],' . "\n"
        . '    ]), "headers" => ["HTTP/1.1 200 OK"]];' . "\n"
        . '}' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/includes/currency_provider.php', true) . ';' . "\n"
        . '$config = ["valid" => true, "values" => ["api_key" => "", "provider" => 2], "notes" => []];' . "\n"
        . '$answer = wallos_fetch_currency_symbols($config);' . "\n"
        . 'echo "ok=" . ($answer["success"] ? "yes" : "no") . "\n";' . "\n"
        . 'echo "codes=" . implode(",", array_keys($answer["symbols"])) . "\n";' . "\n"
        . 'echo "eur=" . ($answer["symbols"]["EUR"] ?? "-") . "\n";' . "\n"
        . 'echo "urls=" . implode(" ", $GLOBALS["seen_urls"]) . "\n";'
    );

    assert_contains('ok=yes', $run['output'], 'the catalogue is read (got: ' . $run['output'] . ')');
    assert_contains('codes=EUR,USD', $run['output'], 'as codes, sorted, like the other provider');
    assert_contains('eur=Euro', $run['output'], 'with the provider\'s own names');
    assert_contains('/v2/currencies', $run['output'], 'from the v2 catalogue endpoint');
});

wallos_test('every Frankfurter request goes over https', function () {
    // The direct-fixer URLs in this repo are plaintext with the key in the
    // query string, which is its own issue. Nothing new may copy the pattern —
    // and Frankfurter has no excuse, having no key to leak and a working TLS
    // endpoint.
    $source = file_get_contents(WALLOS_ROOT . '/includes/currency_provider.php');

    assert_contains('https://api.frankfurter.dev', $source, 'the rate and catalogue hosts are https');
    assert_not_contains('http://api.frankfurter', $source, 'and none of them is plaintext');
});

wallos_test('choosing a keyless provider does not throw the stored key away', function () {
    // Two endpoints save the same setting: the settings page
    // (endpoints/currency/fixer_api_key.php) and the REST API
    // (api/fixer/set_fixer.php). They disagreed — the page updated the row and
    // kept the key, the API deleted the row and with it the credential, so the
    // same product answered the same question two ways depending on which door
    // you came through. An API user who looked at Frankfurter lost the Fixer
    // key with no way back.
    //
    // Read as source rather than run, because both endpoints require a session
    // and an authenticated user; what is asserted is the shape of the branch,
    // which is where the defect lived.
    $paths = [
        'api/fixer/set_fixer.php' => 'if ($provider === 2) {',
        'endpoints/currency/fixer_api_key.php' => 'if ($keylessProvider) {',
    ];

    foreach ($paths as $path => $opening) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        $start = strpos($source, $opening);

        assert_true($start !== false, $path . ' has a branch for a provider that needs no key');

        // To the end of the branch: the next line that closes it at column one.
        $end = strpos($source, "\n}\n", $start);
        $branch = substr($source, $start, $end === false ? null : $end - $start);

        assert_not_contains('DELETE FROM fixer', $branch,
            $path . ' keeps the row, and the key in it, when a keyless provider is chosen');
        assert_contains('UPDATE fixer SET provider', $branch,
            $path . ' updates the existing row instead');
        assert_contains("provider_mode = 'custom'", $branch,
            $path . ' writes the mode explicitly — the column defaults to instance, '
            . 'and a row saying "use Frankfurter" under instance mode is stored and then ignored');
    }
});
