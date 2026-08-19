<?php
/*
  What a scheduled job says when it fails.

  Every cron job in Wallos used to end the same way whatever happened: exit
  status 0, output appended to a file inside the container, and nothing anywhere
  that had been told the job ran at all. A fatal in sendnotifications survived
  an unknown number of nights and was found by a person working through a test
  plan by hand — because a fatal and a quiet night look identical from outside.

  Three signals replace that, in the order an operator meets them:

    * a non-zero exit status, so the shell, a supervisor or a `podman exec`
      probe can tell the difference;
    * one line on standard error, which under both CLI and php-fpm is where
      error_log() writes in this image, so it reaches the container log rather
      than a file nobody mounts;
    * a row in cron_runs, so the admin page can show a job that stopped running
      without anyone reading a log at all.

  The third is the one that catches the failure nobody is watching for. A job
  that dies writes a row saying so; a job that is never started writes nothing,
  and its row goes stale — which is the only way "cron itself is dead" can be
  noticed, and it has been dead here before.

  Shape of a job:

      require_once __DIR__ . '/../../includes/cron_run.php';
      wallos_cron_begin('updateexchange');

      require_once 'validate.php';
      require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
      wallos_cron_database($db);

      ... work, calling wallos_cron_problem() where a failure is survivable ...

      wallos_cron_done('42 rates updated');

  wallos_cron_done() is not decoration. It is the sentinel that separates
  "finished" from "stopped": die() and exit() leave no trace a shutdown handler
  can read — error_get_last() is null and the status is 0 — and Wallos reaches
  die() through validate.php, connect_endpoint_crontabs.php and the fatal
  variant of the SSRF check. A run that never says it finished is a failure.
*/

require_once __DIR__ . '/database/connection.php';

/** A run that did what it was asked, including doing nothing when there was nothing to do. */
const WALLOS_CRON_OK = 'ok';

/** A run that did not. */
const WALLOS_CRON_FAILED = 'failed';

/** What a failing job exits with. Every job, one value, so a caller can test for it. */
const WALLOS_CRON_EXIT_FAILED = 1;

/**
 * Whether a failed run is allowed to say so in its exit status.
 *
 * It is not on by default, and the reason is startup.sh. That script runs under
 * `set -euo pipefail` and invokes four of these jobs before it reaches `wait`,
 * so a non-zero exit from any of them stops the container from starting at all.
 * Measured, not assumed: with the exit status unconditional, an installation
 * whose currency provider refuses its key — which is the dev environment's
 * deliberate configuration — never comes up.
 *
 * Refusing to start over a failed exchange-rate refresh is a worse outcome than
 * the refresh failing. So the caller that wants the status asks for it, and the
 * caller that does is cron: every line of the crontab sets WALLOS_CRON_STRICT=1,
 * which is exactly the set of runs nobody is watching.
 *
 * A run without it is not silent. The log line and the recorded row are
 * unconditional; only the exit status is not.
 *
 * @return bool
 */
function wallos_cron_strict()
{
    return getenv('WALLOS_CRON_STRICT') === '1';
}

/** How much of the accumulated detail is kept. Enough for a stack of reasons, not a log file. */
const WALLOS_CRON_DETAIL_LIMIT = 2000;

/**
 * Starts a reported run.
 *
 * Call this before anything that can fail, which means before the requires:
 * connect_endpoint_crontabs.php dies when the database is unreachable, and
 * that death is exactly the kind this exists to make visible.
 *
 * @param string $job the name the job is known by, matching wallos_cron_jobs()
 * @return void
 */
function wallos_cron_begin($job)
{
    $GLOBALS['wallos_cron_run'] = [
        'job' => (string) $job,
        'started' => microtime(true),
        'started_at' => gmdate('Y-m-d H:i:s'),
        'problems' => [],
        'counts' => [],
        'summary' => '',
        'finished' => false,
        'recorded' => false,
        'database' => null,
    ];

    // An uncaught throwable already exits 255, so the handler is not here for
    // the status. It is here for the message: without it the reason reaches
    // stdout, which cron sends to a file, and the row records "stopped before
    // it finished" without saying what stopped it.
    set_exception_handler('wallos_cron_uncaught');

    register_shutdown_function('wallos_cron_shutdown');
}

/**
 * Offers the job's own connection for writing the outcome.
 *
 * Optional, and only an optimisation: the recorder opens its own connection
 * when it has none. Handing it this one avoids a second connection in the
 * ordinary case.
 *
 * @param WallosDatabase $db
 * @return void
 */
function wallos_cron_database($db)
{
    if (isset($GLOBALS['wallos_cron_run'])) {
        $GLOBALS['wallos_cron_run']['database'] = $db;
    }
}

/**
 * Records something that went wrong, without stopping.
 *
 * For the failures a job can survive: one notification channel of ten refused,
 * one user's provider unreachable. The run continues and still exits non-zero,
 * because "sent four of five" is not a success and the fifth recipient is the
 * one who needed telling.
 *
 * @param string $message
 * @return void
 */
