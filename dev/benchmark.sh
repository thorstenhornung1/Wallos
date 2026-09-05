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
# Each axis is a geometric x10 ladder (list 100/1k/10k, cron 1/10/100 users), and
# each table prints, beneath the medians, the normalized growth factor F/R for
# every step: how fast a figure grew divided by how fast the size grew. That
# factor, not any absolute figure, is what this measures — it cancels the
# environment's constant overhead, so a curve read on one machine still means
# something on another, and a super-linear path (F/R climbing above 1) shows as
# shape rather than as a number nobody can calibrate. This is a trend instrument
# run by hand, never a CI gate: docs/perf-trend-benchmark.md is the reading guide.
# --big adds the largest, slow step of each ladder (see below).
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
# The rates column is the only part of this script that costs anything: it runs
# the exchange job once per tier, five runs each, which is roughly 555 calls
# against the configured provider. A free tier is 100 a month, so a single
# unguarded run spends half a year of it — and the account, not the key, is what
# carries the counter, so rotating afterwards does not undo it.
#
# Off unless asked for. The old behaviour was to measure whenever the provider
# happened to answer, which made an expensive run the reward for a working key.
RATES_ENABLED=0

# The default run is household-sized and quick: three geometric x10 points are
# enough to show a curve's shape. --big adds the largest step of each ladder —
# the 100 000-row list and the notification cron over a million active
# subscriptions (100 users x 10 000) — which is the >128 MB blow-up worth
# looking at once in a while, not on every run. Slow and memory-hungry, so it is
# opt-in. See docs/perf-trend-benchmark.md for how to read what it prints.
BIG=0

while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE=$2; shift 2 ;;
        --user) USERNAME=$2; shift 2 ;;
        --big) BIG=1; shift ;;
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
        --rates) RATES_ENABLED=1; shift ;;
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

# The notification cron's load phase, in-process, as "<ms>\t<peak-bytes>" or a
# single word. It stops before the send loop, so it opens no SMTP session and
# calls no provider — network-free by construction, the structural version of
# the guard the rates column gets from --rates. Peak memory is meaningful here
# and nowhere else in this script: the read pages are timed over HTTP, in the
# web server's process, out of this script's reach — so they stay time-only, and
# only this in-process job reports what it took from the OS. That asymmetry is
# honest and deliberate.
measure_cron_load() {
    $EXEC $BENCH measure-cron-load "$RUNS" "$CRON_TIMEOUT"
}

# The whole detector: the normalized growth factor F/R = (a figure's x-growth)
# divided by (the size's x-growth) between two adjacent points. A number, not a
# threshold, is what survives the environment: the 4x spread between loopback
# and an overlay network and the peak drift across PHP build, opcache and
# allocator are all one multiplicative constant k, and dividing two adjacent
# figures cancels k. An absolute limit trips over k; a factor reads through it.
#
# For an inherently O(N) path — a list that draws N rows, a load that holds N
# rows — F/R ~ 1 is the honest, healthy reading. F/R climbing clearly above 1
# from one step to the next is the shape of a super-linear path, the thing to go
# and look at. F/R alone misses a fat constant that stays linear (formatPrice
# was O(rows x currencies), but currencies is fixed per request, so it read as
# linear-with-a-steep-slope, not bent): watch the absolute size for that.
# docs/perf-trend-benchmark.md is the full reading guide.

# Two tables from "size list stats calendar" rows on stdin: the absolute
# medians, then F/R for each step. Non-numeric cells (a word instead of a
# figure) are passed through and never divided.
read_tables() {
    awk '
    { size[NR] = $1; a[NR] = $2; b[NR] = $3; c[NR] = $4; rows = NR }
    function ms(v)     { return (v ~ /^[0-9]+$/) ? v "ms" : v }
    function fr(p,x,r) { return (p ~ /^[0-9]+$/ && x ~ /^[0-9]+$/ && p + 0 > 0) ? sprintf("%.2f", (x / p) / r) : "-" }
    END {
        printf "  %-16s %9s %9s %9s\n", "entries", "list", "stats", "calendar"
        for (i = 1; i <= rows; i++)
            printf "  %-16s %9s %9s %9s\n", size[i], ms(a[i]), ms(b[i]), ms(c[i])
        if (rows < 2) exit
        printf "\n  normalized growth factor  F/R = (figure x) / (size x)\n"
        printf "  %-16s %6s %9s %9s %9s\n", "step", "size x", "list", "stats", "calendar"
        for (i = 2; i <= rows; i++) {
            r = size[i] / size[i - 1]
            printf "  %-16s %6.1f %9s %9s %9s\n", size[i - 1] "->" size[i], r, \
                fr(a[i - 1], a[i], r), fr(b[i - 1], b[i], r), fr(c[i - 1], c[i], r)
        }
    }'
}

