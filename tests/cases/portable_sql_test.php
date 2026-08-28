<?php
/*
  The queries that had to be rewritten so a second backend can run them.

  Two of those rewrites moved a comparison out of SQL and into PHP, because
  neither database has an expression both of them accept against the columns
  Wallos stores dates in. That makes the boundary condition a decision this
  repository now owns rather than one SQLite made: the hour a password-reset
  token stays usable, and whether a subscription due today is upcoming or
  overdue. Both are pinned below, at the second and at the day.

  The queries are read out of the application rather than restated here, so a
  case cannot keep passing against a copy of a predicate the application has
  stopped running.
*/

/**
 * The SQL literal on the first line of $file that contains $marker.
 *
 * @param string $file   path relative to the repository root
 * @param string $marker a fragment unique to the line the query starts on
 * @return string
 */
function portable_sql($file, $marker)
{
    $lines = preg_split('/\R/', file_get_contents(WALLOS_ROOT . '/' . $file));

    foreach ($lines as $line) {
        if (strpos($line, $marker) === false) {
            continue;
        }

        if (preg_match('/"([^"]*\b(?:SELECT|INSERT|UPDATE|DELETE)\b[^"]*)"/i', $line, $found)
            || preg_match("/'([^']*\\b(?:SELECT|INSERT|UPDATE|DELETE)\\b[^']*)'/i", $line, $found)) {
            return $found[1];
        }
    }

    wallos_test_fail(sprintf('no SQL found in %s on a line containing "%s"', $file, $marker));

    // Matches nothing, so the assertions report the boundary that was missed
    // rather than a fatal error somewhere further down.
    return 'SELECT 1 WHERE 1 = 0';
}

/**
 * Every PHP file the application itself runs.
 *
 * Vendored libraries and the SQLite side of the database boundary are left
 * out because they are allowed to be backend-specific. So are the migrations:
 * a PostgreSQL installation starts from the generated baseline schema with
 * every migration already recorded, so the chain only ever runs against
 * SQLite.
 *
 * @return array paths relative to the repository root
 */
function portable_sql_application_files()
{
    $skip = ['libs', 'migrations', 'tests', 'dev', 'database', 'i18n'];

    $directories = new RecursiveDirectoryIterator(WALLOS_ROOT, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($directories, function ($entry) use ($skip) {
        if (!$entry->isDir()) {
            return $entry->getExtension() === 'php';
        }

        $name = $entry->getFilename();

        // Dot directories hold .git and the agent worktrees, which are whole
        // checkouts of this repository nested inside it.
        return $name[0] !== '.' && !in_array($name, $skip, true);
    });

    $files = [];
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen(WALLOS_ROOT) + 1);
    }

    sort($files);

    return $files;
}

/**
 * @param WallosDatabase $db
 * @param string         $token
 * @param string         $createdAt
 */
function portable_sql_insert_reset($db, $token, $createdAt)
{
    $stmt = $db->prepare('INSERT INTO password_resets (user_id, email, token, created_at)
                          VALUES (1, :email, :token, :createdAt)');
    $stmt->bindValue(':email', $token . '@example.com');
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':createdAt', $createdAt);
    $stmt->execute();
}

/**
 * Runs the reset page's lookup exactly as passwordreset.php runs it.
 *
 * @param WallosDatabase $db
 * @param string         $sql
 * @param string         $token
 * @param string         $validAfter
 * @return bool whether the token would still be accepted
 */
function portable_sql_token_accepted($db, $sql, $token, $validAfter)
{
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':email', $token . '@example.com');
    $stmt->bindValue(':validAfter', $validAfter);
    $row = $stmt->execute()->fetchArray();

    // The page runs this as both a COUNT and a row lookup; a count of zero and
    // no row are the same answer.
    return $row !== false && (!isset($row[0]) || !is_numeric($row[0]) || ((int) $row[0]) > 0);
}

/**
 * @param WallosDatabase $db
 * @param string         $name
 * @param string         $nextPayment
 */