function wallos_cron_problem($message)
{
    $message = trim(preg_replace('/[\r\n\s]+/', ' ', (string) $message));

    if ($message === '' || !isset($GLOBALS['wallos_cron_run'])) {
        return;
    }

    // Ten users failing for one reason is one finding, not ten lines. The count
    // is kept so the detail can say how many.
    $problems = &$GLOBALS['wallos_cron_run']['problems'];
    if (isset($problems[$message])) {
        $problems[$message]++;

        return;
    }

    $problems[$message] = 1;
}

/**
 * Records something that went wrong and stops the run here.
 *
 * The replacement for die('some message'), which exits 0.
 *
 * @param string $message
 * @return void
 */
function wallos_cron_fail($message)
{
    wallos_cron_problem($message);

    // The shutdown handler decides the status again on the way out; this is
    // only about not leaving a stray 1 behind when the caller did not ask for
    // one. See wallos_cron_strict().
    exit(wallos_cron_strict() ? WALLOS_CRON_EXIT_FAILED : 0);
}

/**
 * Counts a unit of work, so the outcome can say how much of it succeeded.
 *
 * The distinction that matters is not "did it throw" but "did it deliver".
 * A run that attempted five notifications and sent none is the shape of
 * failure these jobs actually have.
 *
 * @param string $key
 * @param int    $increment
 * @return void
 */
function wallos_cron_count($key, $increment = 1)
{
    if (!isset($GLOBALS['wallos_cron_run'])) {
        return;
    }

    $counts = &$GLOBALS['wallos_cron_run']['counts'];
    $counts[$key] = ($counts[$key] ?? 0) + (int) $increment;
}

/**
 * Says the job reached its own end.
 *
 * Everything after this point is bookkeeping. A run that does not call it is
 * reported as having stopped, whatever its exit status claims.
 *
 * @param string $summary what it did, in a few words, for the admin page
 * @return void
 */
function wallos_cron_done($summary = '')
{
    if (!isset($GLOBALS['wallos_cron_run'])) {
        return;
    }

    $GLOBALS['wallos_cron_run']['finished'] = true;

    if ($summary !== '') {
        $GLOBALS['wallos_cron_run']['summary'] = trim(preg_replace('/[\r\n\s]+/', ' ', (string) $summary));
    }
}

/**
 * Why a database call failed, in words worth printing.
 *
 * SQLite answers lastErrorMsg() with the literal string "not an error" when a
 * statement failed for a reason it does not keep on the connection — a write
 * that waited out its busy timeout is the common one. Repeating that inside a
 * failure line produces "could not record the run date: not an error", which
 * reads like the job is confused about whether anything went wrong.
 *
 * @param WallosDatabase $db
 * @return string
 */
function wallos_cron_reason($db)
{
    $message = trim((string) $db->lastErrorMsg());

    return ($message === '' || strcasecmp($message, 'not an error') === 0)
        ? 'the database gave no reason (a locked or busy database looks like this)'
        : $message;
}

/**
 * Turns an uncaught throwable into a reason before PHP prints it to stdout.
 *
 * @param Throwable $error
 * @return void
 */
function wallos_cron_uncaught($error)
{
    wallos_cron_problem(sprintf(
        'uncaught %s: %s at %s:%d',
        get_class($error),
        $error->getMessage(),
        basename($error->getFile()),
        $error->getLine()
    ));
}

/**
 * The error levels that end a run whether or not anyone catches them.
 *
 * The two that happen here are the execution time limit and memory exhaustion.
 * Neither is catchable and neither reaches set_exception_handler, so this is
 * the only place they can be noticed — and the first is not hypothetical:
 * generaterecommendations.php sets a 300 second limit and then gives a single
 * slow AI provider a 300 second cURL timeout.
 *
 * A method call on false is not in this category. PHP 8 raises that as an
 * Error, which is a Throwable and arrives through the exception handler above.
 *
 * @return int[]
 */
function wallos_cron_fatal_levels()
{
    return [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
}

/**
 * Decides the outcome, says it three ways, and sets the exit status.
 *
 * @return void
 */
function wallos_cron_shutdown()
{
    if (!isset($GLOBALS['wallos_cron_run']) || $GLOBALS['wallos_cron_run']['recorded']) {
        return;
    }

    $GLOBALS['wallos_cron_run']['recorded'] = true;

    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'], wallos_cron_fatal_levels(), true)) {
        wallos_cron_problem(sprintf(
            'fatal: %s at %s:%d',
            $fatal['message'],
            basename($fatal['file']),
            $fatal['line']
        ));
    }

    $run = $GLOBALS['wallos_cron_run'];

    if (!$run['finished'] && $run['problems'] === []) {
        // die() and exit() land here: no fatal, no exception, status 0, and no
        // trace of why. Naming the shape is the most that can be said, and it
        // is still the difference between a failure and a quiet night.
        wallos_cron_problem('stopped before it finished, with no error reported'
            . ' — a die() or exit() on a path that should have reported one');
        $run = $GLOBALS['wallos_cron_run'];
    }

    $status = $run['problems'] === [] ? WALLOS_CRON_OK : WALLOS_CRON_FAILED;
    $duration = (int) round((microtime(true) - $run['started']) * 1000);
    $detail = wallos_cron_detail($run);

    // The log line first, because it is the signal that needs no working
    // database. Recording the row can itself fail; this cannot.
    if ($status === WALLOS_CRON_FAILED) {
        error_log(sprintf('[Wallos cron] ERROR job=%s duration=%dms %s', $run['job'], $duration, $detail));
    }

    wallos_cron_record($run, $status, $duration, $detail);

    if ($status === WALLOS_CRON_FAILED && wallos_cron_strict()) {
        exit(WALLOS_CRON_EXIT_FAILED);
    }

    // No exit() on the way out of a successful run. PHP's own status is
    // already 0, and calling exit() here would stop any shutdown function
    // registered after this one from running at all.
}

