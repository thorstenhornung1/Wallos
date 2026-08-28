<?php
/*
  What Wallos can say about its own scheduled jobs without anyone reading a log.

  The failure this exists for is not a job that breaks loudly. It is a job that
  stops: the crontab that startup.sh installed and then deleted, where the jobs
  kept running only because dcron holds the parsed table in memory, and nobody
  noticed for as long as that had been true. The container healthcheck covers
  nginx and php-fpm, both of which stay perfectly healthy while nothing is
  scheduled at all.

  So the check is not "did the last run fail". It is "when did each job last
  report, and should it have reported since". A job that is never started
  writes nothing, and nothing is precisely what a dead cron produces — which
  is why the absence of a row has to be a finding rather than a blank.

  wallos_cron_checks() takes rows and a clock and no I/O, so a stale job can be
  tested without waiting a week for one. wallos_cron_diagnostics() adds the
  reading. Deliberately the same shape as includes/oidc/diagnostics.php, which
  admin.php already knows how to render.
*/

require_once __DIR__ . '/../cron_run.php';

const WALLOS_CRON_CHECK_OK = 'ok';
const WALLOS_CRON_CHECK_WARNING = 'warning';
const WALLOS_CRON_CHECK_ERROR = 'error';
const WALLOS_CRON_CHECK_UNKNOWN = 'unknown';

/**
 * Every job Wallos schedules, and how long it may stay silent.
 *
 * stale_after is deliberately more than one missed run. A container restart at
 * the wrong minute skips one; two in a row is the schedule not happening.
 * Jobs with no schedule of their own carry stale_after 0 and are only ever
 * reported on their last outcome — there is no interval to be overdue against.
 *
 * The names are the ones the jobs pass to wallos_cron_begin(), and
 * tests/cases/cron_reporting_test.php checks this list against the crontab, so
 * a job added to one and not the other is a failing test rather than a job
 * that silently never appears here.
 *
 * @return array<string, array{label: string, schedule: string, stale_after: int}>
 */
function wallos_cron_jobs()
{
    $day = 86400;

    return [
        'updatenextpayment' => [
            'label' => 'Next payment dates',
            'schedule' => 'daily at 01:00, and at startup',
            'stale_after' => 2 * $day,
        ],
        'updateexchange' => [
            'label' => 'Exchange rates',
            'schedule' => 'daily at 02:00, and at startup',
            'stale_after' => 2 * $day,
        ],
        'sendcancellationnotifications' => [
            'label' => 'Cancellation notifications',
            'schedule' => 'daily at 08:00',
            'stale_after' => 2 * $day,
        ],
        'sendnotifications' => [
            'label' => 'Payment notifications',
            'schedule' => 'daily at 09:00',
            'stale_after' => 2 * $day,
        ],
        'sendverificationemails' => [
            'label' => 'Verification emails',
            'schedule' => 'every 2 minutes',
            'stale_after' => 1800,
        ],
        'sendresetpasswordemails' => [
            'label' => 'Password reset emails',
            'schedule' => 'every 2 minutes',
            'stale_after' => 1800,
        ],
        'checkforupdates' => [
            'label' => 'Update check',
            'schedule' => 'every 6 hours, and at startup',
            'stale_after' => 13 * 3600,
        ],
        'storetotalyearlycost' => [
            'label' => 'Cost history',
            'schedule' => 'weekly, Monday 01:30',
            'stale_after' => 9 * $day,
        ],
        'cleanupresettokens' => [
            'label' => 'Expired reset tokens',
            'schedule' => 'daily at 03:00',
            'stale_after' => 2 * $day,
        ],
        'cleanupsessions' => [
            'label' => 'Expired sessions',
            'schedule' => 'daily at 03:15',
            'stale_after' => 2 * $day,
        ],
        'generaterecommendations:weekly' => [
            'label' => 'AI recommendations (weekly)',
            'schedule' => 'weekly, Monday 03:30',
            'stale_after' => 9 * $day,
        ],
        'generaterecommendations:monthly' => [
            'label' => 'AI recommendations (monthly)',
            'schedule' => 'monthly, the 1st at 04:00',
            'stale_after' => 63 * $day,
        ],
        'createdatabase' => [
            'label' => 'Schema installation',
            'schedule' => 'at startup',
            'stale_after' => 0,
        ],
    ];
}

/**
 * Builds one finding, in the shape admin.php already renders.
 *
 * @param string $label
 * @param string $status
 * @param string $detail
 * @return array{label: string, status: string, detail: string}
 */
function wallos_cron_check($label, $status, $detail)
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail];
}

/**
 * A duration a person can read, from seconds.
 *
 * @param int $seconds
 * @return string
 */
