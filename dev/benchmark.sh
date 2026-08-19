#!/bin/sh
# Repeatable performance measurement against a running Wallos.
#
#   dev/benchmark.sh                                  local dev environment
#   dev/benchmark.sh --base https://test.example.de \
#                    --user admin --password-stdin \
#                    --exec 'docker exec wallos-test_wallos.1.abc'
#
# Two axes, because they stress different things:
#
#   list size   one user with N subscriptions — page rendering, rate conversion,
#               the subscription index
#   user count  M users with notifications — the cron job's per-user work
#
# Every figure is the median of five runs. Seeded rows are prefixed "seed-",
# rows added to the measured account "bench-", and both are removed at the end;
# real accounts are never touched.
#
# Everything that touches a database does so through dev/bench.php, which
# connects with wallos_database_connect() and cannot be pointed at a file. Two
# things follow, and both were defects here (issue #91): the rows are written to
# the database the measured pages read from, whichever backend that is, and the
# cleanup cannot reach a database that is not the one under test.
#
# The password is read from the environment, a file, or standard input. It used
# to be an argument, where `ps` shows it to every process on the machine.

set -eu

# curl formats %{time_total} with the locale's decimal separator; awk expects a point.
export LC_ALL=C

BASE=${WALLOS_BASE:-http://localhost:8383}
USERNAME=${WALLOS_USER:-e2e}
PASSWORD=${WALLOS_PASSWORD:-E2ePass123!}
ENGINE=${CONTAINER_ENGINE:-podman}
CONTAINER=${WALLOS_CONTAINER:-wallos-dev}
EXEC=""
RUNS=5
# Per-run wall-clock bounds. A benchmark that hangs measures nothing and says
# nothing; one that gives up after a bound says which figure is missing.
RATES_TIMEOUT=${WALLOS_RATES_TIMEOUT:-20}
CRON_TIMEOUT=${WALLOS_CRON_TIMEOUT:-180}

while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE=$2; shift 2 ;;
        --user) USERNAME=$2; shift 2 ;;
        --password)
            # Kept so the command in docs/test-instance.md keeps working, but it
            # puts the credential in the process table for the whole run.
            PASSWORD=$2
            printf 'warning: --password is visible in `ps`; prefer --password-file or --password-stdin\n' >&2
            shift 2
            ;;
        --password-file)
            [ -r "$2" ] || { printf 'cannot read %s\n' "$2" >&2; exit 2; }
            PASSWORD=$(head -n 1 "$2")
            shift 2
            ;;
        --password-stdin)
            IFS= read -r PASSWORD
            shift
            ;;
        --exec) EXEC=$2; shift 2 ;;
        --runs) RUNS=$2; shift 2 ;;
        --rates-timeout) RATES_TIMEOUT=$2; shift 2 ;;
        --cron-timeout) CRON_TIMEOUT=$2; shift 2 ;;
        *) printf 'unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
done

[ -n "$EXEC" ] || EXEC="$ENGINE exec $CONTAINER"

BENCH="php /var/www/html/dev/bench.php"

COOKIES=$(mktemp)
trap 'rm -f "$COOKIES"' EXIT

seed() {
    $EXEC php /var/www/html/dev/seed.php "$1" "$2" >/dev/null
}

login() {
    rm -f "$COOKIES"
    curl -s -c "$COOKIES" "$BASE/login.php" -o /dev/null
    curl -s -b "$COOKIES" -c "$COOKIES" -X POST "$BASE/login.php" \
        --data-urlencode "username=$USERNAME" \
        --data-urlencode "password=$PASSWORD" -o /dev/null
}

# A page that redirects to the login form answers in 8ms, and a table of 8ms
# figures looks like a fast application rather than a failed sign-in. Each page
# is fetched once and checked before anything is timed.
check_page() {
    status_size=$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code} %{size_download}' "$BASE/$1")
    status=${status_size% *}
    size=${status_size#* }

    if [ "$status" != "200" ] || [ "$size" -lt 1000 ]; then
        printf '\n%s answered %s with %s bytes — signed in as %s?\n' "$1" "$status" "$size" "$USERNAME" >&2
        exit 1
    fi
}

