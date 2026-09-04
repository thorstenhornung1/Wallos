<?php
/*
  Whether a scheduled job that fails can be told apart from one that had nothing
  to do.

  It could not. Every job ended with status 0 whatever happened, its output went
  to a file inside the container, and nothing recorded that it had run — so a
  fatal in sendnotifications was found by a person working a test plan by hand,
  after an unknown number of nights.

  The cases below are in three groups, and the middle one is the point:

    * the harness itself, exercised through a real subprocess, because an exit
      status asserted in the same process as the code that sets it is not an
      exit status;
    * the reading of the recorded runs, which is what makes a job that stopped
      being started visible at all — a container whose cron is dead passes its
      healthcheck, so nothing else in Wallos would say a word;
    * the crontab and the jobs agreeing on which jobs exist, so that adding one
      to either without the other fails here instead of leaving a job that
      never appears on the admin page.
*/

require_once WALLOS_ROOT . '/includes/cron/diagnostics.php';

/**
 * Runs a snippet in a real PHP process with the harness loaded.
 *
 * A subprocess rather than an include: register_shutdown_function, exit codes
 * and error_log all belong to a process, and asserting them from inside the
 * test runner would assert something no cron job ever experiences. It also
 * keeps the harness's own exit() away from the runner's exit status.
 *
 * @param string $body   PHP to run after wallos_cron_begin()
 * @param string $job
 * @param bool   $strict whether to ask for the exit status
 * @return array{status: int, stdout: string, stderr: string}
 */
