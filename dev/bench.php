<?php
/*
  The database half of dev/benchmark.sh.

      php dev/bench.php target
      php dev/bench.php account <username>
      php dev/bench.php subscriptions <username> <count>
      php dev/bench.php notifications
      php dev/bench.php cleanup
      php dev/bench.php rates-preflight [seconds]
      php dev/bench.php measure <script> <runs> <seconds>

  It exists because the benchmark used to do its writing in `php -r '...'`
  snippets that opened db/wallos.db as a file. On a PostgreSQL instance those
  snippets wrote where nothing was reading: the pages being timed were served
  from PostgreSQL, so the "entries" column reported timings against whatever
  PostgreSQL happened to hold rather than against 100, 1000 and 5000 rows
  (issue #91).

  Two rules follow from that, and they are the whole design of this file.

  First, there is no way to name a database here. Every command connects with
  wallos_database_connect() and no argument, which is the same call index.php
  makes, so "the database under test" and "the database this writes to" cannot
  drift apart.

  Second, the cleanup deletes by prefix and reports what it actually removed,
  counted before and after. The old one deleted from a hardcoded path and the
  script then printed "Seeded data removed." whether or not anything had been:
  on the instance where this was found, that path held the SQLite backup being
  kept as the rollback route out of PostgreSQL.
*/

if (php_sapi_name() !== 'cli') {
    die("This script is meant to be run from the command line.\n");
}

require_once __DIR__ . '/../includes/database/connection.php';
require_once __DIR__ . '/../includes/database/configuration.php';
require_once __DIR__ . '/../includes/user_deletion.php';

/** Rows this tool creates for one account, replaced on every size change. */
const BENCH_PREFIX = 'bench-';

/** Rows dev/seed.php creates: whole accounts with their own children. */
const BENCH_SEED_PREFIX = 'seed-';

/**
 * @param string $message
 * @return never
 */
function bench_fail($message)
{
    fwrite(STDERR, 'bench: ' . $message . "\n");
    exit(1);
}

/**
 * The connection the application would be handed, and the only one this file
 * knows how to obtain.
 *
 * @return WallosDatabase
 */
function bench_connect()
{
    return wallos_database_connect();
}

/**
 * Where the connection actually points, in one line.
 *
 * Printed in the benchmark's header so that a table of figures always carries
 * the backend it was measured against. The run that produced issue #91 printed
 * a table that looked like every other table and had been measured against a
 * database nobody was reading.
 *
 * @param WallosDatabase $db
 * @return string
 */
function bench_target($db)
{
    $configuration = wallos_database_configuration();

    if ($db->driver() === 'pgsql') {
        return sprintf(
            'pgsql %s@%s:%d/%s, schema %s',
            $configuration['pgsql']['user'],
            $configuration['pgsql']['host'],
            $configuration['pgsql']['port'],
            $configuration['pgsql']['name'],
            (string) $db->scalar('SELECT current_schema()')
        );
    }

    return 'sqlite ' . $configuration['sqlite']['path'];
}

/**
 * @param WallosDatabase $db
 * @param string         $username
 * @return int 0 when the account is not in this database
 */
function bench_account_id($db, $username)
{
    return (int) $db->scalar('SELECT id FROM "user" WHERE username = :username', [':username' => $username]);
}

/**
 * The account the benchmark signs in as, or a refusal naming both sides.
 *
 * This is the cross-check that would have stopped the run in issue #91 within a
 * second: the pages are fetched over HTTP as this account, so if the account is
 * not in the database this process just opened, the two are not the same
 * database and nothing measured afterwards would mean anything.
 *
 * @param WallosDatabase $db
 * @param string         $username
 * @return int
 */
function bench_require_account($db, $username)
{
    $id = bench_account_id($db, $username);

    if ($id === 0) {
        bench_fail(sprintf(
            'no account "%s" in %s — the benchmark signs in as that account over HTTP, so this is'
                . ' not the database the measured pages read from.',
            $username,
            bench_target($db)
        ));
    }

    return $id;
}

