<?php
/*
  The container's unbounded writes, bounded (#85).

  Upstream #955 reported k3s evicting pods for writing outside the volumes.
  The main cause — a recursive chown forcing overlayfs copy-ups — is fixed in
  startup.sh; what this file pins is the long tail: session files no cron run
  should create, caches nothing deleted, archives that outlive an aborted
  download, restore staging leaked on failure paths, and logs that only ever
  grow. Each case guards one write that used to be unbounded.
*/

/**
 * Runs a PHP snippet as its own process, inheriting the fixture environment.
 *
 * Deliberately local to this file: the runner loads only the case files the
 * filter matches, so helpers from other files may not exist. The script path
 * is generated here and quoted; nothing a request could reach.
 *
 * @param string $body PHP code, without the opening tag.
 * @return array{output: string, status: int}
 */
function ephemeral_run_php($body)
{
    $script = WALLOS_TEST_TMP . '/ephemeral-' . uniqid('', true) . '.php';
    file_put_contents($script, "<?php\n" . $body . "\n");

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    unlink($script);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('a cron run leaves no session file behind', function () {
    // Every cron job includes validate.php, which used to session_start()
    // under CLI too — one orphan session file per run, about 1,450 a day at
    // the shipped schedule, and CLI runs never trigger PHP's probabilistic
    // session GC. A session exists for a browser; a cron run has none.
    $dir = WALLOS_TEST_TMP . '/sessions-' . uniqid('', true);
    mkdir($dir);

    $run = ephemeral_run_php(
        'ini_set("session.save_path", ' . var_export($dir, true) . ');' . "\n"
        . 'require ' . var_export(WALLOS_ROOT . '/endpoints/cronjobs/validate.php', true) . ';' . "\n"
        . 'echo "done";'
    );

    assert_contains('done', $run['output'], 'the include finishes under CLI (got: ' . $run['output'] . ')');
    assert_same([], glob($dir . '/sess_*') ?: [], 'no session file was written');

    rmdir($dir);
});

wallos_test('the backup archive does not outlive an aborted download', function () {
    // readfile() then unlink() means an aborted download keeps the full zip:
    // the unlink line is never reached. Unlinking the path first and streaming
    // the already-open handle keeps the bytes alive exactly as long as the
    // request that is sending them.
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/db/backup.php');

    assert_true(strpos($source, 'readfile(') === false,
        'nothing streams by path after the path is meant to be gone');
    assert_true(substr_count($source, 'fpassthru(') >= 2,
        'both archive paths stream from an open handle');
});

wallos_test('restore staging cannot leak past the request', function () {
    // Both restore endpoints stage the upload and its extraction under .tmp/
    // and used to leak them on failure paths (a zip that refuses to open, a
    // migration that fails). A shutdown hook cleans whatever the explicit
    // paths did not, and the cleanup resolves its directory absolutely —
    // the working directory at shutdown is not guaranteed to be the script's.
    foreach (['endpoints/db/restore.php', 'endpoints/db/import.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(strpos($source, 'register_shutdown_function') !== false,
            $path . ' cleans its staging on every exit path');
        assert_true(strpos($source, "sys_get_temp_dir() . '/wallos-restore'") !== false,
            $path . ' resolves the staging directory absolutely, outside the webroot (#86)');
    }
});

wallos_test('an expired logo search cache is swept, not kept', function () {
    // One cache file per distinct search term, and nothing ever deleted them.
    // Writing a fresh entry now sweeps expired siblings, which bounds the
    // directory to the terms searched within one TTL.
    foreach (['endpoints/logos/search.php', 'endpoints/logos/google_search.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(strpos($source, 'glob(') !== false && strpos($source, 'unlink(') !== false,
            $path . ' sweeps its expired cache files');
    }

    // The icon index is different on purpose: one file per source, bounded,
    // and kept past its TTL as the fallback when the network fails. Deleting
    // it would trade a bounded file for a broken search during an outage.
    $iconSearch = file_get_contents(WALLOS_ROOT . '/endpoints/logos/icon_search.php');
    assert_true(strpos($iconSearch, 'unlink(') === false,
        'the icon index keeps its stale-fallback copy');
});

wallos_test('the cron log files hold one run, not history', function () {
    // The commentary log per job is diagnostic only — outcomes live in the
    // cron_runs table — so each run truncates its file instead of appending
    // forever to a log nothing rotates.
    $jobLines = 0;
    foreach (file(WALLOS_ROOT . '/cronjobs') as $line) {
        if (strpos($line, '/tmp/cron/') === false) {
            continue;
        }

        $jobLines++;
        assert_true(!preg_match('/>>\s*\/tmp\/cron\//', $line),
            'a job appends to its log instead of truncating it: ' . trim($line));
    }

    // Counted, so a path change cannot quietly turn this into a test of
    // nothing — that is what happened when the logs moved for #86.
    assert_true($jobLines >= 10, 'the job lines were actually seen (got ' . $jobLines . ')');
});

wallos_test('nginx logs to the container runtime, not into the container', function () {
    // Files under /var/log/nginx are rotated by nothing; the healthcheck alone
    // wrote about 29 MB a year into the access log. Logging to the standard
    // streams hands rotation to whatever runs the container, and the
    // healthcheck itself — 720 requests a day of no information — is excluded.
    $main = file_get_contents(WALLOS_ROOT . '/nginx.conf');

    assert_true(strpos($main, '/dev/stdout') !== false, 'access log goes to stdout');
    assert_true(strpos($main, '/dev/stderr') !== false, 'error log goes to stderr');
    assert_true(strpos($main, 'location = /health.php') !== false,
        'the healthcheck has its own location');
    assert_true(strpos($main, 'access_log     off') !== false || strpos($main, 'access_log off') !== false,
        'and it is not logged');
});

wallos_test('PHP is told where its ephemeral state lives', function () {
    // With no configuration loaded, session.save_path, sys_temp_dir and
    // upload_tmp_dir all fall back to /tmp implicitly. Naming them makes the
    // writes visible, keeps sessions in a directory PHP's GC actually cleans,
    // and is what lets a read-only root filesystem work with one tmpfs.
    $ini = WALLOS_ROOT . '/php-wallos.ini';
    assert_true(is_file($ini), 'the ini file exists');

    $settings = file_get_contents($ini);
    foreach (['session.save_path', 'sys_temp_dir', 'upload_tmp_dir'] as $key) {
        assert_true(strpos($settings, $key) !== false, $key . ' is set');
    }

    $dockerfile = file_get_contents(WALLOS_ROOT . '/Dockerfile');
    assert_true(strpos($dockerfile, 'php-wallos.ini') !== false,
        'the Dockerfile installs it into conf.d');

    // The sessions directory has to exist before the first request, and with
    // a tmpfs at /tmp it is gone on every start — so startup.sh creates it,
    // before php-fpm launches.
    $startup = file_get_contents(WALLOS_ROOT . '/startup.sh');
    $created = strpos($startup, 'wallos-sessions');
    $launched = strpos($startup, 'Launching php-fpm');
    assert_true($created !== false, 'startup.sh creates the sessions directory');
    assert_true($launched !== false && $created < $launched, 'and does so before php-fpm starts');
});