/**
 * The one string that has to carry both what happened and what was achieved.
 *
 * Counts come first because they are what distinguishes "nothing to do" from
 * "nothing got through", and those two are the same silence today.
 *
 * @param array $run
 * @return string
 */
function wallos_cron_detail($run)
{
    $parts = [];

    foreach ($run['counts'] as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    if ($run['summary'] !== '') {
        $parts[] = $run['summary'];
    }

    foreach ($run['problems'] as $message => $occurrences) {
        $parts[] = $occurrences > 1 ? $message . ' (x' . $occurrences . ')' : $message;
    }

    $detail = implode('; ', $parts);

    return strlen($detail) > WALLOS_CRON_DETAIL_LIMIT
        ? substr($detail, 0, WALLOS_CRON_DETAIL_LIMIT - 3) . '...'
        : $detail;
}

/**
 * Writes the outcome where the admin page reads it.
 *
 * One row per job, replaced each run. History would need a retention job of
 * its own, and the question this answers — did the thing that should have run
 * last night run, and did it work — needs only the last answer.
 *
 * Best effort by construction. A job whose database is gone still has its exit
 * status and its log line, and those were emitted before this ran.
 *
 * @param array  $run
 * @param string $status
 * @param int    $duration
 * @param string $detail
 * @return bool whether the row was written
 */
function wallos_cron_record($run, $status, $duration, $detail)
{
    $db = $run['database'];

    try {
        if ($db !== null && wallos_cron_write($db, $run, $status, $duration, $detail)) {
            return true;
        }
    } catch (Throwable $error) {
        // The job's connection is closed, or PostgreSQL has aborted the
        // transaction the job died inside and is refusing every statement
        // until it is rolled back. Both are worth a second attempt on a
        // connection of our own rather than a lost outcome.
        $db = null;
    }

    require_once __DIR__ . '/database/configuration.php';
    $configuration = wallos_database_configuration();

    if ($configuration['error'] !== null) {
        // wallos_database_connect() answers a broken configuration by exiting,
        // which inside a shutdown function would drop the status this run has
        // already decided on. The log line is out; leave it at that.
        return false;
    }

    try {
        $own = wallos_database_connect();
        $written = wallos_cron_write($own, $run, $status, $duration, $detail);
        $own->close();

        return $written;
    } catch (Throwable $error) {
        error_log(sprintf('[Wallos cron] ERROR job=%s could not record its outcome: %s',
            $run['job'], $error->getMessage()));

        return false;
    }
}

/**
 * The write itself, against whichever connection is usable.
 *
 * ON CONFLICT rather than a delete and an insert: both backends have it, and a
 * job that dies between the two would leave no row at all — which the admin
 * page cannot tell apart from a job that has never run.
 *
 * @param WallosDatabase $db
 * @param array          $run
 * @param string         $status
 * @param int            $duration
 * @param string         $detail
 * @return bool
 */
function wallos_cron_write($db, $run, $status, $duration, $detail)
{
    if (!$db->tableExists('cron_runs')) {
        // An installation that has not run migrations yet. createdatabase.php
        // reports through this file and runs before them.
        return false;
    }

    $statement = $db->prepare('INSERT INTO cron_runs (job, status, started_at, finished_at, duration_ms, detail)
                               VALUES (:job, :status, :startedAt, :finishedAt, :duration, :detail)
                               ON CONFLICT (job) DO UPDATE SET
                                   status = :status,
                                   started_at = :startedAt,
                                   finished_at = :finishedAt,
                                   duration_ms = :duration,
                                   detail = :detail');

    if ($statement === false) {
        return false;
    }

    $statement->bindValue(':job', $run['job']);
    $statement->bindValue(':status', $status);
    $statement->bindValue(':startedAt', $run['started_at']);
    // gmdate, not date: the column is compared against gmdate('U') when the
    // admin page decides whether a job is overdue, and a container with TZ set
    // would otherwise write local time and read it as UTC.
    $statement->bindValue(':finishedAt', gmdate('Y-m-d H:i:s'));
    $statement->bindValue(':duration', $duration);
    $statement->bindValue(':detail', $detail);

    return $statement->execute() !== false;
}