function cron_run_process($body, $job = 'testjob', $strict = true)
{
    $script = WALLOS_TEST_TMP . '/cron-' . uniqid('', true) . '.php';

    file_put_contents($script, "<?php\n"
        . "require_once " . var_export(WALLOS_ROOT . '/includes/cron_run.php', true) . ";\n"
        . "wallos_cron_begin(" . var_export($job, true) . ");\n"
        . $body . "\n");

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    // The environment is inherited, which is how the child finds the same
    // database the fixture opened: WALLOS_DB_PATH for SQLite, and the
    // WALLOS_DB_* plus PGOPTIONS set for PostgreSQL.
    $environment = $_ENV;
    foreach (['WALLOS_DB_PATH', 'WALLOS_DB_DRIVER', 'WALLOS_DB_HOST', 'WALLOS_DB_PORT',
              'WALLOS_DB_NAME', 'WALLOS_DB_USER', 'WALLOS_DB_PASSWORD', 'WALLOS_DB_SSLMODE',
              'PGOPTIONS'] as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $environment[$name] = $value;
        }
    }
    $environment['WALLOS_CRON_STRICT'] = $strict ? '1' : '0';

    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, WALLOS_ROOT, $environment);

    if (!is_resource($process)) {
        return ['status' => -1, 'stdout' => '', 'stderr' => 'could not start php'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    @unlink($script);

    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * The recorded run for one job.
 *
 * @param WallosDatabase $db
 * @param string         $job
 * @return array|null
 */
function cron_run_row($db, $job)
{
    $statement = $db->prepare('SELECT * FROM cron_runs WHERE job = :job');
    $statement->bindValue(':job', $job);
    $row = $statement->execute()->fetchArray();

    return $row === false ? null : $row;
}

/**
 * A row as wallos_cron_checks() reads them.
 *
 * @param string $status
 * @param int    $secondsAgo
 * @param string $detail
 * @return array
 */
function cron_run_fake($status, $secondsAgo, $detail = '')
{
    return [
        'status' => $status,
        'started_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo),
        'finished_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo),
        'duration_ms' => 10,
        'detail' => $detail,
        'last_failure_at' => null,
        'last_failure_detail' => '',
        'failure_count' => 0,
    ];
}

/**
 * The same row, with a failure behind it that a later success did not erase.
 *
 * @param array  $row
 * @param int    $secondsAgo
 * @param int    $count
 * @param string $detail
 * @return array
 */
function cron_run_with_failure($row, $secondsAgo, $count = 1, $detail = '')
{
    $row['last_failure_at'] = gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    $row['last_failure_detail'] = $detail;
    $row['failure_count'] = $count;

    return $row;
}

/**
 * The finding for one job label.
 *
 * @param array  $checks
 * @param string $label
 * @return array|null
 */
function cron_run_check($checks, $label)
{
    foreach ($checks as $check) {
        if ($check['label'] === $label) {
            return $check;
        }
    }

    return null;
}

// --- the table ------------------------------------------------------------

wallos_test('every backend has somewhere to record a run', function () {
    $db = wallos_test_open_database();

    // Not a formality. The harness writes nothing when the table is absent and
    // the admin page then reports every job as never having run, which is the
    // same thing it says about a dead cron — so a missing table would hide
    // exactly the failure this exists to show.
    assert_true($db->tableExists('cron_runs'), 'cron_runs exists on ' . $db->driver());

    $db->close();
});

// --- the harness, in a process that really exits --------------------------

wallos_test('a job that finishes exits zero and records success', function () {
    $db = wallos_test_open_database();

    $result = cron_run_process("wallos_cron_count('sent', 3);\nwallos_cron_done('nothing unusual');");

    assert_same(0, $result['status'], 'a successful run exits 0');
    // Revised with #122: a successful run that did work (sent=3 above) says
    // exactly one OK line where a deploy is watched. Silence is reserved for
    // runs with nothing to report — pinned by the case at the end of this
    // file. ERROR on a clean run would still be wrong:
    assert_contains('[Wallos cron] OK job=testjob', $result['stderr'],
        'a clean run with work reports one OK line');
    assert_true(strpos($result['stderr'], 'ERROR') === false, 'and no ERROR');

    $row = cron_run_row($db, 'testjob');
    assert_true($row !== null, 'the run recorded itself');
    assert_same(WALLOS_CRON_OK, $row['status'], 'and recorded success');
    assert_contains('sent=3', $row['detail'], 'the detail carries what it achieved');
    assert_contains('nothing unusual', $row['detail'], 'and the summary it gave');

    $db->close();
});

wallos_test('a reported problem fails the run even when everything else worked', function () {
    $db = wallos_test_open_database();

    // The shape these jobs actually have: four notifications out of five. The
    // old code printed the fifth to a file and exited 0.
    $result = cron_run_process(
        "wallos_cron_count('sent', 4);\n"
        . "wallos_cron_problem('SMTP refused the message');\n"
        . "wallos_cron_done();"
    );

    assert_same(1, $result['status'], 'a partial failure is still a failure');
    assert_contains('[Wallos cron] ERROR', $result['stderr'], 'and says so on standard error');
    assert_contains('SMTP refused the message', $result['stderr'], 'naming the reason');
    assert_contains('job=testjob', $result['stderr'], 'and the job');

    $row = cron_run_row($db, 'testjob');
    assert_same(WALLOS_CRON_FAILED, $row['status'], 'the recorded outcome is failure');
    assert_contains('sent=4', $row['detail'], 'and it still says what did get through');

    $db->close();
});

wallos_test('an uncaught throwable is reported rather than printed', function () {
    $db = wallos_test_open_database();

    // Issue #90: a TypeError from a hard-coded backend type hint killed
    // sendnotifications, and the only trace was a line in a log file nobody
    // reads. (The class name is spelled out in portable_sql_test.php, which is
    // where the boundary audit expects to find it.)
    $result = cron_run_process("throw new RuntimeException('the boundary refused the connection');");

    assert_same(1, $result['status'], 'a throwable ends the run non-zero');
    assert_contains('uncaught RuntimeException', $result['stderr'], 'the class reaches standard error');
    assert_contains('the boundary refused the connection', $result['stderr'], 'and the message');

    $row = cron_run_row($db, 'testjob');
    assert_same(WALLOS_CRON_FAILED, $row['status'], 'and the row says the job failed');

    $db->close();
});

wallos_test('a fatal error is reported, and it is the one no catch would see', function () {
    $db = wallos_test_open_database();

    // A real E_ERROR, which no catch block and no exception handler sees. The
    // ones that happen in the field are the 300 second time limit in
    // generaterecommendations and memory exhaustion; this is the second, made
    // deterministic. A method call on false is not usable here — PHP 8 raises
    // that as a catchable Error, which the case above already covers.
    $result = cron_run_process("ini_set('memory_limit', '4M');\n\$block = str_repeat('x', 64 * 1024 * 1024);");

    assert_same(1, $result['status'], 'a fatal ends the run non-zero');
    assert_contains('fatal:', $result['stderr'], 'the fatal reaches standard error');

    $row = cron_run_row($db, 'testjob');
    assert_same(WALLOS_CRON_FAILED, $row['status'], 'and the row says the job failed');
    assert_contains('Allowed memory size', $row['detail'], 'with the reason kept');

    $db->close();
});

wallos_test('a job that stops without saying why is a failure, not a quiet night', function () {
    $db = wallos_test_open_database();

    // die() leaves no fatal, no exception and status 0. It is how
    // checkforupdates reported an unreachable GitHub for two days, and how the
    // fatal variant of the SSRF check ends a recommendations run mid-loop.
    $result = cron_run_process("die('nothing to see here');");

    assert_same(1, $result['status'], 'an unexplained exit is a failure');
    assert_contains('stopped before it finished', $result['stderr'], 'and is named as one');

    $row = cron_run_row($db, 'testjob');
    assert_same(WALLOS_CRON_FAILED, $row['status'], 'the row agrees');

    $db->close();
});

wallos_test('the exit status is asked for, because startup.sh cannot survive it otherwise', function () {
    $db = wallos_test_open_database();

    // startup.sh runs four of these scripts under `set -e` before the container
    // finishes starting. An unconditional non-zero exit means an installation
    // that cannot reach GitHub, or whose rate provider refuses its key, never
    // comes up — so the caller that wants the status sets WALLOS_CRON_STRICT,
    // and the crontab is that caller.
    $result = cron_run_process("wallos_cron_problem('the provider refused');\nwallos_cron_done();",
        'testjob', false);

    assert_same(0, $result['status'], 'without the flag the status stays 0');
    assert_contains('[Wallos cron] ERROR', $result['stderr'], 'but the log line is unconditional');

    $row = cron_run_row($db, 'testjob');
    assert_same(WALLOS_CRON_FAILED, $row['status'], 'and so is the recorded outcome');

    $db->close();
});

wallos_test('a second run replaces the first rather than accumulating', function () {
    $db = wallos_test_open_database();

    // ON CONFLICT, which both backends have. A delete followed by an insert
    // would leave no row at all for a job that died between the two, and the
    // admin page cannot tell that apart from a job that has never run.
    cron_run_process("wallos_cron_done('first');");
    cron_run_process("wallos_cron_done('second');");

    $count = 0;
    $result = $db->query("SELECT job FROM cron_runs WHERE job = 'testjob'");
    while ($result->fetchArray()) {
        $count++;
    }

    assert_same(1, $count, 'one row per job on ' . $db->driver());
    assert_contains('second', cron_run_row($db, 'testjob')['detail'], 'holding the latest run');

    $db->close();
});

// --- reading the record ---------------------------------------------------

wallos_test('a job that succeeded recently is reported as healthy', function () {
    $runs = ['sendnotifications' => cron_run_fake(WALLOS_CRON_OK, 3600, 'sent=2')];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_OK, $check['status'], 'a fresh success is ok');
    assert_contains('sent=2', $check['detail'], 'and says what it did');
});

wallos_test('a job that succeeded too long ago is reported as overdue', function () {
    // The failure the container healthcheck cannot see. Nothing is wrong inside
    // the job; what is wrong is that nothing has started it, which is what an
    // empty crontab and a still-running crond look like from here.
    $runs = ['sendnotifications' => cron_run_fake(WALLOS_CRON_OK, 5 * 86400)];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_ERROR, $check['status'], 'a job that has not run is an error');
    assert_contains('check that cron is running', $check['detail'], 'and points at the likely cause');
});

