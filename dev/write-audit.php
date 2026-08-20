<?php
/*
  Counts the write paths whose outcome nobody looks at.

      podman exec wallos-dev php /var/www/html/dev/write-audit.php
      podman exec wallos-dev php /var/www/html/dev/write-audit.php --report
      podman exec wallos-dev php /var/www/html/dev/write-audit.php --update

  Issue #87: four defects across three releases turned out to be one defect
  wearing four coats — a statement whose return value is discarded, followed by
  a response that says the operation succeeded. Logout deleted no token, user
  deletion failed at a foreign key, four PostgreSQL boundary defects and the
  backup on PostgreSQL all reported success while doing nothing. A crash
  announces itself; a false success is believed.

  This does not fix any of them. It measures them, and then refuses to let the
  number grow — the same ratchet dev/db-audit.sh runs for the SQLite boundary,
  for the same reason: a gate demanding zero on a codebase with hundreds of
  these would be switched off in a week.

  ## What it counts

  Two of the five categories the issue lists, because these two can be
  recognised without guessing:

    discarded   $statement->execute();  as a statement of its own. The result
                is the only thing that says whether the write happened, and it
                went nowhere. A discarded SELECT is the same shape and a much
                smaller problem, which is why the count is a starting point for
                reading rather than a defect list.

    unchecked   $x = $db->prepare(...) where $x is never compared against false
                anywhere in the file. prepare() returns false on a broken
                statement, bindValue() on false is a fatal, and the request dies
                with a 500 instead of an error the caller could act on.

  ## What it does not see, and would mislead you by omitting silently

    * changes() read as a success signal. Neither backend resets the counter
      after a failed statement, so it can report the previous statement's row
      count — but the same call is correct right after a checked execute(),
      and nothing in the token stream tells the two apart.
    * multi-statement operations with no transaction, including
      includes/run_migrations.php recording a migration as applied whether or
      not its statements worked.
    * a hardcoded success response at the end of a function whose writes were
      never consulted. Textually this is 100 files; almost all of them are
      correct.

  So a file at zero here is not proven honest. It is proven to be free of the
  two shapes that can be counted.

  ## Parsing rather than searching

  token_get_all(), because a text search cannot tell `$stmt->execute();` from
  `$ok = $stmt->execute();`, nor either of them from the same words inside a
  comment or a docblock — and this file would be measuring its own prose.

  Exit codes: 0 pass, 1 regression, 2 usage error.
*/

/**
 * Directories the audit does not look at.
 *
 * libs is vendored code Wallos does not own. includes/database is the adapter
 * itself, where a raw execute() is the implementation rather than a call site.
 * tests holds fixtures that write deliberately unchecked rows. .claude holds
 * agent worktrees — whole checkouts nested in the repository, which would count
 * the tree a second time under paths the baseline does not know.
 *
 * @return string[]
 */
function write_audit_excluded()
{
    return ['libs', 'includes/database', 'tests', 'dev', '.git', '.claude'];
}

/**
 * Every PHP file the audit reads, root-relative and sorted.
 *
 * @param string $root
 * @return string[]
 */
function write_audit_files($root)
{
    $excluded = write_audit_excluded();
    $files = [];

    // The filter refuses the directory rather than its files, so that excluded
    // trees are never descended into. Filtering afterwards walks .git and the
    // agent worktrees under .claude — whole checkouts, tens of thousands of
    // files — and turns a two-second case into a suite nobody waits for.
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        function ($current) use ($root, $excluded) {
            $path = str_replace($root . '/', '', $current->getPathname());

            foreach ($excluded as $prefix) {
                if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                    return false;
                }
            }

            return $current->isDir() || $current->getExtension() === 'php';
        });

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        $files[] = str_replace($root . '/', '', $file->getPathname());
    }

    sort($files);

    return $files;
}

/**
 * The tokens that carry meaning, with whitespace and comments dropped.
 *
 * Comments go first because a docblock describing execute() must not be counted
 * as a call to it — which is exactly the mistake a text search makes.
 *
 * @param string $source
 * @return array<int, array{0: int|string, 1: string, 2: int}>
 */
function write_audit_tokens($source)
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = [$token[0], $token[1], $token[2]];

            continue;
        }

        // A single-character token carries no line of its own; the line of the
        // token before it is close enough for a report that points at code.
        $line = $tokens === [] ? 0 : $tokens[count($tokens) - 1][2];
        $tokens[] = [$token, $token, $line];
    }

    return $tokens;
}