function portable_sql_insert_subscription($db, $name, $nextPayment)
{
    $stmt = $db->prepare('INSERT INTO subscriptions
                          (name, price, currency_id, next_payment, cycle, frequency,
                           payer_user_id, category_id, notify, inactive, auto_renew, user_id)
                          VALUES (:name, 1.0, :currencyId, :nextPayment, 3, 1, 1, 1, 0, 0, 0, 1)');
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0));
    $stmt->bindValue(':nextPayment', $nextPayment);
    $stmt->execute();
}

/**
 * @param WallosDatabase $db
 * @param string         $sql
 * @param string         $today
 * @return array the names the query returns, in the order it returns them
 */
function portable_sql_subscription_names($db, $sql, $today)
{
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', 1);
    $stmt->bindValue(':today', $today);
    $result = $stmt->execute();

    $names = [];
    while ($row = $result->fetchArray()) {
        $names[] = $row['name'];
    }

    return $names;
}

wallos_test('a password reset token is usable for one hour, to the second', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // One reading of "now" for the whole case, so a second ticking over
    // between two calls cannot move a token across the boundary.
    $now = time();
    $validAfter = gmdate('Y-m-d H:i:s', $now - 3600);

    $ages = ['fresh' => 0, 'just-inside' => 3599, 'exactly-an-hour' => 3600, 'just-outside' => 3601];
    foreach ($ages as $token => $secondsAgo) {
        portable_sql_insert_reset($db, $token, gmdate('Y-m-d H:i:s', $now - $secondsAgo));
    }

    foreach (['$matchCount = ', '$resetQuery = '] as $marker) {
        $sql = portable_sql('passwordreset.php', $marker);

        assert_true(portable_sql_token_accepted($db, $sql, 'fresh', $validAfter),
            $marker . ' accepts a token created just now');
        assert_true(portable_sql_token_accepted($db, $sql, 'just-inside', $validAfter),
            $marker . ' accepts a token one second short of an hour old');
        assert_true(!portable_sql_token_accepted($db, $sql, 'exactly-an-hour', $validAfter),
            $marker . ' rejects a token exactly an hour old');
        assert_true(!portable_sql_token_accepted($db, $sql, 'just-outside', $validAfter),
            $marker . ' rejects a token one second over an hour old');
    }

    $db->close();
});

wallos_test('the cleanup cron deletes exactly the tokens the reset page refuses', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $now = time();
    $validAfter = gmdate('Y-m-d H:i:s', $now - 3600);

    $ages = ['fresh' => 0, 'just-inside' => 3599, 'exactly-an-hour' => 3600, 'just-outside' => 3601];
    foreach ($ages as $token => $secondsAgo) {
        portable_sql_insert_reset($db, $token, gmdate('Y-m-d H:i:s', $now - $secondsAgo));
    }

    $lookup = portable_sql('passwordreset.php', '$resetQuery = ');
    $accepted = [];
    foreach (array_keys($ages) as $token) {
        if (portable_sql_token_accepted($db, $lookup, $token, $validAfter)) {
            $accepted[] = $token;
        }
    }

    $stmt = $db->prepare(portable_sql('endpoints/cronjobs/cleanupresettokens.php', 'DELETE FROM password_resets'));
    $stmt->bindValue(':expiredBefore', $validAfter);
    $stmt->execute();

    $surviving = [];
    $result = $db->query('SELECT token FROM password_resets ORDER BY token');
    while ($row = $result->fetchArray()) {
        $surviving[] = $row['token'];
    }
    sort($accepted);

    // A gap either way is a bug users can see: tokens deleted while the page
    // still offers them, or expired tokens kept forever.
    assert_same($accepted, $surviving,
        'the rows the cron keeps must be exactly the rows the reset page accepts');
    assert_same(['fresh', 'just-inside'], $surviving, 'and that is everything under an hour old');

    $db->close();
});