wallos_test('a job that failed is reported with its reason', function () {
    $runs = ['sendnotifications' => cron_run_fake(WALLOS_CRON_FAILED, 60, 'SMTP refused the message')];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_ERROR, $check['status'], 'a failure is an error');
    assert_contains('SMTP refused the message', $check['detail'], 'carrying the reason to the page');
});

wallos_test('a job with no record is unknown rather than green', function () {
    $checks = wallos_cron_checks([], time());
    $check = cron_run_check($checks, 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_UNKNOWN, $check['status'], 'never having run is not success');
    assert_contains('No run recorded', $check['detail'], 'and says so plainly');
});

wallos_test('every job overdue at once is reported as one problem, not eleven', function () {
    $runs = [];
    foreach (wallos_cron_jobs() as $name => $job) {
        $runs[$name] = cron_run_fake(WALLOS_CRON_OK, 100 * 86400);
    }

    $checks = wallos_cron_checks($runs, time());

    assert_same(WALLOS_CRON_CHECK_ERROR, wallos_cron_worst_status($checks), 'the summary is red');
    assert_contains('Cron is not running in this container', $checks[0]['detail'],
        'and names the single cause rather than listing every job as its own fault');
});

wallos_test('a run whose recorded time cannot be read is not called healthy', function () {
    // Found by breaking it: a fixture wrote empty timestamps and every job was
    // reported green. Freshness is the whole quantity here, so a timestamp that
    // cannot be read is the absence of an answer, not a good one.
    $runs = ['sendnotifications' => ['status' => WALLOS_CRON_OK, 'started_at' => '',
                                     'finished_at' => '', 'duration_ms' => 1, 'detail' => '']];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_UNKNOWN, $check['status'], 'an unreadable time is unknown, not ok');
    assert_contains('cannot tell', $check['detail'], 'and says that is what it means');
});