/**
 * Whether a token starts a new statement, so that what follows it is an
 * expression whose value nothing receives.
 *
 * The colon is here for `case 1:` and for the alternative syntax, and it is
 * also the middle of a ternary — where what follows is emphatically not a
 * statement. `$ok = $stmt === false ? false : $stmt->execute();` reads as a
 * discarded result to anything that stops at the colon, and reporting a checked
 * write as unchecked is worse for a ratchet than missing one: it asks for
 * correct code to be rewritten. wallos_cron_fail sites in the scheduled jobs
 * are written exactly that way.
 *
 * @param array|null                     $token
 * @param array<int, array>              $tokens the whole stream
 * @param int                            $index  where $token sits in it
 * @return bool
 */
function write_audit_starts_statement($token, array $tokens = [], $index = -1)
{
    if ($token === null) {
        return true;
    }

    if (in_array($token[0], [';', '{', '}'], true)
        || in_array($token[0], [T_OPEN_TAG, T_ELSE], true)) {
        return true;
    }

    if ($token[0] !== ':' || $index < 0) {
        return false;
    }

    // A colon belongs to a ternary when a question mark opened one since the
    // last real statement boundary.
    for ($i = $index - 1; $i >= 0; $i--) {
        if (in_array($tokens[$i][0], [';', '{', '}'], true)) {
            return true;
        }

        if ($tokens[$i][0] === '?') {
            return false;
        }
    }

    return true;
}

/**
 * Whether the SQL handed to prepare() changes anything.
 *
 * Only the literal case can be answered here — a query built into a variable
 * first is reported as unknown rather than guessed at, and unknown is counted
 * with the writes, because that is the direction that is safe to be wrong in.
 *
 * The split does not change what the ratchet holds. It exists so that the
 * decision this measurement is for — whether the boundary should offer a write
 * that returns rows-affected-or-null (issue #87) — is made against the number
 * of writes rather than against one number covering both.
 *
 * @param array|null $token the first token inside prepare(
 * @return bool
 */
function write_audit_statement_writes($token)
{
    if ($token === null) {
        return true;
    }

    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return true;
    }

    return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',
        trim($token[1], "'\"")) === 1;
}

/**
 * The two counts for one file, with the lines they were found on.
 *
 * @param string $source
 * @return array{discarded: int[], unchecked: int[]}
 */
function write_audit_scan($source)
{
    $tokens = write_audit_tokens($source);
    $count = count($tokens);
    $discarded = [];
    $prepared = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token[0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$i + 1] ?? null;

        if ($name === null || $name[0] !== T_STRING) {
            continue;
        }

        // ->execute() whose value nothing receives. Walk back over the object
        // expression — $stmt, $this->stmt, $rows[0]->stmt — to whatever ends
        // the statement before it.
        if (strtolower($name[1]) === 'execute') {
            $j = $i;
            while ($j > 0 && !write_audit_starts_statement($tokens[$j - 1], $tokens, $j - 1)) {
                $previous = $tokens[$j - 1][0];

                if (in_array($previous, [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR,
                        T_DOUBLE_COLON, ']', ')'], true)) {
                    $j--;

                    continue;
                }

                break;
            }

            if (write_audit_starts_statement($tokens[$j - 1] ?? null, $tokens, $j - 1)) {
                $discarded[] = $name[2];
            }

            continue;
        }

        // $x = $db->prepare(...) — remember the variable, and see below whether
        // the file ever compares it against false.
        if (strtolower($name[1]) === 'prepare') {
            $assignment = $tokens[$i - 2] ?? null;
            $variable = $tokens[$i - 3] ?? null;

            if ($assignment !== null && $assignment[0] === '='
                && $variable !== null && $variable[0] === T_VARIABLE) {
                $prepared[$variable[1]][] = ['line' => $name[2],
                    'writes' => write_audit_statement_writes($tokens[$i + 3] ?? null)];
            }
        }
    }

    // A prepared statement is checked when the file compares it against false
    // or negates it. Per file rather than per scope: a ratchet that reports a
    // check in the wrong function as missing would be argued with rather than
    // acted on, and the direction of that error is the safe one.
    $unchecked = [];
    $uncheckedWrites = 0;

    foreach ($prepared as $variable => $statements) {
        $checked = false;

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== $variable) {
                continue;
            }

            $before = $tokens[$i - 1][0] ?? null;
            $after = $tokens[$i + 1][0] ?? null;

            if ($before === '!' || in_array($after, [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                    T_IS_EQUAL, T_IS_NOT_EQUAL], true)) {
                $checked = true;
                break;
            }
        }

        if ($checked) {
            continue;
        }

        foreach ($statements as $statement) {
            $unchecked[] = $statement['line'];

            if ($statement['writes']) {
                $uncheckedWrites++;
            }
        }
    }

    sort($unchecked);

    return ['discarded' => $discarded, 'unchecked' => $unchecked, 'writes' => $uncheckedWrites];
}