function wallos_cron_describe_age($seconds)
{
    $seconds = max(0, (int) $seconds);

    if ($seconds < 90) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    if ($seconds < 5400) {
        $minutes = (int) round($seconds / 60);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    if ($seconds < 172800) {
        $hours = (int) round($seconds / 3600);

        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    $days = (int) round($seconds / 86400);

    return $days . ' day' . ($days === 1 ? '' : 's');
}

/**
 * Reads a timestamp written by wallos_cron_write().
 *
 * They are stored as UTC text, so they are parsed as UTC text. Letting
 * strtotime() apply the container's timezone would make every job look hours
 * fresher or staler than it is, which is the whole quantity being measured.
 *
 * @param string $value
 * @return int|null unix time, or null when it cannot be read
 */
function wallos_cron_parse_time($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $parsed = strtotime($value . ' UTC');

    return $parsed === false ? null : $parsed;
}

/**
 * A failure recent enough to say something about a job that is succeeding now.
 *
 * Migration 000066 keeps the last failure and a cumulative count in the row,
 * because the row is replaced on every run and a job that fails intermittently
 * would otherwise look perfect the morning after it worked.
 *
 * "Recent" is three of the job's own staleness windows, not a fixed number of
 * days: the same rule has to be right for a job that runs hourly and one that
 * runs monthly. Beyond that the failure is history — worth keeping in the
 * column, not worth colouring the page over.
 *
 * @param array $row           a row from cron_runs
 * @param int   $now           unix time
 * @param int   $staleAfter    the job's staleness window in seconds, 0 if unscheduled
 * @return array{when: string, count: int, detail: string}|null
 */
function wallos_cron_recent_failure($row, $now, $staleAfter)
{
    $count = (int) ($row['failure_count'] ?? 0);
    $at = wallos_cron_parse_time((string) ($row['last_failure_at'] ?? ''));

    if ($count === 0 || $at === null) {
        return null;
    }

    // An unscheduled job — createdatabase runs once from startup.sh — has no
    // interval to measure against. A day is the honest default there.
    $window = $staleAfter > 0 ? $staleAfter * 3 : 86400;

    if ($now - $at > $window) {
        return null;
    }

    return [
        'when' => wallos_cron_describe_age(max(0, $now - $at)) . ' ago',
        'count' => $count,
        'detail' => (string) ($row['last_failure_detail'] ?? ''),
    ];
}

/**
 * Evaluates the recorded runs.
 *
 * @param array<string, array> $runs job name => row from cron_runs
 * @param int                  $now  unix time
 * @return array<int, array{label: string, status: string, detail: string}>
 */
function wallos_cron_checks($runs, $now)
{
    $jobs = wallos_cron_jobs();
    $checks = [];
    $failed = 0;
    $overdue = 0;
    $silent = 0;
    $unreliable = 0;

    // Jobs cron actually starts. createdatabase runs once from startup.sh and
    // has no interval to be late against, so counting it would stop "every job
    // is overdue" from ever being true — which is the one finding that names
    // the cause instead of describing twelve symptoms.
    $scheduled = 0;
    foreach ($jobs as $job) {
        if ($job['stale_after'] > 0) {
            $scheduled++;
        }
    }

    foreach ($jobs as $name => $job) {
        $row = $runs[$name] ?? null;

        if ($row === null) {
            // Not a blank. A job that has never reported is either newly added
            // or never started, and the second is what a dead cron looks like.
            $silent++;
            $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_UNKNOWN,
                'No run recorded — ' . $job['schedule'] . '.');

            continue;
        }

        $finished = wallos_cron_parse_time($row['finished_at'] ?? '');
        $detail = (string) ($row['detail'] ?? '');

        if ($finished === null) {
            // Reported as unknown rather than as success, because the answer to
            // "is this job still running" is being read out of this timestamp:
            // treating an unreadable one as fresh is how a dead cron would show
            // twelve green lines. Found by breaking it — a fixture wrote empty
            // timestamps and the page called the installation healthy.
            $silent++;
            $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_UNKNOWN,
                'The last run is recorded with an unreadable time, so Wallos cannot tell '
                . 'whether this job is still running.');

            continue;
        }

        $age = max(0, $now - $finished);
        $when = wallos_cron_describe_age($age) . ' ago';

        if (($row['status'] ?? '') === WALLOS_CRON_FAILED) {
            $failed++;
            $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_ERROR,
                'Failed ' . $when . ($detail === '' ? '.' : ': ' . $detail));

            continue;
        }

        if ($job['stale_after'] > 0 && $age > $job['stale_after']) {
            // The job last succeeded, so nothing is broken inside it. What is
            // broken is that it has not been started since — which nothing
            // else in this container reports.
            $overdue++;
            $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_ERROR,
                'Last succeeded ' . $when . ', but it runs ' . $job['schedule']
                . '. Nothing has started it since — check that cron is running.');

            continue;
        }

        $succeeded = 'Succeeded ' . $when . ' (' . $job['schedule'] . ')'
            . ($detail === '' ? '.' : ': ' . $detail);

        // A job that succeeded most recently but failed within its last few
        // runs is the case the single row used to hide: green line, fresh
        // timestamp, and a job nobody can rely on. The window is measured in
        // this job's own schedule rather than in days, so an hourly job and a
        // monthly one are both judged against how often they run.
        $recent = wallos_cron_recent_failure($row, $now, $job['stale_after']);

        if ($recent !== null) {
            $unreliable++;
            $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_WARNING,
                $succeeded . ' It last failed ' . $recent['when'] . ' and has failed '
                . $recent['count'] . ' time' . ($recent['count'] === 1 ? '' : 's')
                . ($recent['detail'] === '' ? '.' : ': ' . $recent['detail']));

            continue;
        }

        $checks[] = wallos_cron_check($job['label'], WALLOS_CRON_CHECK_OK, $succeeded);
    }

    // The summary goes first, because a section with twelve green lines and one
    // red one is read top to bottom and the red one is at the bottom.
    array_unshift($checks, wallos_cron_summary(count($jobs), $failed, $overdue, $silent, $scheduled,
        $unreliable));

    return $checks;
}

