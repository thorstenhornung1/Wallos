<?php
/*
  dev/db-audit.sh — the SQLite boundary ratchet.

  The audit scans every *.php file in the repository, this one included, so the
  trigger words below are assembled from fragments. If they appeared literally,
  this test file would land in the baseline and every edit to it would move the
  ratchet it is supposed to guard.
*/

/**
 * One of the words the audit looks for, spelled so that the audit cannot see
 * it in this file.
 */
function db_audit_token($which)
{
    $tokens = [
        'class' => 'SQL' . 'ite3',
        'constant' => 'SQL' . 'ITE3_' . 'INTEGER',
        'method' => 'query' . 'Single',
        'directive' => 'PRA' . 'GMA',
    ];

    return $tokens[$which];
}

function db_audit_rmdir($path)
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        is_dir($child) ? db_audit_rmdir($child) : @unlink($child);
    }

    @rmdir($path);
}

/**
 * A throwaway source tree for the audit to scan.
 */
function db_audit_tree($name)
{
    $dir = WALLOS_TEST_TMP . '/db-audit-' . $name;
    db_audit_rmdir($dir);
    mkdir($dir, 0700, true);

    return $dir;
}

/**
 * Writes a PHP file with exactly $matchingLines lines the audit must count.
 */
function db_audit_write($dir, $relative, $matchingLines)
{
    $path = $dir . '/' . $relative;

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }

    $php = "<?php\n";
    for ($i = 0; $i < $matchingLines; $i++) {
        $php .= '$db' . $i . ' = new ' . db_audit_token('class') . "('x');\n";
    }

    file_put_contents($path, $php);

    return $path;
}

function db_audit_baseline($dir, array $counts)
{
    $path = $dir . '/baseline.txt';
    $text = "# baseline for a test tree\n";

    foreach ($counts as $file => $count) {
        $text .= $file . "\t" . $count . "\n";
    }

    file_put_contents($path, $text);

    return $path;
}

/**
 * Runs dev/db-audit.sh and returns its exit status; $output receives the
 * combined stdout and stderr.
 *
 * Every interpolated value goes through escapeshellarg, and every one of them
 * is a path this test just created — there is no external input here.
 */
function db_audit_run($arguments, &$output)
{
    $command = 'NO_COLOR=1 sh ' . escapeshellarg(WALLOS_ROOT . '/dev/db-audit.sh')
        . ' ' . $arguments . ' 2>&1';

    $lines = [];
    $status = 0;
    exec($command, $lines, $status);
    $output = implode("\n", $lines);

    return $status;
}

function db_audit_check($dir, $baseline, $extra = '')
{
    return '--root ' . escapeshellarg($dir) . ' --baseline ' . escapeshellarg($baseline) . ' ' . $extra;
}