wallos_test('a subscription due today is upcoming, never overdue', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // The dashboard compares against the UTC date, which is what SQLite's
    // date('now') answered before the predicate moved into PHP.
    $now = time();
    $today = gmdate('Y-m-d', $now);
    portable_sql_insert_subscription($db, 'yesterday', gmdate('Y-m-d', $now - 86400));
    portable_sql_insert_subscription($db, 'today', $today);
    portable_sql_insert_subscription($db, 'tomorrow', gmdate('Y-m-d', $now + 86400));

    $upcoming = portable_sql_subscription_names($db, portable_sql('index.php', 'next_payment >= '), $today);
    $overdue = portable_sql_subscription_names($db, portable_sql('index.php', 'next_payment < '), $today);

    assert_same(['today', 'tomorrow'], $upcoming, 'today belongs to the upcoming list, and is first');
    assert_same(['yesterday'], $overdue, 'only a payment date already past is overdue');

    $db->close();
});

wallos_test('the date boundaries are computed in UTC, where SQLite computed them', function () {
    // date() would follow whatever timezone PHP is configured with, and the
    // values being compared against are written by CURRENT_TIMESTAMP, which is
    // not. A token would then expire in fifty-nine minutes or in three hours
    // depending on the host, and a subscription would move between the
    // upcoming and overdue lists a few hours early.
    $expressions = [
        'index.php' => "gmdate('Y-m-d')",
        'passwordreset.php' => "gmdate('Y-m-d H:i:s', time() - 3600)",
        'endpoints/cronjobs/cleanupresettokens.php' => "gmdate('Y-m-d H:i:s', time() - 3600)",
    ];

    foreach ($expressions as $file => $expression) {
        assert_contains($expression, file_get_contents(WALLOS_ROOT . '/' . $file),
            $file . ' must compute its boundary in UTC');
    }

    $queries = [
        'passwordreset.php' => ['$matchCount = ', '$resetQuery = '],
        'index.php' => ['next_payment >= ', 'next_payment < '],
        'endpoints/cronjobs/cleanupresettokens.php' => ['DELETE FROM password_resets'],
    ];

    foreach ($queries as $file => $markers) {
        foreach ($markers as $marker) {
            assert_not_contains("('now'", portable_sql($file, $marker),
                $file . ' (' . $marker . ') must not ask the database for the current time');
        }
    }
});

wallos_test('no application query is written in a dialect only SQLite accepts', function () {
    // Backticks are a MySQL borrowing SQLite tolerates and PostgreSQL rejects
    // outright; the standard spelling, "order", is understood by both. The
    // conflict clause is the same story: ON CONFLICT is what both databases
    // have.
    $backtickIdentifier = '/`[A-Za-z_][A-Za-z0-9_]*`/';
    $sqliteConflictClause = '/\bINSERT\s+OR\s+\w+\s+INTO\b/i';

    $scanned = 0;
    foreach (portable_sql_application_files() as $file) {
        $scanned++;

        foreach (preg_split('/\R/', file_get_contents(WALLOS_ROOT . '/' . $file)) as $number => $line) {
            if (!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|ORDER\s+BY)\b/i', $line)) {
                continue;
            }

            $where = $file . ':' . ($number + 1);

            assert_true(!preg_match($backtickIdentifier, $line),
                $where . ' quotes an identifier with a backtick; use double quotes');
            assert_true(!preg_match($sqliteConflictClause, $line),
                $where . ' uses a SQLite-only conflict clause; use ON CONFLICT');
        }
    }

    assert_true($scanned > 100, 'the scan found the application (only ' . $scanned . ' files)');
});