/**
 * A row of $table belonging to $userId, created with the bench prefix if the
 * account has none.
 *
 * Subscriptions reference a category and a household member, and PostgreSQL
 * enforces both where SQLite never has. Binding an arbitrary id — or the
 * account's own id into payer_user_id, which points at household(id) and not at
 * user(id) — is the mistake this repository has made to itself more than once.
 *
 * @param WallosDatabase $db
 * @param string         $table    'categories' or 'household'
 * @param int            $userId
 * @return int
 */
function bench_reference($db, $table, $userId)
{
    $existing = (int) $db->scalar(
        'SELECT id FROM ' . $table . ' WHERE user_id = :userId ORDER BY id LIMIT 1',
        [':userId' => $userId]
    );

    if ($existing > 0) {
        return $existing;
    }

    $names = ['categories' => 'category', 'household' => 'payer'];
    $name = BENCH_PREFIX . $names[$table];

    if ($table === 'household') {
        $statement = $db->prepare('INSERT INTO household (name, email, user_id) VALUES (:name, :email, :userId)');
        $statement->bindValue(':email', $name . '@example.com');
    } else {
        $statement = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, 1, :userId)');
    }

    $statement->bindValue(':name', $name);
    $statement->bindValue(':userId', $userId);

    if ($statement->execute() === false) {
        bench_fail('could not create a ' . $table . ' row for the benchmark account: ' . $db->lastErrorMsg());
    }

    return (int) $db->lastInsertId();
}

/**
 * Moves one account's subscription list to exactly $target rows.
 *
 * @param WallosDatabase $db
 * @param string         $username
 * @param int            $target
 * @return int the number of rows the account now has
 */