/**
 * The whole tree, keyed by path, files with nothing to report left out.
 *
 * @param string $root
 * @return array<string, array{discarded: int, unchecked: int}>
 */
function write_audit_measure($root)
{
    $measured = [];

    foreach (write_audit_files($root) as $path) {
        $source = file_get_contents($root . '/' . $path);

        if ($source === false) {
            continue;
        }

        $scan = write_audit_scan($source);
        $discarded = count($scan['discarded']);
        $unchecked = count($scan['unchecked']);

        if ($discarded + $unchecked > 0) {
            $measured[$path] = ['discarded' => $discarded, 'unchecked' => $unchecked,
                'writes' => $scan['writes']];
        }
    }

    return $measured;
}

/**
 * The baseline file as written by --update.
 *
 * @param array<string, array{discarded: int, unchecked: int}> $measured
 * @return string
 */
function write_audit_render($measured)
{
    $discarded = 0;
    $unchecked = 0;

    foreach ($measured as $counts) {
        $discarded += $counts['discarded'];
        $unchecked += $counts['unchecked'];
    }

    $out = "# Unchecked write paths — generated by dev/write-audit.php --update\n#\n"
        . "# One line per file: <path><TAB><discarded results><TAB><unchecked prepares>.\n#\n"
        . "# Issue #87. Neither number may rise and no file may join this list; both\n"
        . "# may fall, and every honest write shrinks it. When the file is empty the\n"
        . "# shape that produced four defects across three releases is gone.\n#\n"
        . "# Do not edit by hand — run dev/write-audit.php --update and commit the diff.\n#\n"
        . '# ' . count($measured) . ' file(s), ' . $discarded . ' discarded result(s), '
        . $unchecked . " unchecked prepare(s).\n\n";

    foreach ($measured as $path => $counts) {
        $out .= $path . "\t" . $counts['discarded'] . "\t" . $counts['unchecked'] . "\n";
    }

    return $out;
}

/**
 * Reads a baseline file back.
 *
 * @param string $path
 * @return array<string, array{discarded: int, unchecked: int}>
 */
function write_audit_read_baseline($path)
{
    $baseline = [];

    if (!is_file($path)) {
        return $baseline;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) !== 3) {
            continue;
        }

        $baseline[$parts[0]] = ['discarded' => (int) $parts[1], 'unchecked' => (int) $parts[2]];
    }

    return $baseline;
}

/**
 * What changed against the baseline, in both directions.
 *
 * @param array $measured
 * @param array $baseline
 * @return array{regressions: string[], improvements: string[]}
 */
function write_audit_compare($measured, $baseline)
{
    $regressions = [];
    $improvements = [];

    foreach ($measured as $path => $counts) {
        if (!isset($baseline[$path])) {
            $regressions[] = sprintf('%s is not in the baseline (%d discarded, %d unchecked)',
                $path, $counts['discarded'], $counts['unchecked']);

            continue;
        }

        foreach (['discarded' => 'discarded result', 'unchecked' => 'unchecked prepare'] as $key => $label) {
            $was = $baseline[$path][$key];
            $now = $counts[$key];

            if ($now > $was) {
                $regressions[] = sprintf('%s: %d -> %d %s(s)', $path, $was, $now, $label);
            } elseif ($now < $was) {
                $improvements[] = sprintf('%s: %d -> %d %s(s)', $path, $was, $now, $label);
            }
        }
    }

    foreach ($baseline as $path => $counts) {
        if (!isset($measured[$path])) {
            $improvements[] = $path . ': cleared';
        }
    }

    return ['regressions' => $regressions, 'improvements' => $improvements];
}