wallos_test('an unchanged tree passes', function () {
    $dir = db_audit_tree('unchanged');
    db_audit_write($dir, 'app.php', 3);
    db_audit_write($dir, 'nested/other.php', 1);
    $baseline = db_audit_baseline($dir, ['app.php' => 3, 'nested/other.php' => 1]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(0, $status, 'a tree that matches its baseline passes: ' . $output);
    assert_contains('no change against the baseline', $output, 'the verdict is stated');
    assert_contains('4 matches in 2 file(s)', $output, 'the totals are reported');

    db_audit_rmdir($dir);
});

wallos_test('a file that gains a SQLite call fails', function () {
    $dir = db_audit_tree('grown');
    db_audit_write($dir, 'app.php', 5);
    $baseline = db_audit_baseline($dir, ['app.php' => 3]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(1, $status, 'growth is a regression: ' . $output);
    assert_contains('exceed the baseline', $output, 'the failure is named');
    assert_contains('3 -> 5 (+2)', $output, 'the before and after counts are shown');
    assert_contains('app.php:2:', $output, 'the offending lines are quoted with line numbers');

    db_audit_rmdir($dir);
});

wallos_test('a file that is not in the baseline at all fails', function () {
    $dir = db_audit_tree('added');
    db_audit_write($dir, 'app.php', 2);
    db_audit_write($dir, 'endpoints/fresh.php', 1);
    $baseline = db_audit_baseline($dir, ['app.php' => 2]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(1, $status, 'a new leaking file is a regression: ' . $output);
    assert_contains('not in the baseline', $output, 'the reason is stated');
    assert_contains('endpoints/fresh.php', $output, 'the new file is named');
    assert_contains('adapter boundary', $output, 'the contributor is told why this matters');

    db_audit_rmdir($dir);
});

wallos_test('an improvement passes and asks for a smaller baseline', function () {
    $dir = db_audit_tree('shrunk');
    db_audit_write($dir, 'app.php', 2);
    db_audit_write($dir, 'gone.php', 0);
    $baseline = db_audit_baseline($dir, ['app.php' => 6, 'gone.php' => 4]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(0, $status, 'removing SQLite calls never fails the build: ' . $output);
    assert_contains('2 file(s) improved', $output, 'both improvements are counted');
    assert_contains('6 -> 2 (-4)', $output, 'the shrink is quantified');
    assert_contains('4 -> 0 (cleared)', $output, 'a file with nothing left is reported as cleared');
    assert_contains('--update', $output, 'the contributor is told how to record it');

    db_audit_rmdir($dir);
});

wallos_test('a file deleted outright counts as an improvement', function () {
    $dir = db_audit_tree('deleted');
    db_audit_write($dir, 'kept.php', 1);
    $baseline = db_audit_baseline($dir, ['kept.php' => 1, 'removed.php' => 9]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(0, $status, 'a deleted file is not a regression: ' . $output);
    assert_contains('removed.php', $output, 'the vanished file is reported');
    assert_contains('9 -> 0 (cleared)', $output, 'its whole count is credited');

    db_audit_rmdir($dir);
});

wallos_test('--update records the tree and the result then passes', function () {
    $dir = db_audit_tree('update');
    db_audit_write($dir, 'app.php', 4);
    db_audit_write($dir, 'nested/other.php', 2);
    $baseline = $dir . '/baseline.txt';

    $status = db_audit_run(db_audit_check($dir, $baseline, '--update'), $output);
    assert_same(0, $status, 'the baseline can be generated: ' . $output);

    $written = file_get_contents($baseline);
    assert_contains("app.php\t4", $written, 'the count is recorded per file');
    assert_contains("nested/other.php\t2", $written, 'nested paths are relative to the root');
    assert_contains('# total: 6 matches in 2 file(s)', $written, 'the header carries the total');
    assert_contains('issue #20', $written, 'the header explains why a baseline exists at all');

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);
    assert_same(0, $status, 'a freshly generated baseline passes: ' . $output);

    db_audit_rmdir($dir);
});

wallos_test('the permitted SQLite boundary and vendored code are not audited', function () {
    $dir = db_audit_tree('excluded');
    db_audit_write($dir, 'libs/vendored/thing.php', 7);
    db_audit_write($dir, 'includes/database/sqlite/adapter.php', 12);
    db_audit_write($dir, 'migrations/sqlite/000001.php', 5);
    db_audit_write($dir, 'app.php', 1);
    $baseline = db_audit_baseline($dir, ['app.php' => 1]);

    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(0, $status, 'the adapter boundary itself may use SQLite freely: ' . $output);
    assert_contains('1 matches in 1 file(s)', $output, 'only application code is counted');
    assert_not_contains('adapter.php', $output, 'the SQLite adapter directory is skipped');
    assert_not_contains('vendored', $output, 'vendored libraries are skipped');

    db_audit_rmdir($dir);
});

wallos_test('every fingerprint from the issue is detected', function () {
    $dir = db_audit_tree('fingerprint');

    $lines = [
        'new ' . db_audit_token('class') . '($file);',
        '$statement->fetchArray(' . db_audit_token('constant') . ');',
        '$db->' . db_audit_token('method') . '("SELECT 1");',
        '$db->busy' . 'Timeout(5000);',
        '$id = $db->lastInsert' . 'RowID();',
        '$db->exec("' . db_audit_token('directive') . ' journal_mode = WAL");',
        '$db->query("SELECT name FROM sqlite' . '_master");',
        '$db->query("SELECT * FROM pragma' . '_table_info(\'users\')");',
        '$db->exec("INSERT   OR' . "\tREPLACE" . ' INTO settings VALUES (1)");',
        '$db->exec("CREATE TABLE t (id INTEGER PRIMARY KEY AUTO' . 'INCREMENT)");',
    ];

    $path = $dir . '/app.php';
    file_put_contents($path, "<?php\n" . implode("\n", $lines) . "\n");

    $baseline = db_audit_baseline($dir, ['app.php' => count($lines)]);
    $status = db_audit_run(db_audit_check($dir, $baseline), $output);

    assert_same(0, $status, 'each fingerprint is counted exactly once: ' . $output);
    assert_contains(count($lines) . ' matches in 1 file(s)', $output, 'no fingerprint is missed');

    db_audit_rmdir($dir);
});

wallos_test('ripgrep and grep produce the same verdict', function () {
    $dir = db_audit_tree('engines');
    db_audit_write($dir, 'app.php', 3);
    db_audit_write($dir, 'nested/other.php', 2);
    $baseline = db_audit_baseline($dir, ['app.php' => 3, 'nested/other.php' => 2]);

    $viaGrep = '';
    $status = db_audit_run(db_audit_check($dir, $baseline, '--report --engine grep'), $viaGrep);
    assert_same(0, $status, 'the grep fallback runs everywhere: ' . $viaGrep);
    assert_contains('5 matches in 2 file(s)', $viaGrep, 'grep counts matching lines');

    $probe = [];
    $found = 0;
    exec('command -v rg >/dev/null 2>&1', $probe, $found);

    if ($found !== 0) {
        // No ripgrep here (the test container has none); the grep path is the
        // one that has to work on every machine, and it just did.
        return;
    }

    $viaRg = '';
    $status = db_audit_run(db_audit_check($dir, $baseline, '--report --engine rg'), $viaRg);
    assert_same(0, $status, 'ripgrep runs when installed: ' . $viaRg);
    assert_same(
        str_replace('engine: grep', 'engine: X', $viaGrep),
        str_replace('engine: rg', 'engine: X', $viaRg),
        'both engines see exactly the same files and counts'
    );

    db_audit_rmdir($dir);
});

wallos_test('a corrupt baseline is an error, not a silent pass', function () {
    $dir = db_audit_tree('corrupt');
    db_audit_write($dir, 'app.php', 3);

    $baseline = $dir . '/baseline.txt';
    file_put_contents($baseline, "app.php\tthree\n");
    $status = db_audit_run(db_audit_check($dir, $baseline), $output);
    assert_same(2, $status, 'a non-numeric count is refused: ' . $output);
    assert_contains('malformed baseline line 1', $output, 'the bad line is pointed at');

    file_put_contents($baseline, "app.php\t3\napp.php\t9\n");
    $status = db_audit_run(db_audit_check($dir, $baseline), $output);
    assert_same(2, $status, 'a duplicate entry is refused: ' . $output);
    assert_contains('duplicate baseline entry', $output, 'the duplicate is named');

    $status = db_audit_run(db_audit_check($dir, $dir . '/missing.txt'), $output);
    assert_same(2, $status, 'a missing baseline is an error: ' . $output);
    assert_contains('no baseline at', $output, 'the missing file is named');

    db_audit_rmdir($dir);
});

wallos_test('--report never fails, whatever the baseline says', function () {
    $dir = db_audit_tree('report');
    db_audit_write($dir, 'app.php', 4);
    $baseline = db_audit_baseline($dir, []);

    $status = db_audit_run(db_audit_check($dir, $baseline, '--report'), $output);

    assert_same(0, $status, 'the inventory is not a gate: ' . $output);
    assert_contains('4  app.php', $output, 'counts are listed worst first');

    db_audit_rmdir($dir);
});

wallos_test('the committed baseline matches this working tree', function () {
    $status = db_audit_run('', $output);

    assert_same(0, $status, "dev/db-audit.sh fails on this tree:\n" . $output);
});