function bench_set_subscriptions($db, $username, $target)
{
    $userId = bench_require_account($db, $username);

    $currency = (int) $db->scalar('SELECT main_currency FROM "user" WHERE id = :id', [':id' => $userId]);
    if ($currency === 0) {
        bench_fail('the account "' . $username . '" has no main currency, so a subscription cannot reference one.');
    }

    $category = bench_reference($db, 'categories', $userId);
    $payer = bench_reference($db, 'household', $userId);

    $delete = $db->prepare('DELETE FROM subscriptions WHERE user_id = :userId AND name LIKE :prefix');
    $delete->bindValue(':userId', $userId);
    $delete->bindValue(':prefix', BENCH_PREFIX . '%');
    $delete->execute();

    $insert = $db->prepare('INSERT INTO subscriptions
        (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id,
         notify, inactive, user_id, auto_renew)
        VALUES (:name, :price, :currency, :next, 3, 1, :payer, :category, 0, 0, :userId, 1)');

    $db->beginTransaction();

    for ($i = 0; $i < $target; $i++) {
        $insert->bindValue(':name', BENCH_PREFIX . $i);
        $insert->bindValue(':price', 9.99);
        $insert->bindValue(':currency', $currency);
        $insert->bindValue(':next', date('Y-m-d', strtotime('+' . ($i % 40) . ' days')));
        $insert->bindValue(':payer', $payer);
        $insert->bindValue(':category', $category);
        $insert->bindValue(':userId', $userId);

        if ($insert->execute() === false) {
            $db->rollBack();
            bench_fail('insert ' . $i . ' failed: ' . $db->lastErrorMsg());
        }

        $insert->reset();
    }

    if ($db->commit() === false) {
        bench_fail('the inserts did not commit: ' . $db->lastErrorMsg());
    }

    // Counted rather than assumed: on PostgreSQL a failed statement aborts the
    // whole transaction and a later commit reports success having written
    // nothing, which is precisely how a benchmark ends up timing an empty list.
    return (int) $db->scalar(
        'SELECT COUNT(*) FROM subscriptions WHERE user_id = :userId AND name LIKE :prefix',
        [':userId' => $userId, ':prefix' => BENCH_PREFIX . '%']
    );
}

/**
 * Turns email notifications on for the seeded accounts, and nothing else.
 *
 * Scoped on purpose. The version this replaces ran
 * `UPDATE subscriptions SET notify = 1` across every row, real accounts
 * included, and an UPDATE against a `notifications.provider` column that exists
 * in no schema Wallos ships. Both errors were discarded by `2>/dev/null || true`,
 * so the cron column was measured with no account subscribed to anything.
 *
 * @param WallosDatabase $db
 * @return array{accounts: int, subscriptions: int}
 */
function bench_enable_notifications($db)
{
    $accounts = [];
    $result = $db->query("SELECT id FROM \"user\" WHERE username LIKE '" . BENCH_SEED_PREFIX . "%'");
    while ($result !== false && $row = $result->fetchArray()) {
        $accounts[] = (int) $row['id'];
    }

    foreach ($accounts as $userId) {
        $existing = (int) $db->scalar(
            'SELECT COUNT(*) FROM email_notifications WHERE user_id = :userId',
            [':userId' => $userId]
        );

        $statement = $existing > 0
            ? $db->prepare('UPDATE email_notifications SET enabled = 1, smtp_mode = :mode WHERE user_id = :userId')
            : $db->prepare('INSERT INTO email_notifications (enabled, smtp_mode, user_id) VALUES (1, :mode, :userId)');
        $statement->bindValue(':mode', 'instance');
        $statement->bindValue(':userId', $userId);
        $statement->execute();
    }

    // date('now', '+2 days') is SQLite's spelling, which PostgreSQL has never
    // understood; the date is computed here so one statement runs on both.
    $update = $db->prepare('UPDATE subscriptions SET notify = 1, next_payment = :next WHERE name LIKE :prefix');
    $update->bindValue(':next', date('Y-m-d', strtotime('+2 days')));
    $update->bindValue(':prefix', BENCH_SEED_PREFIX . '%');
    $update->execute();

    $subscriptions = (int) $db->scalar(
        'SELECT COUNT(*) FROM subscriptions WHERE notify = 1 AND name LIKE :prefix',
        [':prefix' => BENCH_SEED_PREFIX . '%']
    );

    return ['accounts' => count($accounts), 'subscriptions' => $subscriptions];
}

/**
 * Everything the benchmark and dev/seed.php created, and only that.
 *
 * Children before parents, because PostgreSQL enforces the references SQLite
 * declares and ignores. The counts come from a COUNT before and a COUNT after,
 * so the summary is a measurement rather than the script's opinion of itself.
 *
 * @param WallosDatabase $db
 * @return array<string, int> table => rows removed
 */
/**
 * Removes one table's rows for the listed accounts.
 *
 * The ids are bound rather than interpolated. Only the table and column names
 * are assembled into the statement, because no backend binds an identifier, and
 * they come from wallos_user_deletion_plan(), which takes only names matching
 * [A-Za-z_][A-Za-z0-9_]* out of the live schema.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @param string         $column
 * @param int[]          $ids
 */
function bench_delete_owned($db, $table, $column, array $ids)
{
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $db->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders . ')');

    if ($delete === false) {
        return;
    }

    foreach (array_values($ids) as $position => $id) {
        $delete->bindValue($position + 1, (int) $id);
    }

    $delete->execute();
}

/**
 * Rows in the per-account tables whose account no longer exists.
 *
 * The cleanup used to say what it had removed and stop there, which is how
 * eleven notification rows per run went unnoticed (issue #98): the figure was
 * true and answered a different question than the one that mattered. This asks
 * the one that mattered — is anything left pointing at an account that is gone
 * — and asks it of every table with a user_id rather than of the one somebody
 * remembered.
 *
 * It counts rather than deletes. Orphans from another source are not this
 * script's to clean up, and a benchmark that quietly repairs a database is
 * worse than one that leaves a mess it names.
 *
 * @param WallosDatabase $db
 * @return array<string, int> table => orphan rows
 */
function bench_orphans($db)
{
    $orphans = [];

    foreach (wallos_user_deletion_plan($db) as $step) {
        if ($step['table'] === 'user') {
            continue;
        }

        // NULL and 0 are excluded on purpose. Older installations carry system
        // rows — payment methods in particular — that belong to nobody by
        // convention rather than by accident, and reporting them as orphans
        // would make this report noise on exactly the installations that need
        // it. An orphan is a row naming an account that does not exist.
        $column = 't.' . $step['column'];
        $count = 'SELECT COUNT(*) FROM ' . $step['table'] . ' t'
            . ' WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> 0'
            . ' AND NOT EXISTS (SELECT 1 FROM "user" u WHERE u.id = ' . $column . ')';
        $found = (int) $db->scalar($count);

        if ($found > 0) {
            $orphans[$step['table']] = $found;
        }
    }

    return $orphans;
}