wallos_test('a job with no schedule is never overdue', function () {
    // createdatabase runs once, at startup. There is no interval for it to be
    // late against, and reporting it as overdue every day would be noise.
    $runs = ['createdatabase' => cron_run_fake(WALLOS_CRON_OK, 400 * 86400)];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Schema installation');

    assert_same(WALLOS_CRON_CHECK_OK, $check['status'], 'a startup job does not go stale');
});

wallos_test('the recorded time is read as UTC, because that is how it is written', function () {
    // The container sets TZ; the harness writes with gmdate(). Reading the
    // value through the local timezone would make every job look hours fresher
    // or staler than it is, and freshness is the entire quantity here.
    $written = gmdate('Y-m-d H:i:s', 1800000000);

    assert_same(1800000000, wallos_cron_parse_time($written), 'the round trip is exact');
    assert_true(wallos_cron_parse_time('') === null, 'an empty timestamp is unreadable, not epoch');
});

// --- the crontab and the jobs agreeing ------------------------------------

/**
 * The job names the crontab actually schedules.
 *
 * @return array<int, string>
 */
function cron_run_scheduled_jobs()
{
    $names = [];

    foreach (preg_split('/\R/', file_get_contents(WALLOS_ROOT . '/cronjobs')) as $line) {
        if (!preg_match('#cronjobs/([a-z]+)\.php(\s+([a-z]+))?#', $line, $found)) {
            continue;
        }

        $names[] = isset($found[3]) && $found[3] !== ''
            ? $found[1] . ':' . $found[3]
            : $found[1];
    }

    return $names;
}

wallos_test('the admin page knows about exactly the jobs the crontab runs', function () {
    $scheduled = cron_run_scheduled_jobs();
    $known = array_keys(wallos_cron_jobs());

    assert_true(count($scheduled) >= 11, 'the crontab was parsed (' . count($scheduled) . ' entries)');

    // Both directions. A job added to the crontab and not here never appears on
    // the page, so nobody notices when it stops; a job listed here and not in
    // the crontab is reported as overdue for ever.
    assert_same([], array_values(array_diff($scheduled, $known)),
        'every scheduled job is described in wallos_cron_jobs()');
    assert_same(['createdatabase', 'providerprobe'], array_values(array_diff($known, $scheduled)),
        'and the only jobs described but not scheduled are the two startup.sh runs');
});

wallos_test('every job the page describes actually opens a run', function () {
    // The gap this closes: createdatabase was described in wallos_cron_jobs()
    // from the beginning, wallos_cron_write() carried a guard naming it, and
    // the script itself never called wallos_cron_begin(). The overview said
    // "No run recorded" for it on every installation that has ever existed,
    // and the summary counted a permanent one in "never reported" — the count
    // whose entire purpose is to make a dead cron visible.
    //
    // Matching on the script rather than on a list, so a job added later is
    // covered without touching this case.
    foreach (wallos_cron_jobs() as $name => $job) {
        // A job that reports under several names — the recommendations run
        // weekly and monthly — builds the suffix at runtime from one script.
        $script = strpos($name, ':') === false ? $name : strstr($name, ':', true);
        $path = 'endpoints/cronjobs/' . $script . '.php';

        assert_true(file_exists(WALLOS_ROOT . '/' . $path),
            $name . ' is described by wallos_cron_jobs() and has a script: ' . $path);

        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_contains("wallos_cron_begin('" . $script, $source,
            $path . ' opens a reported run, so the job can appear on the page at all');
        assert_contains('wallos_cron_done(', $source,
            $path . ' reaches its own end, so a clean run is not recorded as stopped');
    }
});