# The same for the cron, from "users load-ms peak-bytes rates" rows: absolute
# medians with peak memory in human units, then F/R for load time and peak. The
# rates column rides along in the absolute table but not the factor table — it
# measures a different job (the currency cron) and only when --rates is given.
cron_tables() {
    awk '
    { u[NR] = $1; t[NR] = $2; p[NR] = $3; ra[NR] = $4; rows = NR }
    function ms(v)     { return (v ~ /^[0-9]+$/) ? v "ms" : v }
    function hb(x)     {
        if (x !~ /^[0-9]+$/) return x
        if (x + 0 >= 1073741824) return sprintf("%.2f GiB", x / 1073741824)
        if (x + 0 >= 1048576)    return sprintf("%.1f MiB", x / 1048576)
        if (x + 0 >= 1024)       return sprintf("%.0f KiB", x / 1024)
        return x " B"
    }
    function fr(o,c,r) { return (o ~ /^[0-9]+$/ && c ~ /^[0-9]+$/ && o + 0 > 0) ? sprintf("%.2f", (c / o) / r) : "-" }
    END {
        printf "  %-10s %10s %12s %11s\n", "users", "load", "peak", "rates"
        for (i = 1; i <= rows; i++)
            printf "  %-10s %10s %12s %11s\n", u[i], ms(t[i]), hb(p[i]), ms(ra[i])
        if (rows < 2) exit
        printf "\n  normalized growth factor  F/R = (figure x) / (users x)\n"
        printf "  %-16s %6s %10s %10s\n", "step", "users x", "load", "peak"
        for (i = 2; i <= rows; i++) {
            r = u[i] / u[i - 1]
            printf "  %-16s %6.1f %10s %10s\n", u[i - 1] "->" u[i], r, \
                fr(t[i - 1], t[i], r), fr(p[i - 1], p[i], r)
        }
    }'
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

# A clean geometric x10 ladder: three points show a shape where two show only a
# line, and ten would be gold-plating. --big adds the fourth, slow point.
LIST_SIZES='100 1000 10000'
[ "$BIG" = "1" ] && LIST_SIZES='100 1000 10000 100000'

printf 'Subscription list and statistics, one user — rendered over HTTP (time, median of %d runs)\n' "$RUNS"

# Collected in the main shell rather than inside the pipe, so the issue-#91
# refusal — the seed wrote fewer rows than asked for — still exits the whole run
# and not merely the left half of a pipeline.
LIST_ROWS=''
for count in $LIST_SIZES; do
    written=$($EXEC $BENCH subscriptions "$USERNAME" "$count")
    if [ "$written" != "$count" ]; then
        printf '\nasked for %s subscriptions, the database holds %s\n' "$count" "$written" >&2
        exit 1
    fi
    row="$count $(measure_page subscriptions.php) $(measure_page stats.php) $(measure_page calendar.php)"
    LIST_ROWS=$(printf '%s\n%s' "$LIST_ROWS" "$row")
done
printf '%s\n' "$LIST_ROWS" | grep -v '^$' | read_tables

# Whether the rates column can measure anything is decided once, before any
# tier runs, and the answer is printed with the table rather than left to be
# inferred from a suspiciously round number.
if [ "$RATES_ENABLED" = "1" ]; then
    RATES=$($EXEC $BENCH rates-preflight "$RATES_TIMEOUT")
    RATES_VERDICT=$(printf '%s' "$RATES" | cut -f1)
    RATES_NOTE=$(printf '%s' "$RATES" | cut -f2)
else
    # The preflight is itself a request, so asking whether we could measure
    # already costs one of the hundred.
    RATES_VERDICT=not-requested
    RATES_NOTE="pass --rates to measure it; expect about 555 provider calls"
fi

# Account count is the axis that grows past a household — the cron runs over
# every user — so it is the one measured here. Subscriptions per account stay
# constant across the tiers, so peak memory grows with the account count alone
# and its factor reads cleanly; --big lifts that constant until the total
# crosses the million active rows that need more than the default 128 MB.
CRON_USERS='1 10 100'
CRON_SUBS=50
[ "$BIG" = "1" ] && CRON_SUBS=10000

# The interpreter's own start-up, measured once. The load figures below are the
# load phase alone, timed inside the process, so this is not in them; a real
# cron run pays both. Kept as the reference the notify column has always had.
BASELINE_MS=$(measure_cron dev/noop.php)

printf '\nNotification cron, all users — load/build phase, in-process (median of %d runs)\n' "$RUNS"

CRON_ROWS=''
for users in $CRON_USERS; do
    seed "$users" "$CRON_SUBS"
    $EXEC $BENCH notifications >/dev/null

    load=$(measure_cron_load)
    load_ms=$(printf '%s' "$load" | cut -f1)
    load_peak=$(printf '%s' "$load" | cut -f2)

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

    row="$users $load_ms $load_peak $rates"
    CRON_ROWS=$(printf '%s\n%s' "$CRON_ROWS" "$row")
done
printf '%s\n' "$CRON_ROWS" | grep -v '^$' | cron_tables

printf '\n  load is the bulk-load block of sendnotifications.php up to the send loop:\n'
printf '  it opens no SMTP session and calls no provider, so it measures the job and\n'
printf '  not the mail network — the structural form of the guard --rates gives the\n'
printf '  currency column. Interpreter start-up adds %s to each real run, on top of load.\n' "$(cell "$BASELINE_MS")"
printf '  peak is memory_get_peak_usage(true) inside that process; the HTTP-timed pages\n'
printf '  above run in the web server, out of reach, so only this in-process job reports it.\n'

if [ "$RATES_VERDICT" = "ok" ]; then
    printf '  rates measured against a live provider — this run spent roughly 555 calls.\n'
else
    printf '  rates not measured (%s): %s\n' "$RATES_VERDICT" "$RATES_NOTE"
    printf '  A figure taken from a provider that refuses or never answers is the failure\n'
    printf '  path and the network, not the job.\n'
fi

printf '\nRemoving seeded data\n'
$EXEC $BENCH cleanup

printf '\n'