function bench_cleanup($db)
{
    // The fourth element is what keeps a cleanup from creating the very thing
    // dev/snapshot.sh exists to find: a row something still points at is left
    // where it is rather than deleted out from under it. SQLite would allow the
    // delete and leave an orphan behind, PostgreSQL would refuse and abort the
    // transaction, and neither is what a tidy-up should be doing.
    $plan = [
        ['subscriptions', 'name', [BENCH_PREFIX, BENCH_SEED_PREFIX], null],
        ['household', 'name', [BENCH_PREFIX, BENCH_SEED_PREFIX],
            'id NOT IN (SELECT payer_user_id FROM subscriptions WHERE payer_user_id IS NOT NULL)'],
        ['categories', 'name', [BENCH_PREFIX, BENCH_SEED_PREFIX],
            'id NOT IN (SELECT category_id FROM subscriptions WHERE category_id IS NOT NULL)'],
        ['user', 'username', [BENCH_SEED_PREFIX], null],
        ['currencies', 'name', [BENCH_SEED_PREFIX],
            'id NOT IN (SELECT main_currency FROM "user" WHERE main_currency IS NOT NULL)'
                . ' AND id NOT IN (SELECT currency_id FROM subscriptions WHERE currency_id IS NOT NULL)'],
    ];

    $removed = [];

    // The seeded accounts' notification rows go first: they are keyed by
    // user_id, and once the account is gone nothing names them any more.
    $seededAccounts = [];
    $result = $db->query("SELECT id FROM \"user\" WHERE username LIKE '" . BENCH_SEED_PREFIX . "%'");
    while ($result !== false && $row = $result->fetchArray()) {
        $seededAccounts[] = (int) $row['id'];
    }

    if ($seededAccounts !== []) {
        // Every per-account table except the five below, which the ordered plan
        // handles with the guards their foreign keys need. Named one at a time,
        // this was email_notifications alone — correct, and the shape that
        // leaves the next table behind. dev/seed.php missed the same table for
        // the same reason (issue #98), so both now ask the schema instead:
        // wallos_user_deletion_plan() lists every base table with a user_id.
        $ordered = ['subscriptions', 'household', 'categories', 'user', 'currencies'];

        foreach (wallos_user_deletion_plan($db) as $step) {
            if (in_array($step['table'], $ordered, true)) {
                continue;
            }

            $count = 'SELECT COUNT(*) FROM ' . $step['table'];
            $before = (int) $db->scalar($count);
            bench_delete_owned($db, $step['table'], $step['column'], $seededAccounts);
            $after = (int) $db->scalar($count);

            if ($before - $after > 0) {
                $removed[$step['table']] = $before - $after;
            }
        }
    }

    foreach ($plan as $step) {
        list($table, $column, $prefixes, $guard) = $step;
        $quoted = $table === 'user' ? '"user"' : $table;

        $conditions = [];
        foreach ($prefixes as $prefix) {
            $conditions[] = $column . " LIKE '" . $prefix . "%'";
        }
        $mine = '(' . implode(' OR ', $conditions) . ')';
        $where = ' WHERE ' . $mine . ($guard === null ? '' : ' AND (' . $guard . ')');

        $before = (int) $db->scalar('SELECT COUNT(*) FROM ' . $quoted . $where);

        if ($before > 0 && $db->exec('DELETE FROM ' . $quoted . $where) === false) {
            bench_fail('could not remove the seeded rows from ' . $table . ': ' . $db->lastErrorMsg());
        }

        $after = (int) $db->scalar('SELECT COUNT(*) FROM ' . $quoted . $where);
        $removed[$table] = $before - $after;

        if ($after !== 0) {
            bench_fail($after . ' seeded row(s) are still in ' . $table . ' after the delete.');
        }

        // Rows that carry the prefix and survived because something still points
        // at them. Reported rather than forced: a benchmark is not the place to
        // decide that a referenced row should go.
        $kept = (int) $db->scalar('SELECT COUNT(*) FROM ' . $quoted . ' WHERE ' . $mine);
        if ($kept > 0) {
            $removed[$table . ' (kept, still referenced)'] = $kept;
        }
    }

    return $removed;
}