/**
 * The one line that has to be true at a glance.
 *
 * @param int $total
 * @param int $failed
 * @param int $overdue
 * @param int $silent
 * @param int $scheduled how many of them cron is supposed to start
 * @return array{label: string, status: string, detail: string}
 */
function wallos_cron_summary($total, $failed, $overdue, $silent, $scheduled = 0, $unreliable = 0)
{
    if ($scheduled > 0 && $overdue === $scheduled) {
        // Every single job overdue is not twelve independent problems. It is
        // one, and naming it saves the reader from diagnosing each job.
        return wallos_cron_check('Scheduled jobs', WALLOS_CRON_CHECK_ERROR,
            'None of the ' . $scheduled . ' scheduled jobs has run recently. '
            . 'Cron is not running in this container.');
    }

    $problems = [];
    if ($failed > 0) {
        $problems[] = $failed . ' failed';
    }
    if ($overdue > 0) {
        $problems[] = $overdue . ' overdue';
    }
    if ($silent > 0) {
        $problems[] = $silent . ' never reported';
    }
    if ($unreliable > 0) {
        // Named separately from 'failed', because these are succeeding right
        // now. Folding the two together would make the summary say something
        // the job list below it contradicts.
        $problems[] = $unreliable . ' failing intermittently';
    }

    if ($problems === []) {
        return wallos_cron_check('Scheduled jobs', WALLOS_CRON_CHECK_OK,
            'All ' . $total . ' jobs reported success on their last run.');
    }

    return wallos_cron_check('Scheduled jobs',
        $failed + $overdue > 0 ? WALLOS_CRON_CHECK_ERROR : WALLOS_CRON_CHECK_WARNING,
        $total . ' jobs: ' . implode(', ', $problems) . '.');
}

/**
 * The most severe finding, for callers that want one answer.
 *
 * @param array $checks
 * @return string
 */
function wallos_cron_worst_status($checks)
{
    // "unknown" means Wallos cannot tell rather than that something is wrong,
    // so it never decides the summary — the same rule the OIDC page follows.
    foreach ([WALLOS_CRON_CHECK_ERROR, WALLOS_CRON_CHECK_WARNING] as $status) {
        foreach ($checks as $check) {
            if ($check['status'] === $status) {
                return $status;
            }
        }
    }

    return WALLOS_CRON_CHECK_OK;
}

/**
 * Reads what the jobs recorded.
 *
 * @param WallosDatabase $db
 * @return array<string, array>
 */
function wallos_cron_runs($db)
{
    if (!$db->tableExists('cron_runs')) {
        return [];
    }

    // The failure columns came with migration 000066; an installation that has
    // not run it yet must still get its runs rather than an empty page.
    $result = $db->columnExists('cron_runs', 'failure_count')
        ? $db->query('SELECT job, status, started_at, finished_at, duration_ms, detail,
                             last_failure_at, last_failure_detail, failure_count FROM cron_runs')
        : $db->query('SELECT job, status, started_at, finished_at, duration_ms, detail FROM cron_runs');

    if ($result === false) {
        return [];
    }

    $runs = [];
    while ($row = $result->fetchArray()) {
        $runs[$row['job']] = $row;
    }

    return $runs;
}

/**
 * Evaluates the live record.
 *
 * @param WallosDatabase $db
 * @return array{checks: array, worst: string}
 */
function wallos_cron_diagnostics($db)
{
    $checks = wallos_cron_checks(wallos_cron_runs($db), time());

    return [
        'checks' => $checks,
        'worst' => wallos_cron_worst_status($checks),
    ];
}