wallos_test('no SQL alias relies on the database preserving its case', function () {
    // PostgreSQL folds an unquoted identifier to lower case, so `as userCount`
    // comes back as `usercount` and every read of $row['userCount'] finds
    // nothing. SQLite preserves the case, so the defect is invisible there.
    //
    // Seven sites had this. The reading side needs no change — quoting the
    // alias works on both backends.
    //
    // Asserting the key rather than the value matters here: a test written as
    // $row['userCount'] ?? $row['usercount'] passes on both backends and hides
    // the defect permanently.
    $offenders = [];
    $scanned = 0;

    $paths = array_merge(
        glob(WALLOS_ROOT . '/*.php'),
        glob(WALLOS_ROOT . '/includes/*.php'),
        glob(WALLOS_ROOT . '/includes/*/*.php'),
        glob(WALLOS_ROOT . '/endpoints/*/*.php'),
        glob(WALLOS_ROOT . '/api/*/*.php')
    );

    foreach ($paths as $path) {
        $scanned++;
        $source = file_get_contents($path);

        // ` as camelCase` with no quote before the alias.
        if (preg_match_all('/\bas\s+(?![\'"\\\\])([a-z]+[A-Z][a-zA-Z]*)/', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $alias) {
            $offenders[] = str_replace(WALLOS_ROOT . '/', '', $path) . ': as ' . $alias;
        }
    }

    assert_true($scanned > 100, 'the scan reached the application, not an empty glob');
    assert_same([], $offenders, 'every mixed-case alias is quoted');
});

wallos_test('no function signature names a backend instead of the boundary', function () {
    // A SQLite3 type hint rejects the PostgreSQL connection with a TypeError
    // before the function body runs. Two functions had one, and between them
    // they killed sendnotifications — which nobody watches — plus stats.php and
    // get_period_budget.php.
    $offenders = [];
    $scanned = 0;

    $paths = array_merge(
        glob(WALLOS_ROOT . '/*.php'),
        glob(WALLOS_ROOT . '/includes/*.php'),
        glob(WALLOS_ROOT . '/includes/*/*.php'),
        glob(WALLOS_ROOT . '/endpoints/*/*.php'),
        glob(WALLOS_ROOT . '/api/*/*.php')
    );

    foreach ($paths as $path) {
        // The SQLite implementation may name its own type.
        if (strpos($path, '/includes/database/sqlite/') !== false) {
            continue;
        }

        $scanned++;
        if (preg_match('/function\s+\w+\s*\([^)]*\bSQLite3\s+\$/', file_get_contents($path)) === 1) {
            $offenders[] = str_replace(WALLOS_ROOT . '/', '', $path);
        }
    }

    assert_true($scanned > 100, 'the scan reached the application');
    assert_same([], $offenders, 'type hints name WallosDatabase, not an implementation');
});

wallos_test('the subscription INSERT binds every placeholder it names, logo or not', function () {
    // PostgreSQL counts: 22 placeholders with 19 bound is a refused
    // statement, where SQLite quietly makes the missing three NULL. The
    // browser form without a logo is exactly that request, so on PostgreSQL
    // the UI could not create a subscription without one (#115).
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php');

    assert_true(preg_match('/INSERT INTO subscriptions\s*\([^)]*\)\s*VALUES\s*\(([^)]*)\)/s', $source, $insert) === 1,
        'the INSERT statement is where this file says it is');
    preg_match_all('/:(\w+)/', $insert[1], $named);
    assert_true(count($named[1]) >= 20,
        'the placeholder list was actually read (' . count($named[1]) . ' found)');

    // The request the defect needed: no logo arrived, so nothing inside the
    // `if ($logo != "")` branches runs. Strip those blocks from the text and
    // every placeholder must still find a bind in what remains.
    $withoutLogo = preg_replace('/if \(\$logo != ""\) \{[^}]*\}/s', 'if (false) {}', $source);

    $unbound = [];
    foreach (array_unique($named[1]) as $placeholder) {
        if (!preg_match('/bind(?:Param|Value)\(\s*\':' . preg_quote($placeholder, '/') . '\'/', $withoutLogo)) {
            $unbound[] = ':' . $placeholder;
        }
    }

    assert_same([], $unbound, 'every INSERT placeholder is bound even without a logo');
});