/**
 * Runs a script with a wall-clock bound and returns how long it took.
 *
 * The bound is the point. dev/benchmark.sh measures the currency cron five
 * times per tier, and the development and test environments both configure a
 * deliberately invalid provider key so that no run spends real quota. Each call
 * then waits for a provider that will never answer: one tier alone took over
 * eleven minutes in the run that produced issue #91, and the figure it would
 * eventually have printed was the network timeout rather than the code.
 *
 * @param string[] $command the interpreter and its arguments
 * @param float    $seconds
 * @param bool     $capture whether the output is wanted
 * @return array{ms: float, timedOut: bool, exit: int, output: string}
 */
function bench_run_bounded($command, $seconds, $capture = false)
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => $capture ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
        2 => $capture ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
    ];

    $started = microtime(true);

    // The command is an array, so the interpreter is started directly and no
    // shell is involved: nothing here is ever parsed as shell syntax, and the
    // process handle refers to the PHP process itself. With a string, the
    // handle would refer to a shell and terminating it would leave the PHP
    // process behind it still waiting on the provider — which is the failure
    // this function exists to prevent.
    $process = @proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        bench_fail('could not start ' . implode(' ', $command));
    }

    $output = '';
    if ($capture) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    $timedOut = false;
    $status = ['running' => true, 'exitcode' => -1];

    while (true) {
        $status = proc_get_status($process);

        if ($capture) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
        }

        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $started) >= $seconds && !$timedOut) {
            $timedOut = true;
            proc_terminate($process, 9);
            // The loop continues until the process is really gone, so that
            // proc_close() below does not block on a child still being reaped.
        }

        usleep(20000);
    }

    $elapsed = (microtime(true) - $started) * 1000;

    if ($capture) {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    proc_close($process);

    return [
        'ms' => $elapsed,
        'timedOut' => $timedOut,
        'exit' => (int) $status['exitcode'],
        'output' => $output,
    ];
}

/**
 * Whether measuring the currency cron would measure anything.
 *
 * Four answers, and the reason each one is not simply "run it anyway":
 *
 *   unconfigured  no provider is set, so every user is skipped in microseconds
 *                 and the column would read like a very fast job.
 *   refused       a provider is set and answers with an error — usually the
 *                 deliberately invalid key that both dev/compose.yaml and
 *                 docs/test-instance.md prescribe so no run spends real quota.
 *                 The figure would be the error path, not the work.
 *   timeout       a provider is set and does not answer within the bound. The
 *                 figure would be the network timeout.
 *   ok            worth measuring.
 *
 * @param int $seconds bound for the probe run
 * @return array{verdict: string, note: string, ms: float}
 */
