#!/bin/sh
# Repeatable performance measurement against a running Wallos.
#
#   dev/benchmark.sh                                  local dev environment
#   dev/benchmark.sh --base https://test.example.de \
#                    --user admin --password '…' \
#                    --exec 'docker exec wallos-test_wallos.1.abc'
#
# Two axes, because they stress different things:
#
#   list size   one user with N subscriptions — page rendering, rate conversion,
#               the subscription index
#   user count  M users with notifications — the cron job's per-user work
#
# Every figure is the median of five runs. Seeded rows are prefixed "seed-" and
# removed at the end; real accounts are never touched.

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

while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE=$2; shift 2 ;;
        --user) USERNAME=$2; shift 2 ;;
        --password) PASSWORD=$2; shift 2 ;;
        --exec) EXEC=$2; shift 2 ;;
        --runs) RUNS=$2; shift 2 ;;
        *) printf 'unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
done

[ -n "$EXEC" ] || EXEC="$ENGINE exec $CONTAINER"

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

# Median of RUNS timings, in milliseconds.
measure_page() {
    path=$1
    i=0
    while [ "$i" -lt "$RUNS" ]; do
        curl -s -b "$COOKIES" -o /dev/null -w '%{time_total}\n' "$BASE/$path"
        i=$((i + 1))
    done | sort -n | awk -v n="$RUNS" 'NR == int((n + 1) / 2) { printf "%.0f", $1 * 1000 }'
}

enable_notifications() {
    $EXEC php -r '
        $db = new SQLite3("/var/www/html/db/wallos.db");
        $db->exec("UPDATE subscriptions SET notify = 1, next_payment = date(\"now\", \"+2 days\")");
        $db->exec("UPDATE notifications SET enabled = 1 WHERE provider = \"email\"");
    ' >/dev/null 2>&1 || true
}

measure_cron() {
    job=$1
    # Timed from inside a PHP process rather than with `date +%s%N`, which is not
    # portable: BusyBox date — what the Alpine-based image ships — ignores %N and
    # returns whole seconds, so two readings inside the same second differ by
    # zero. That produced a table of 0ms figures, baseline included, which reads
    # like "too fast to measure" rather than "not measured".
    $EXEC php -r '
        $runs = (int) "'"$RUNS"'";
        $script = "/var/www/html/'"$1"'";
        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            $start = microtime(true);
            exec("php " . escapeshellarg($script) . " > /dev/null 2>&1");
            $times[] = (microtime(true) - $start) * 1000;
        }
        sort($times);
        printf("%d", (int) round($times[intdiv(count($times), 2)]));
    ' 2>/dev/null
}

# Moves the logged-in user's own subscriptions to a given count, so the measured
# page is one account's list rather than the sum of everybody's.
set_own_subscriptions() {
    $EXEC php -r '
        $db = new SQLite3("/var/www/html/db/wallos.db");
        $userId = (int) $db->querySingle("SELECT id FROM user WHERE username = \"'"$USERNAME"'\"");
        $target = (int) "'"$1"'";
        $db->exec("DELETE FROM subscriptions WHERE name LIKE \"bench-%\" AND user_id = $userId");
        $currency = (int) $db->querySingle("SELECT id FROM currencies WHERE user_id = $userId LIMIT 1");
        $category = (int) $db->querySingle("SELECT id FROM categories WHERE user_id = $userId LIMIT 1");
        $db->exec("BEGIN");
        $stmt = $db->prepare("INSERT INTO subscriptions (name, price, currency_id, next_payment, cycle, frequency, payer_user_id, category_id, notify, inactive, user_id, auto_renew) VALUES (:n, 9.99, :c, :d, 3, 1, :u, :cat, 0, 0, :u, 1)");
        for ($i = 0; $i < $target; $i++) {
            $stmt->bindValue(":n", "bench-" . $i, SQLITE3_TEXT);
            $stmt->bindValue(":c", $currency, SQLITE3_INTEGER);
            $stmt->bindValue(":d", date("Y-m-d", strtotime("+" . ($i % 40) . " days")), SQLITE3_TEXT);
            $stmt->bindValue(":u", $userId, SQLITE3_INTEGER);
            $stmt->bindValue(":cat", $category, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
        }
        $db->exec("COMMIT");
    ' >/dev/null
}

cleanup_bench() {
    $EXEC php -r '
        $db = new SQLite3("/var/www/html/db/wallos.db");
        $db->exec("DELETE FROM subscriptions WHERE name LIKE \"bench-%\"");
        $db->exec("DELETE FROM subscriptions WHERE name LIKE \"seed-%\"");
        $db->exec("DELETE FROM user WHERE username LIKE \"seed-%\"");
    ' >/dev/null
}

printf '\nWallos benchmark — %s, median of %d runs\n\n' "$BASE" "$RUNS"

login

printf 'Subscription list, one user\n'
printf '  %-12s %10s %10s %10s\n' 'entries' 'list' 'stats' 'calendar'
for count in 100 1000 5000; do
    set_own_subscriptions "$count"
    printf '  %-12s %9sms %9sms %9sms\n' "$count" \
        "$(measure_page subscriptions.php)" \
        "$(measure_page stats.php)" \
        "$(measure_page calendar.php)"
done

printf '\nNotification cron, all users\n'
printf '  %-12s %10s %10s\n' 'users' 'notify' 'rates'
printf '  %-12s %9sms %9s\n' 'baseline' "$(measure_cron dev/noop.php)" '-'
for users in 1 10 100; do
    seed "$users" 10
    enable_notifications
    printf '  %-12s %9sms %9sms\n' "$users" \
        "$(measure_cron endpoints/cronjobs/sendnotifications.php)" \
        "$(measure_cron endpoints/cronjobs/updateexchange.php)"
done
printf '\n  baseline is an empty script: interpreter start-up, included in every row above.\n' 

cleanup_bench

printf '\nSeeded data removed.\n\n'