wallos_test('a temporary crontab line is removed by the release that says so', function () {
    // 5.10.1 carries one extra rate refresh, at 08:30 on 4 September, so that
    // the provider test runs on the day it was decided. It is deliberate, it is
    // dated, and its removal is written into the line itself — which is worth
    // nothing unless something checks.
    //
    // So this is the check: the marker may exist only while the version is the
    // one that shipped it. The first bump past 5.10.1 fails here until the line
    // is gone, which turns "remember to take it out" into a failing test.
    $marker = 'REMOVE IN 5.10.2';
    $crontab = file_get_contents(WALLOS_ROOT . '/cronjobs');

    if (strpos($crontab, $marker) === false) {
        // Already removed, which is the state this case is steering towards.
        assert_true(true, 'no temporary crontab line is present');

        return;
    }

    $version = file_get_contents(WALLOS_ROOT . '/includes/version.php');

    assert_contains('"v5.10.1"', $version,
        'the temporary 08:30 run is still in the crontab, so the version must '
        . 'still be the one that shipped it — remove the line marked "'
        . $marker . '" before releasing anything else');
});

wallos_test('every scheduled job asks for its exit status', function () {
    $lines = preg_split('/\R/', file_get_contents(WALLOS_ROOT . '/cronjobs'));
    $jobLines = 0;

    foreach ($lines as $line) {
        if (strpos($line, 'cronjobs/') === false || substr(ltrim($line), 0, 1) === '#') {
            continue;
        }

        $jobLines++;

        assert_contains('WALLOS_CRON_STRICT=1', $line, 'the job asks for a real exit status: ' . $line);
        // Standard error is the container's own, so the one ERROR line reaches
        // `docker logs`. Merging it into the log file, which is what 2>&1 used
        // to do, put the only failure signal inside the container.
        assert_contains('2>> /proc/1/fd/2', $line, 'and sends its errors to the container log: ' . $line);
    }

    assert_true($jobLines >= 11, 'every job line was checked (' . $jobLines . ')');
});

wallos_test('every cron job reports itself', function () {
    // strpos would be satisfied by the word appearing in a comment. The
    // tokeniser is asked whether the call is really there.
    $jobs = [
        'checkforupdates', 'cleanupresettokens', 'cleanupsessions', 'generaterecommendations',
        'sendcancellationnotifications', 'sendnotifications', 'sendresetpasswordemails',
        'sendverificationemails', 'storetotalyearlycost', 'updateexchange', 'updatenextpayment',
    ];

    foreach ($jobs as $job) {
        $path = 'endpoints/cronjobs/' . $job . '.php';

        assert_true(wallos_test_file_calls($path, 'wallos_cron_begin'),
            $path . ' starts a reported run');
        // The sentinel. Without it a die() anywhere in the job, or in anything
        // it includes, is indistinguishable from a clean finish.
        assert_true(wallos_test_file_calls($path, 'wallos_cron_done'),
            $path . ' says when it reached the end');
    }
});


// --- a failure that outlives the next success ------------------------------

wallos_test('a failure is still visible after the job succeeds again', function () {
    $db = wallos_test_open_database();

    // Through real processes, not a hand-written row: the question is whether
    // the write path keeps the failure, and the write path is what runs here.
    cron_run_process("wallos_cron_problem('the transport is unusable');");
    cron_run_process("wallos_cron_done('sent=2');");

    $row = cron_run_row($db, 'testjob');

    assert_same(WALLOS_CRON_OK, $row['status'], 'the last run is the one that worked');
    assert_contains('sent=2', $row['detail'], 'and the row describes that run');

    // The half that used to be lost. Before migration 000066 the row above was
    // the whole record, so the night the job died left nothing behind at all.
    assert_same(1, (int) $row['failure_count'], 'the failure is still counted');
    assert_contains('transport is unusable', $row['last_failure_detail'],
        'and it still says what went wrong');
    assert_true(trim((string) $row['last_failure_at']) !== '', 'and when');

    $db->close();
});

wallos_test('a job that never failed carries no failure', function () {
    // The negative control. Without it, a write path that recorded a failure
    // unconditionally would pass every assertion above.
    $db = wallos_test_open_database();

    cron_run_process("wallos_cron_done('nothing queued');");
    $row = cron_run_row($db, 'testjob');

    assert_same(0, (int) $row['failure_count'], 'a job that only ever worked has no failures');
    assert_same('', trim((string) ($row['last_failure_at'] ?? '')), 'and no failure time');

    $db->close();
});

wallos_test('failures accumulate rather than replacing each other', function () {
    $db = wallos_test_open_database();

    cron_run_process("wallos_cron_problem('first');");
    cron_run_process("wallos_cron_problem('second');");
    cron_run_process("wallos_cron_done('recovered');");

    $row = cron_run_row($db, 'testjob');

    assert_same(2, (int) $row['failure_count'], 'both failures are counted');
    assert_contains('second', $row['last_failure_detail'], 'and the most recent one is the one kept');

    $db->close();
});