function bench_rates_preflight($seconds)
{
    require_once __DIR__ . '/../includes/integration_config.php';

    $db = bench_connect();
    $userId = (int) $db->scalar('SELECT MIN(id) FROM "user"');

    if ($userId === 0) {
        return ['verdict' => 'unconfigured', 'note' => 'there is no account to update rates for', 'ms' => 0.0];
    }

    $config = wallos_get_effective_currency_config($db, $userId);
    $db->close();

    if (empty($config['valid'])) {
        return [
            'verdict' => 'unconfigured',
            'note' => $config['notes'][0] ?? 'no currency provider is configured',
            'ms' => 0.0,
        ];
    }

    // The probe asks the function the cron job asks, and reads its return
    // value. Reading the job's own output instead means matching a sentence:
    // this did, on "Exchange rates update skipped.", and stopped working the
    // day that sentence became "Exchange rates update failed." — after which a
    // provider that cannot be reached was reported as one worth measuring.
    $probe = 'require ' . var_export(dirname(__DIR__) . '/includes/database/connection.php', true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/includes/integration_config.php', true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/includes/currency_provider.php', true) . ';'
        . '$db = wallos_database_connect();'
        . '$id = (int) $db->scalar(\'SELECT MIN(id) FROM "user"\');'
        . '$result = wallos_update_exchange_rates_for_user($db, $id);'
        . 'echo $result["success"] ? "SUCCESS" : "FAILURE " . $result["message"];';

    $run = bench_run_bounded([PHP_BINARY, '-r', $probe], $seconds, true);

    if ($run['timedOut']) {
        return [
            'verdict' => 'timeout',
            'note' => sprintf('the provider did not answer within %ds', $seconds),
            'ms' => $run['ms'],
        ];
    }

    if (strpos($run['output'], 'SUCCESS') === false) {
        $message = trim(str_replace('FAILURE', '', strip_tags($run['output'])));

        return [
            'verdict' => 'refused',
            'note' => $message !== '' ? $message : 'the provider refused the request',
            'ms' => $run['ms'],
        ];
    }

    return ['verdict' => 'ok', 'note' => 'the provider answered', 'ms' => $run['ms']];
}

// ------------------------------------------------------------------ dispatch

// Only when invoked directly, so that a test can include this file for the
// functions above without the dispatch running.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    return;
}

$command = isset($argv[1]) ? $argv[1] : '';

switch ($command) {
    case 'target':
        $db = bench_connect();
        echo bench_target($db), "\n";
        $db->close();
        break;

    case 'account':
        $db = bench_connect();
        echo bench_require_account($db, (string) ($argv[2] ?? '')), "\n";
        $db->close();
        break;

    case 'subscriptions':
        $db = bench_connect();
        echo bench_set_subscriptions($db, (string) ($argv[2] ?? ''), max(0, (int) ($argv[3] ?? 0))), "\n";
        $db->close();
        break;

    case 'notifications':
        $db = bench_connect();
        $enabled = bench_enable_notifications($db);
        printf("%d account(s), %d subscription(s)\n", $enabled['accounts'], $enabled['subscriptions']);
        $db->close();
        break;

    case 'cleanup':
        $db = bench_connect();
        $removed = bench_cleanup($db);
        foreach ($removed as $table => $rows) {
            printf("  %-22s %d row(s) removed\n", $table, $rows);
        }

        // What was removed is not the same question as what is left. Reporting
        // only the first is how eleven orphaned notification rows survived
        // every run of this script (issue #98) behind a line that was true.
        $orphans = bench_orphans($db);

        if ($orphans !== []) {
            printf("\n  Rows left pointing at an account that no longer exists:\n");

            foreach ($orphans as $table => $rows) {
                printf("  %-22s %d row(s)\n", $table, $rows);
            }

            printf("  Not removed here: orphans from another source are not this script's to\n"
                . "  repair, and dev/migrate-to-pgsql.php refuses a database holding them.\n");
        }

        $db->close();
        break;

    case 'rates-preflight':
        $preflight = bench_rates_preflight(max(1, (int) ($argv[2] ?? 20)));
        printf("%s\t%s\n", $preflight['verdict'], $preflight['note']);
        break;

    case 'measure':
        $script = dirname(__DIR__) . '/' . ltrim((string) ($argv[2] ?? ''), '/');
        $runs = max(1, (int) ($argv[3] ?? 5));
        $seconds = max(1, (int) ($argv[4] ?? 60));

        if (!is_file($script)) {
            bench_fail('no such script: ' . $script);
        }

        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            $run = bench_run_bounded([PHP_BINARY, $script], $seconds);

            if ($run['timedOut']) {
                // One bounded run is enough to know the rest would be the same,
                // and four more of them cost four more timeouts.
                echo "timeout\n";
                exit(0);
            }

            $times[] = $run['ms'];
        }

        sort($times);
        printf("%d\n", (int) round($times[intdiv(count($times), 2)]));
        break;

    default:
        fwrite(STDERR, "Usage: php dev/bench.php <target|account|subscriptions|notifications|cleanup"
            . "|rates-preflight|measure> [arguments]\n");
        exit(2);
}