# Median of RUNS timings, in milliseconds.
measure_page() {
    path=$1
    i=0
    while [ "$i" -lt "$RUNS" ]; do
        curl -s -b "$COOKIES" -o /dev/null -w '%{time_total}\n' "$BASE/$path"
        i=$((i + 1))
    done | sort -n | awk -v n="$RUNS" 'NR == int((n + 1) / 2) { printf "%.0f", $1 * 1000 }'
}

# Timed from inside a PHP process rather than with `date +%s%N`, which is not
# portable: BusyBox date — what the Alpine-based image ships — ignores %N and
# returns whole seconds, so two readings inside the same second differ by zero.
# That produced a table of 0ms figures, baseline included, which reads like
# "too fast to measure" rather than "not measured".
measure_cron() {
    $EXEC $BENCH measure "$1" "$RUNS" "$CRON_TIMEOUT"
}

# Milliseconds, or whatever word the measurement produced instead.
cell() {
    case "$1" in
        ''|*[!0-9]*) printf '%11s' "$1" ;;
        *) printf '%9sms' "$1" ;;
    esac
}

TARGET=$($EXEC $BENCH target)

printf '\nWallos benchmark — %s, median of %d runs\n' "$BASE" "$RUNS"
printf 'database        %s\n\n' "$TARGET"

login

# The account is the link between the two halves of this script: the pages are
# fetched over HTTP as this user, the rows are written over a CLI connection. If
# the account is not in the database the CLI just opened, the two halves are
# talking to different databases and every figure below would be meaningless.
# That is exactly what happened in issue #91, and it ran for 24 minutes first.
$EXEC $BENCH account "$USERNAME" >/dev/null

for page in subscriptions.php stats.php calendar.php; do
    check_page "$page"
done

printf 'Subscription list, one user\n'
printf '  %-12s %11s %11s %11s\n' 'entries' 'list' 'stats' 'calendar'
for count in 100 1000 5000; do
    written=$($EXEC $BENCH subscriptions "$USERNAME" "$count")
    if [ "$written" != "$count" ]; then
        printf '\nasked for %s subscriptions, the database holds %s\n' "$count" "$written" >&2
        exit 1
    fi
    printf '  %-12s %s %s %s\n' "$count" \
        "$(cell "$(measure_page subscriptions.php)")" \
        "$(cell "$(measure_page stats.php)")" \
        "$(cell "$(measure_page calendar.php)")"
done

# Whether the rates column can measure anything is decided once, before any
# tier runs, and the answer is printed with the table rather than left to be
# inferred from a suspiciously round number.
RATES=$($EXEC $BENCH rates-preflight "$RATES_TIMEOUT")
RATES_VERDICT=$(printf '%s' "$RATES" | cut -f1)
RATES_NOTE=$(printf '%s' "$RATES" | cut -f2)

printf '\nNotification cron, all users\n'
printf '  %-12s %11s %11s\n' 'users' 'notify' 'rates'
printf '  %-12s %s %11s\n' 'baseline' "$(cell "$(measure_cron dev/noop.php)")" '-'
for users in 1 10 100; do
    seed "$users" 10
    $EXEC $BENCH notifications >/dev/null

    if [ "$RATES_VERDICT" = "ok" ]; then
        rates=$(measure_cron endpoints/cronjobs/updateexchange.php)

        case "$rates" in
            ''|*[!0-9]*)
                # The bound expired. Every later tier has more accounts and
                # would expire again, so the column stops here instead of paying
                # the same bound twice more for an answer already known. The
                # figure that was measured stays in the table.
                RATES_VERDICT=timeout
                RATES_NOTE="the job passed the ${CRON_TIMEOUT}s bound at $users account(s); later tiers were not attempted"
                ;;
        esac
    else
        rates="skipped"
    fi

    printf '  %-12s %s %s\n' "$users" \
        "$(cell "$(measure_cron endpoints/cronjobs/sendnotifications.php)")" \
        "$(cell "$rates")"
done

printf '\n  baseline is an empty script: interpreter start-up, included in every row above.\n'

if [ "$RATES_VERDICT" = "ok" ]; then
    printf '  rates measured against a live provider — each run spends provider quota.\n'
else
    printf '  rates not measured (%s): %s.\n' "$RATES_VERDICT" "$RATES_NOTE"
    printf '  A run against a provider that never answers measures the timeout, not the job.\n'
fi

printf '\nRemoving seeded data\n'
$EXEC $BENCH cleanup

printf '\n'