wallos_test('a job succeeding now but failing lately is not reported as healthy', function () {
    // What the single row hid: green line, fresh timestamp, and a job that
    // dies every third night. sendnotifications runs daily with a two-day
    // staleness window, so a failure six hours ago is well inside it.
    $runs = ['sendnotifications' => cron_run_with_failure(
        cron_run_fake(WALLOS_CRON_OK, 3600, 'sent=2'), 6 * 3600, 3, 'SMTP timeout')];
    $checks = wallos_cron_checks($runs, time());
    $check = cron_run_check($checks, 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_WARNING, $check['status'], 'succeeding now is not the whole answer');
    assert_contains('sent=2', $check['detail'], 'the last run is still described');
    assert_contains('3 times in total', $check['detail'],
        'and so is how often it has failed, said as the lifetime figure it is: '
        . 'the timestamp beside it is recent by construction, so an unlabelled '
        . 'count reads as three recent failures');
    assert_contains('SMTP timeout', $check['detail'], 'with the reason');

    $summary = cron_run_check($checks, 'Scheduled jobs');
    assert_contains('failing intermittently', $summary['detail'],
        'and the summary does not call the installation healthy');
});

wallos_test('an old failure is history rather than a warning', function () {
    // The other side of the window, and the reason it is measured in the job's
    // own schedule: one failure last spring must not colour the page forever.
    $runs = ['sendnotifications' => cron_run_with_failure(
        cron_run_fake(WALLOS_CRON_OK, 3600, 'sent=2'), 30 * 86400, 1, 'SMTP timeout')];
    $check = cron_run_check(wallos_cron_checks($runs, time()), 'Payment notifications');

    assert_same(WALLOS_CRON_CHECK_OK, $check['status'], 'a failure a month ago is not a current problem');
    assert_not_contains('SMTP timeout', $check['detail'], 'and is not repeated on the page');
});

wallos_test('the window follows the schedule, not the calendar', function () {
    // Verification emails run every two minutes with a 30-minute window, so a
    // failure two hours ago is many runs back — while for a daily job the same
    // two hours is the same night. One rule, two right answers.
    $failure = 2 * 3600;
    $frequent = ['sendverificationemails' => cron_run_with_failure(
        cron_run_fake(WALLOS_CRON_OK, 60), $failure)];
    $daily = ['sendnotifications' => cron_run_with_failure(
        cron_run_fake(WALLOS_CRON_OK, 60), $failure)];

    assert_same(WALLOS_CRON_CHECK_OK,
        cron_run_check(wallos_cron_checks($frequent, time()), 'Verification emails')['status'],
        'for a job running every two minutes, two hours ago is long past');
    assert_same(WALLOS_CRON_CHECK_WARNING,
        cron_run_check(wallos_cron_checks($daily, time()), 'Payment notifications')['status'],
        'for a daily job it is last night');
});

// --- the OK line a deploy can read --------------------------------------

wallos_test('a run that did something says so in the container log', function () {
    // The #123 field test found the skip working and the log empty: only
    // failing runs wrote to stderr, so the operator watching a deploy had to
    // open the database to see that the quota guard did its job (#122). A
    // clean run with at least one non-zero count logs one OK line in the
    // ERROR line's shape; a clean run that did nothing stays quiet, because
    // the two-minute jobs would otherwise write 720 lines a day of nothing.
    $db = wallos_test_open_database();

    $busy = cron_run_process("wallos_cron_count('updated', 2); wallos_cron_done('updated=2');");
    assert_contains('[Wallos cron] OK job=testjob', $busy['stderr'],
        'a run with work reports itself (stderr: ' . $busy['stderr'] . ')');
    assert_contains('updated=2', $busy['stderr'], 'and carries its detail');

    $idle = cron_run_process("wallos_cron_count('sent', 0); wallos_cron_done('nothing queued');");
    assert_true(strpos($idle['stderr'], '[Wallos cron] OK') === false,
        'a run with nothing to do stays out of the log (stderr: ' . $idle['stderr'] . ')');

    $failed = cron_run_process("wallos_cron_problem('broken'); wallos_cron_done();", 'testjob', false);
    assert_contains('[Wallos cron] ERROR', $failed['stderr'], 'failures keep their line');
    assert_true(strpos($failed['stderr'], '[Wallos cron] OK') === false,
        'and never also claim an OK');

    $db->close();
});
