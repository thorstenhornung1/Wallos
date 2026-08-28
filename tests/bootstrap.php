<?php
/*
  Minimal test harness for Wallos.

  Wallos vendors its libraries instead of using Composer, so the tests follow
  the same principle: no external dependency, just PHP and the SQLite
  extension the application already requires.

  A test file registers cases with wallos_test() and asserts with the assert_*
  helpers. tests/run.php discovers and runs them.
*/

define('WALLOS_ROOT', dirname(__DIR__));
define('WALLOS_TEST_TMP', sys_get_temp_dir() . '/wallos-tests');

// At file scope on purpose: WallosCountingDatabase below extends
// WallosSqliteDatabase, and a parent class loaded inside a function does not
// exist yet when PHP parses the class declaration.
require_once WALLOS_ROOT . '/includes/database/sqlite/database.php';

// The boundary itself, because every fixture below opens its database through
// wallos_database_connect() rather than constructing a connection of its own.
require_once WALLOS_ROOT . '/includes/database/connection.php';

$GLOBALS['wallos_tests'] = [];
$GLOBALS['wallos_test_failures'] = [];
$GLOBALS['wallos_test_skipped'] = [];
$GLOBALS['wallos_test_pgsql_schemas'] = [];
$GLOBALS['wallos_test_assertions'] = 0;
$GLOBALS['wallos_test_current'] = null;

/**
 * Registers one test case.
 *
 * @param string   $name
 * @param callable $body
 */
function wallos_test($name, callable $body)
{
    $GLOBALS['wallos_tests'][] = ['name' => $name, 'body' => $body, 'pending' => false];
}

/**
 * Registers a case for behaviour the specification requires but the code does
 * not implement yet. It runs and reports, but a failure does not fail the
 * suite — the moment it starts passing, the runner says so and the case can be
 * promoted to wallos_test().
 *
 * @param string   $name
 * @param string   $reason  Why it is not implemented yet, e.g. an issue number.
 * @param callable $body
 */
function wallos_test_pending($name, $reason, callable $body)
{
    $GLOBALS['wallos_tests'][] = ['name' => $name, 'body' => $body, 'pending' => true, 'reason' => $reason];
}

function wallos_test_fail($message)
{
    $GLOBALS['wallos_test_failures'][] = [
        'test' => $GLOBALS['wallos_test_current'],
        'message' => $message,
    ];
}

function assert_true($condition, $message)
{
    $GLOBALS['wallos_test_assertions']++;

    if (!$condition) {
        wallos_test_fail($message);
    }
}