// Everything above is callable from a test; everything below runs only when
// this file is the program. The same split dev/generate-pgsql-schema.php uses,
// and for the same reason: tests/cases/write_audit_test.php is what keeps the
// baseline honest, and it cannot require a file that immediately exits.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    return;
}

$root = dirname(__DIR__);
$baselinePath = __DIR__ . '/write-audit-baseline.txt';
$mode = 'check';

foreach (array_slice($argv, 1) as $argument) {
    switch ($argument) {
        case '--check':
            $mode = 'check';
            break;
        case '--report':
            $mode = 'report';
            break;
        case '--update':
            $mode = 'update';
            break;
        case '-h':
        case '--help':
            $source = file(__FILE__);
            foreach (array_slice($source, 1) as $line) {
                if (strpos($line, '*/') !== false) {
                    break;
                }
                echo preg_replace('/^\s*(\*\/?|\/\*)\s?/', '', $line);
            }
            exit(0);
        default:
            fwrite(STDERR, 'write-audit: unknown argument: ' . $argument . " (try --help)\n");
            exit(2);
    }
}


$measured = write_audit_measure($root);
$totals = ['files' => count($measured), 'discarded' => 0, 'unchecked' => 0];

foreach ($measured as $counts) {
    $totals['discarded'] += $counts['discarded'];
    $totals['unchecked'] += $counts['unchecked'];
}

$summary = sprintf('%d discarded result(s) and %d unchecked prepare(s) in %d file(s)',
    $totals['discarded'], $totals['unchecked'], $totals['files']);

if ($mode === 'report') {
    $writes = 0;
    foreach ($measured as $counts) {
        $writes += $counts['writes'];
    }

    echo 'Unchecked write paths — ', $summary, "\n";
    printf("Of the %d unchecked prepares, %d carry a statement that changes data "
        . "(or one this cannot read); %d only read.\n\n",
        $totals['unchecked'], $writes, $totals['unchecked'] - $writes);

    $rows = $measured;
    uasort($rows, function ($a, $b) {
        return ($b['discarded'] + $b['unchecked']) - ($a['discarded'] + $a['unchecked']);
    });

    foreach ($rows as $path => $counts) {
        printf("  %4d discarded  %4d unchecked  %s\n", $counts['discarded'], $counts['unchecked'], $path);
    }

    exit(0);
}

if ($mode === 'update') {
    file_put_contents($baselinePath, write_audit_render($measured));
    echo 'baseline updated: ', $baselinePath, ' — ', $summary, "\n";

    exit(0);
}

$baseline = write_audit_read_baseline($baselinePath);

if ($baseline === []) {
    fwrite(STDERR, "write-audit: no baseline at " . $baselinePath
        . " — create one with dev/write-audit.php --update\n");
    exit(2);
}

$comparison = write_audit_compare($measured, $baseline);

foreach ($comparison['improvements'] as $line) {
    echo '  improved  ', $line, "\n";
}

if ($comparison['regressions'] === []) {
    echo 'write-audit: ok — ', $summary, "\n";

    if ($comparison['improvements'] !== []) {
        echo "Run dev/write-audit.php --update to record the improvement.\n";
    }

    exit(0);
}

foreach ($comparison['regressions'] as $line) {
    fwrite(STDERR, '  REGRESSION  ' . $line . "\n");
}

fwrite(STDERR, "\nA write whose result nobody reads reports success whether or not it happened.\n"
    . "That is issue #87, and it is how four defects across three releases went\n"
    . "unnoticed — a crash announces itself, a false success is believed.\n\n"
    . "  * Check the result: if (\$statement->execute() === false) { ... }\n"
    . "  * A DELETE or UPDATE matching zero rows is successful and did nothing;\n"
    . "    \$db->changes() after a checked execute() is what says which.\n"
    . "  * prepare() returns false on a broken statement, and bindValue() on\n"
    . "    false is a fatal rather than an error the caller can report.\n"
    . "  * includes/user_deletion.php is what checked looks like in this codebase.\n\n"
    . "Raising the baseline to make this pass makes issue #87 bigger. Do it only\n"
    . "with a reason stated in the pull request.\n");

exit(1);