function assert_same($expected, $actual, $message)
{
    $GLOBALS['wallos_test_assertions']++;

    if ($expected !== $actual) {
        wallos_test_fail(sprintf(
            '%s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_equals($expected, $actual, $message)
{
    $GLOBALS['wallos_test_assertions']++;

    if ($expected != $actual) {
        wallos_test_fail(sprintf(
            '%s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_contains($needle, $haystack, $message)
{
    $GLOBALS['wallos_test_assertions']++;

    if (strpos((string) $haystack, (string) $needle) === false) {
        wallos_test_fail($message . ' (missing: ' . $needle . ')');
    }
}

function assert_not_contains($needle, $haystack, $message)
{
    $GLOBALS['wallos_test_assertions']++;

    if (strpos((string) $haystack, (string) $needle) !== false) {
        wallos_test_fail($message . ' (unexpectedly present: ' . $needle . ')');
    }
}

/**
 * Clears every environment variable the configuration layer reads, so one test
 * cannot leak configuration into the next.
 */
function wallos_test_reset_env()
{
    $variables = [
        'WALLOS_SMTP_HOST', 'WALLOS_SMTP_PORT', 'WALLOS_SMTP_ENCRYPTION', 'WALLOS_SMTP_USERNAME',
        'WALLOS_SMTP_PASSWORD', 'WALLOS_SMTP_PASSWORD_FILE', 'WALLOS_SMTP_FROM', 'WALLOS_SMTP_FROM_NAME',
        'WALLOS_CURRENCY_PROVIDER', 'WALLOS_CURRENCY_API_KEY', 'WALLOS_CURRENCY_API_KEY_FILE',
        'WALLOS_AI_PROVIDER', 'WALLOS_AI_API_KEY', 'WALLOS_AI_API_KEY_FILE', 'WALLOS_AI_BASE_URL', 'WALLOS_AI_MODEL',
        'WALLOS_DEFAULT_LANGUAGE',
        'SSRF_ALLOWLIST',
        // A variable missing from this list leaks into the next case and makes
        // it pass or fail for a reason that has nothing to do with the case.
        'OIDC_ENABLED', 'OIDC_PROVIDER_NAME', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET',
        'OIDC_CLIENT_SECRET_FILE', 'OIDC_ISSUER', 'OIDC_AUTH_URL', 'OIDC_TOKEN_URL',
        'OIDC_USERINFO_URL', 'OIDC_REDIRECT_URL', 'OIDC_LOGOUT_URL', 'OIDC_SCOPES',
        'OIDC_USER_IDENTIFIER', 'OIDC_AUTO_CREATE_USER', 'OIDC_DISABLE_PASSWORD_LOGIN',
        'OIDC_REQUIRE_EMAIL_VERIFIED', 'OIDC_ADMIN_CLAIM', 'OIDC_ADMIN_VALUE',
        'WALLOS_DB_DRIVER', 'WALLOS_DB_HOST', 'WALLOS_DB_PORT', 'WALLOS_DB_NAME',
        'WALLOS_DB_USER', 'WALLOS_DB_PASSWORD', 'WALLOS_DB_PASSWORD_FILE',
        'WALLOS_DB_SSLMODE', 'WALLOS_DB_PATH',
        // Not read by Wallos but by libpq underneath it: the PostgreSQL fixture
        // puts the case's own schema in here so that a connection opened by the
        // code under test lands in the same isolation as the fixture's own.
        // Left standing between cases it would point at a schema that has been
        // dropped.
        'PGOPTIONS',
    ];

    foreach ($variables as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    // Resolution is memoized per request; a test is a new request.
    if (function_exists('wallos_reset_config_cache')) {
        wallos_reset_config_cache();
    }
}

/**
 * Writes a secret file for *_FILE tests and returns its path.
 *
 * @param string $name
 * @param string $contents
 * @return string
 */
function wallos_test_secret_file($name, $contents)
{
    $directory = WALLOS_TEST_TMP . '/secrets';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $path = $directory . '/' . $name;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Whether a file really calls a function.
 *
 * Not `strpos`. A test suite full of `strpos($source, 'wallos_do_the_thing')`
 * is satisfied by a comment mentioning the function, by a string containing its
 * name, and by a `require_once` of the file that defines it — all of which
 * happened here. The case named "a running session is checked on every request"
 * asserted a filename appeared in one file while 112 endpoints went unguarded,
 * and its replacement asserted a different filename and had exactly the same
 * hole: deleting the call left the suite green.
 *
 * PHP's own tokeniser knows the difference between a call, a comment and a
 * string, so this asks it.
 *
 * @param string $path relative to the repository root
 * @param string $function
 * @return bool
 */
function wallos_test_file_calls($path, $function)
{
    $source = @file_get_contents(WALLOS_ROOT . '/' . $path);
    if ($source === false) {
        return false;
    }

    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $function) {
            continue;
        }

        // The next token that is not whitespace has to be an opening bracket,
        // otherwise this is the name appearing somewhere that is not a call —
        // a definition, a string index, a callable passed by name.
        for ($next = $index + 1; $next < count($tokens); $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && $candidate[0] === T_WHITESPACE) {
                continue;
            }
            if ($candidate === '(') {
                // A definition is `function name(`, so look backwards too.
                for ($previous = $index - 1; $previous >= 0; $previous--) {
                    $before = $tokens[$previous];
                    if (is_array($before) && $before[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($before) && $before[0] === T_FUNCTION) {
                        break 2;
                    }
                    break;
                }

                return true;
            }
            break;
        }
    }

    return false;
}

/**
 * Which backend this run exercises.
 *
 * The suite runs on SQLite by default, because that is what an installation
 * gets unless it asks otherwise. WALLOS_TEST_DRIVER=pgsql runs the same cases
 * against PostgreSQL, which is the only way application code is ever executed
 * against it. Before this existed the single PostgreSQL test compared two
 * strings, and five defects that made the schema unusable at runtime survived
 * all the way to a review.
 *
 * @return string 'sqlite' or 'pgsql'
 */
function wallos_test_driver()
{
    return getenv('WALLOS_TEST_DRIVER') === 'pgsql' ? 'pgsql' : 'sqlite';
}

/**
 * Connection settings for the PostgreSQL test database.
 *
 * @return array
 */
function wallos_test_pgsql_settings()
{
    return [
        'host' => getenv('WALLOS_TEST_DB_HOST') ?: 'postgres',
        'port' => (int) (getenv('WALLOS_TEST_DB_PORT') ?: 5432),
        'name' => getenv('WALLOS_TEST_DB_NAME') ?: 'wallos',
        'user' => getenv('WALLOS_TEST_DB_USER') ?: 'wallos',
        'password' => getenv('WALLOS_TEST_DB_PASSWORD') ?: 'wallos-dev',
        'sslmode' => 'prefer',
    ];
}

/**
 * Puts the PostgreSQL test settings into the environment, the way a deployment
 * selects a backend.
 *
 * The fixture configures the environment instead of handing a connection its
 * settings directly, because that is the only input the application has. A test
 * that constructs its own connection object is testing a connection nobody in
 * production ever holds.
 *
 * @param string $schema the case's own schema
 * @return void
 */
function wallos_test_pgsql_env($schema)
{
    $settings = wallos_test_pgsql_settings();

    putenv('WALLOS_DB_DRIVER=pgsql');
    putenv('WALLOS_DB_HOST=' . $settings['host']);
    putenv('WALLOS_DB_PORT=' . $settings['port']);
    putenv('WALLOS_DB_NAME=' . $settings['name']);
    putenv('WALLOS_DB_USER=' . $settings['user']);
    putenv('WALLOS_DB_PASSWORD=' . $settings['password']);
    putenv('WALLOS_DB_SSLMODE=' . $settings['sslmode']);

    // libpq reads PGOPTIONS when it opens a connection, so every connection
    // built from this environment starts in the case's own schema — including
    // one the code under test opens for itself, which would otherwise land in
    // public and see another case's rows. The fixture's own connection sets
    // search_path explicitly as well; this covers the ones it never sees.
    putenv('PGOPTIONS=-c search_path=' . $schema);
}

/**
 * A PostgreSQL database with the baseline applied, isolated per case.
 *
 * Each case gets its own schema rather than its own database: creating a schema
 * and loading the baseline costs about 120ms where a database costs far more,
 * and search_path makes the isolation complete as far as the application can
 * tell.
 *
 * The object itself comes from wallos_database_connect(), so a case holds what
 * a request holds. When the fixture built its own WallosPgsqlDatabase the two
 * were the same class by coincidence rather than by construction, and the
 * coincidence hid nothing here only because it happened to hold.
 *
 * @return WallosDatabase
 */
function wallos_test_open_pgsql_database()
{
    static $baseline = null;

    require_once WALLOS_ROOT . '/includes/database/pgsql/database.php';
    require_once WALLOS_ROOT . '/includes/database/configuration.php';

    if ($baseline === null) {
        $baseline = file_get_contents(WALLOS_ROOT . '/includes/database/pgsql/schema.sql');
    }

    $schema = 'wallos_test_' . str_replace('.', '', uniqid('', true));
    wallos_test_pgsql_env($schema);
    wallos_test_pgsql_reachable();

    $db = wallos_database_connect();

    $db->exec('CREATE SCHEMA ' . $schema);
    $db->exec('SET search_path TO ' . $schema);
    $db->exec($baseline);

    // Recorded only once the schema exists, so the cleanup at the end of the
    // run never tries to drop a name that was never created.
    $GLOBALS['wallos_test_pgsql_schemas'][] = $schema;

    return $db;
}

/**
 * Checks once per run that the configured PostgreSQL server answers.
 *
 * wallos_database_connect() is the application's front door, and the
 * application's response to an unusable database is wallos_database_fail():
 * a line on stderr and exit(1). That is right for a request and wrong for a
 * test run, which would stop mid-suite with no failing case and no summary. So
 * the first connection of the run is made here, in a try, and turned into an
 * exception the runner reports against the case that asked for a database.
 *
 * @return void
 */
function wallos_test_pgsql_reachable()
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $settings = wallos_test_pgsql_settings();

    try {
        $probe = new WallosPgsqlDatabase(
            wallos_database_pgsql_dsn($settings),
            $settings['user'],
            $settings['password']
        );
        $probe->close();
    } catch (PDOException $exception) {
        throw new RuntimeException(sprintf(
            'WALLOS_TEST_DRIVER=pgsql but %s:%d/%s is not reachable as %s: %s',
            $settings['host'],
            $settings['port'],
            $settings['name'],
            $settings['user'],
            $exception->getMessage()
        ));
    }

    $checked = true;
}

/**
 * Removes the schemas this run created.
 *
 * @return void
 */
function wallos_test_pgsql_cleanup()
{
    if (wallos_test_driver() !== 'pgsql' || empty($GLOBALS['wallos_test_pgsql_schemas'])) {
        return;
    }

    require_once WALLOS_ROOT . '/includes/database/pgsql/database.php';
    require_once WALLOS_ROOT . '/includes/database/configuration.php';

    // Deliberately not through the environment: PGOPTIONS still names the last
    // case's schema, and a connection that cannot resolve its search_path is a
    // poor place from which to drop schemas.
    putenv('PGOPTIONS');

    $settings = wallos_test_pgsql_settings();
    $db = new WallosPgsqlDatabase(
        wallos_database_pgsql_dsn($settings),
        $settings['user'],
        $settings['password']
    );

    foreach ($GLOBALS['wallos_test_pgsql_schemas'] as $schema) {
        $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
    }

    $db->close();
    $GLOBALS['wallos_test_pgsql_schemas'] = [];
}

/**
 * Marks a case as SQLite-only.
 *
 * Some cases genuinely test SQLite — the migration chain, query plans, the
 * pragma-based schema checks. Skipping those on PostgreSQL is honest; asserting
 * them there would be asserting something nobody claims.
 *
 * @param string $reason
 * @return bool whether the case should stop
 */
function wallos_test_skip_unless_sqlite($reason)
{
    if (wallos_test_driver() === 'sqlite') {
        return false;
    }

    $GLOBALS['wallos_test_skipped'][] = [
        'test' => $GLOBALS['wallos_test_current'],
        'reason' => $reason,
    ];

    return true;
}

/**
 * Marks a case as needing a real PostgreSQL server.
 *
 * The mirror image of the helper above, and it exists for a different reason:
 * not that the behaviour is PostgreSQL-specific, but that exercising it needs a
 * server to connect to. dev/test.sh runs in a throwaway container with no route
 * to the database, so these cases have to say so and stand aside rather than
 * fail for want of a network.
 *
 * @param string $reason
 * @return bool whether the case should stop
 */
function wallos_test_skip_unless_pgsql($reason)
{
    if (wallos_test_driver() === 'pgsql') {
        return false;
    }

    $GLOBALS['wallos_test_skipped'][] = [
        'test' => $GLOBALS['wallos_test_current'],
        'reason' => $reason,
    ];

    return true;
}

/**
 * Builds the real application schema once per run by running the same
 * createdatabase.php and migration chain the container startup uses, then
 * hands out a fresh copy of it per test.
 *
 * The application resolves its database path relative to its own directory, so
 * the schema is built inside a throwaway copy of the source tree rather than in
 * the working copy.
 *
 * @return string Path to the freshly copied database.
 */
function wallos_test_database()
{
    static $template = null;

    if ($template === null) {
        $sandbox = WALLOS_TEST_TMP . '/sandbox';

        // The sandbox and its built database survive in the temp directory
        // across suite runs, and nothing used to invalidate them — the first
        // new migration after that produced a template that was quietly one
        // migration behind, and every consumer of it failed somewhere far
        // downstream ("the source is behind the target schema"). A chain
        // newer than the built database means both are stale.
        $built = $sandbox . '/db/wallos.db';
        if (file_exists($built)) {
            foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $migration) {
                if (filemtime($migration) > filemtime($built)) {
                    foreach (glob($sandbox . '/migrations/*.php') as $copied) {
                        unlink($copied);
                    }
                    unlink($built);
                    foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $fresh) {
                        copy($fresh, $sandbox . '/migrations/' . basename($fresh));
                    }
                    break;
                }
            }
        }

        if (!is_dir($sandbox)) {
            mkdir($sandbox, 0700, true);
            foreach (['endpoints/cronjobs', 'includes', 'migrations', 'db'] as $directory) {
                mkdir($sandbox . '/' . $directory, 0700, true);
            }

            foreach (glob(WALLOS_ROOT . '/migrations/*.php') as $migration) {
                copy($migration, $sandbox . '/migrations/' . basename($migration));
            }
            copy(WALLOS_ROOT . '/endpoints/cronjobs/createdatabase.php', $sandbox . '/endpoints/cronjobs/createdatabase.php');
            copy(WALLOS_ROOT . '/includes/run_migrations.php', $sandbox . '/includes/run_migrations.php');
            // createdatabase.php opens its connection through the boundary, so
            // the sandbox needs to find it. Symlinked rather than copied: a copy
            // is a second file to PHP, so require_once loads it again and the
            // functions are declared twice.
            mkdir($sandbox . '/includes/database', 0700, true);
            symlink(WALLOS_ROOT . '/includes/database/connection.php', $sandbox . '/includes/database/connection.php');
            mkdir($sandbox . '/includes/database/sqlite', 0700, true);
            symlink(WALLOS_ROOT . '/includes/database/sqlite/database.php', $sandbox . '/includes/database/sqlite/database.php');
            // createdatabase.php resolves the configuration to decide whether
            // it is building a SQLite schema at all.
            symlink(WALLOS_ROOT . '/includes/database/configuration.php', $sandbox . '/includes/database/configuration.php');
            symlink(WALLOS_ROOT . '/includes/config_helper.php', $sandbox . '/includes/config_helper.php');
        }

        $databaseFile = $sandbox . '/db/wallos.db';

        if (!file_exists($databaseFile)) {
            // Both scripts print progress and resolve their paths from __DIR__,
            // which is why they run inside the sandbox copy. The boundary is
            // symlinked, so __DIR__ inside it points at the real installation —
            // WALLOS_DB_PATH is what keeps the fixture out of it.
            putenv('WALLOS_DB_PATH=' . $databaseFile);
            ob_start();
            require $sandbox . '/endpoints/cronjobs/createdatabase.php';
            $db = wallos_database_connect($databaseFile);
            require $sandbox . '/includes/run_migrations.php';
            $db->close();
            ob_end_clean();
            putenv('WALLOS_DB_PATH');
        }

        $template = $databaseFile;
    }

    $copy = WALLOS_TEST_TMP . '/case-' . uniqid('', true) . '.db';
    copy($template, $copy);

    return $copy;
}

/**
 * Opens a fresh database with the full application schema.
 *
 * The one fixture every case uses, and it hands over what the application would
 * be handed: wallos_database_connect() with no arguments, resolving the backend
 * from the environment exactly as index.php does. The fixture's job is to make
 * the environment point at a throwaway database, not to build a connection of
 * its own.
 *
 * This matters beyond tidiness. A fixture that constructs its own object can
 * construct one the application never has — and a signature like
 * computeAmountNeededInPeriod(..., SQLite3 $database) then goes on rejecting
 * the real PostgreSQL connection in production while the suite, holding
 * something else, stays green (issue #90).
 *
 * @return WallosDatabase
 */
function wallos_test_open_database()
{
    if (wallos_test_driver() === 'pgsql') {
        return wallos_test_open_pgsql_database();
    }

    // The path goes into the environment rather than into the argument, because
    // the argument is the SQLite-only branch of the boundary: passing a file
    // selects SQLite whatever the configuration says, so a case would keep
    // getting a SQLite connection even under WALLOS_TEST_DRIVER=pgsql. Setting
    // WALLOS_DB_PATH takes the same branch a SQLite installation takes, and
    // leaves it standing for the duration of the case so that code opening its
    // own connection reaches the fixture instead of the developer's real
    // db/wallos.db. wallos_test_reset_env() clears it before the next case.
    putenv('WALLOS_DB_PATH=' . wallos_test_database());

    return wallos_database_connect();
}

/**
 * SQLite3 wrapper that counts statements, so tests can assert that a code path
 * does not issue one query per row.
 */
class WallosCountingDatabase extends WallosSqliteDatabase
{
    public $queryCount = 0;

    public function prepare($query): SQLite3Stmt|false
    {
        $this->queryCount++;

        return parent::prepare($query);
    }

    public function query($query): SQLite3Result|false
    {
        $this->queryCount++;

        return parent::query($query);
    }

    public function querySingle($query, $entireRow = false): mixed
    {
        $this->queryCount++;

        return parent::querySingle($query, $entireRow);
    }

    public function resetQueryCount()
    {
        $this->queryCount = 0;
    }
}

/**
 * The one fixture that does not come from wallos_database_connect(), because
 * counting statements means overriding them: the subclass is the measurement.
 * It extends WallosSqliteDatabase, so it is still the class the boundary hands
 * out on SQLite, and the cases that use it are SQLite-only for that reason.
 *
 * @return WallosCountingDatabase
 */
function wallos_test_open_counting_database()
{
    $db = new WallosCountingDatabase(wallos_test_database());
    $db->busyTimeout(5000);

    return $db;
}

/**
 * Inserts a user together with the currency rows Wallos creates alongside it.
 *
 * @param SQLite3 $db
 * @param int     $id
 * @param string  $username
 */
function wallos_test_create_user($db, $id, $username)
{
    // main_currency is NOT NULL and references currencies, so the account has to
    // point at a row that exists at insert time. Hardcoding 1 worked until the
    // fixture started clearing the seeded currencies: the first account took
    // currency 1 with it, and every later account failed the foreign key.
    // Whatever currency is there right now is good enough — the UPDATE below
    // moves it to this account's own fixture currency.
    $anyCurrency = (int) $db->scalar('SELECT MIN(id) FROM currencies');
    $stmt = $db->prepare("INSERT INTO \"user\" (id, username, email, password, main_currency) VALUES (:id, :username, :email, 'x', :currency)");
    $stmt->bindValue(':currency', $anyCurrency > 0 ? $anyCurrency : 1, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':email', $username . '@example.com', SQLITE3_TEXT);
    $stmt->execute();

    // The fixture replaces the seeded currency list so a case can reason about
    // exactly two rows per user.
    //
    // The insert comes first and the delete second, because user.main_currency
    // references currencies: deleting the seeded rows while the account still
    // points at one of them violates the foreign key. SQLite never enforced it
    // and the failure went unnoticed — the return value was not checked either
    // — so on PostgreSQL every case ran against 36 currencies where SQLite gave
    // it 2. Nothing asserted a count, which is the only reason 272 cases passed
    // on both backends against materially different fixtures.

    foreach ([['EUR', 'Euro', 1.0], ['USD', 'US Dollar', 1.1]] as $index => $currency) {
        $stmt = $db->prepare('INSERT INTO currencies (id, name, symbol, code, rate, user_id) VALUES (:id, :name, :symbol, :code, :rate, :userId)');
        $stmt->bindValue(':id', wallos_test_currency_id($id, $index), SQLITE3_INTEGER);
        $stmt->bindValue(':name', $currency[1], SQLITE3_TEXT);
        $stmt->bindValue(':symbol', $currency[0] === 'EUR' ? "\u{20AC}" : '$', SQLITE3_TEXT);
        $stmt->bindValue(':code', $currency[0], SQLITE3_TEXT);
        $stmt->bindValue(':rate', $currency[2], SQLITE3_FLOAT);
        $stmt->bindValue(':userId', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    $stmt = $db->prepare('UPDATE "user" SET main_currency = :currencyId WHERE id = :id');
    $stmt->bindValue(':currencyId', wallos_test_currency_id($id, 0), SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    // Now that the account points at a fixture currency, the seeded list can go.
    $stmt = $db->prepare('DELETE FROM currencies WHERE user_id = :userId AND id < 9000');
    $stmt->bindValue(':userId', $id, SQLITE3_INTEGER);
    if ($stmt->execute() === false) {
        throw new RuntimeException('fixture could not clear the seeded currencies: ' . $db->lastErrorMsg());
    }

    // A household member, a category and a payment method, because a
    // subscription references all three and PostgreSQL enforces that where
    // SQLite never has. Fixtures that bound arbitrary ids passed on SQLite and
    // failed on PostgreSQL with a foreign-key error naming a constraint rather
    // than the fixture.
    //
    // payer_user_id in particular points at household(id), not at user(id) —
    // this harness used to bind a user id into it, which is the exact confusion
    // the column's name causes throughout the application.
    $stmt = $db->prepare('INSERT INTO household (name, email, user_id) VALUES (:name, :email, :userId)');
    $stmt->bindValue(':name', $username, SQLITE3_TEXT);
    $stmt->bindValue(':email', $username . '@example.com', SQLITE3_TEXT);
    $stmt->bindValue(':userId', $id, SQLITE3_INTEGER);
    $stmt->execute();

    $stmt = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, 1, :userId)');
    $stmt->bindValue(':name', 'Fixture category', SQLITE3_TEXT);
    $stmt->bindValue(':userId', $id, SQLITE3_INTEGER);
    $stmt->execute();

    $stmt = $db->prepare('INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
                          VALUES (:name, :icon, 1, 1, :userId)');
    $stmt->bindValue(':name', 'Fixture card', SQLITE3_TEXT);
    $stmt->bindValue(':icon', '', SQLITE3_TEXT);
    $stmt->bindValue(':userId', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * The household member, category and payment method wallos_test_create_user()
 * made, so a fixture can reference them instead of guessing an id.
 *
 * @param SQLite3|WallosDatabase $db
 * @param int                    $userId
 * @return array{household: int, category: int, payment_method: int}
 */
function wallos_test_user_references($db, $userId)
{
    return [
        'household' => (int) $db->scalar('SELECT id FROM household WHERE user_id = :u ORDER BY id LIMIT 1', [':u' => $userId]),
        'category' => (int) $db->scalar('SELECT id FROM categories WHERE user_id = :u ORDER BY id LIMIT 1', [':u' => $userId]),
        'payment_method' => (int) $db->scalar('SELECT id FROM payment_methods WHERE user_id = :u ORDER BY id LIMIT 1', [':u' => $userId]),
    ];
}



/**
 * Fixture currency ids live above the seeded default list so they never clash.
 *
 * @param int $userId
 * @param int $index   0 = EUR (main currency), 1 = USD
 * @return int
 */
function wallos_test_currency_id($userId, $index)
{
    return 9000 + ($userId * 10) + $index;
}

/**
 * Makes a write to a table fail, on either backend.
 *
 * Checked return values need a write that actually fails, and the only honest
 * way to produce one is to make the database refuse it. These cases used a
 * SQLite RAISE(ABORT) trigger and so ran on one backend, which left the
 * PostgreSQL side of exactly the code paths that behave differently there
 * untested — PostgreSQL aborts the whole transaction on the first error, where
 * SQLite lets the statement fail on its own.
 *
 * Only one block may be in place at a time; that is all any case needs, and it
 * keeps cleanup from having to track a set.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @param string         $operation INSERT, UPDATE or DELETE
 * @param string|null    $column    narrows an UPDATE to one column
 * @return void
 */
function wallos_test_block_writes($db, $table, $operation, $column = null)
{
    $operation = strtoupper($operation);
    $of = ($column !== null && $operation === 'UPDATE') ? ' OF "' . $column . '"' : '';

    if ($db->driver() === 'sqlite') {
        // SQLite names a trigger in its own namespace and drops it by name.
        $db->exec('CREATE TRIGGER wallos_test_block BEFORE ' . $operation . $of . ' ON "' . $table . '"
                   BEGIN SELECT RAISE(ABORT, \'blocked by test\'); END');

        return;
    }

    // FOR EACH ROW, so a statement matching no rows is not blocked. That
    // matches SQLite and it matches what is being tested: a write that fails,
    // not a write with nothing to do.
    $db->exec('CREATE OR REPLACE FUNCTION wallos_test_block_fn() RETURNS trigger
               LANGUAGE plpgsql AS $fn$ BEGIN RAISE EXCEPTION \'blocked by test\'; END $fn$');
    $db->exec('CREATE TRIGGER wallos_test_block BEFORE ' . $operation . $of . ' ON "' . $table . '"
               FOR EACH ROW EXECUTE FUNCTION wallos_test_block_fn()');
}

/**
 * Removes the block.
 *
 * On PostgreSQL a failed statement inside an explicit transaction leaves the
 * connection unable to run anything until it is rolled back — including this
 * DROP. Code that handles its own failure has already rolled back by the time a
 * case gets here; the guard is for the case that did not, so cleanup reports
 * the original failure rather than a confusing second one.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @return void
 */
function wallos_test_unblock_writes($db, $table)
{
    if ($db->driver() === 'sqlite') {
        $db->exec('DROP TRIGGER IF EXISTS wallos_test_block');

        return;
    }

    if (@$db->exec('SELECT 1') === false) {
        @$db->rollBack();
    }

    $db->exec('DROP TRIGGER IF EXISTS wallos_test_block ON "' . $table . '"');
    $db->exec('DROP FUNCTION IF EXISTS wallos_test_block_fn()');
}
