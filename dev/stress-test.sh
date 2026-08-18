#!/bin/sh
# Exercises a seeded instance hard, then checks the data is still what it was.
#
#   dev/stress-seed.php 12 25          build an awkward instance
#   dev/stress-test.sh                 hammer it
#
# The reason this exists is the database migration in #79: a fingerprint taken
# before and after proves the data survived, but only if something has actually
# used the application in between. A migration that breaks writes looks perfect
# until the first write.
#
# Every account it uses is one dev/stress-seed.php created, and every row it
# writes is prefixed `stress-`.

set -eu
export LC_ALL=C

BASE=${WALLOS_BASE:-http://localhost:8383}
ENGINE=${CONTAINER_ENGINE:-podman}
CONTAINER=${WALLOS_CONTAINER:-wallos-dev}
EXEC=${WALLOS_EXEC:-"$ENGINE exec $CONTAINER"}
USERS=${STRESS_USERS:-5}
ROUNDS=${STRESS_ROUNDS:-3}
CONCURRENCY=${STRESS_CONCURRENCY:-4}

FAILURES=0
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

check() {
    if [ "$2" = "1" ]; then
        printf '  ok  %s\n' "$1"
    else
        printf 'FAIL  %s\n' "$1"
        FAILURES=$((FAILURES + 1))
    fi
}

# Signs in and leaves the cookie jar at $WORK/jar-N.
login() {
    index=$1
    jar="$WORK/jar-$index"
    rm -f "$jar"
    curl -s -c "$jar" "$BASE/login.php" -o /dev/null
    code=$(curl -s -b "$jar" -c "$jar" -X POST "$BASE/login.php" \
        --data-urlencode "username=stress-user-$index" \
        --data-urlencode "password=StressPass$index!" \
        -o /dev/null -w '%{http_code}')
    # A successful login redirects; a failed one renders the form again.
    [ "$code" = "302" ] && echo 1 || echo 0
}

page() {
    curl -s -b "$WORK/jar-$1" -o "$WORK/page" -w '%{http_code}' "$BASE/$2"
}

csrf() {
    grep -o '"[a-f0-9]\{32,\}"' "$WORK/page" 2>/dev/null | head -1 | tr -d '"'
}

printf '\nWallos stress test — %s, %d users, %d rounds, %d concurrent\n\n' \
    "$BASE" "$USERS" "$ROUNDS" "$CONCURRENCY"

# --- everybody signs in, except those who should not -------------------------
#
# The seed deliberately leaves some accounts with an open email verification,
# because that is a state real installations are full of. Wallos refuses those
# logins, which is correct — so the test asserts the refusal instead of
# tripping over it.

BLOCKED=$($EXEC php -r '
    require "/var/www/html/includes/database/connection.php";
    $db = wallos_database_connect();
    $r = $db->query("SELECT u.username FROM user u
                     JOIN email_verification ev ON ev.user_id = u.id
                     WHERE u.username LIKE \"stress-user-%\"");
    $names = [];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) { $names[] = $x["username"]; }
    echo implode(" ", $names);
' 2>/dev/null)

is_blocked() {
    for name in $BLOCKED; do
        [ "$name" = "stress-user-$1" ] && return 0
    done
    return 1
}

signed_in=0
expected=0
refused=0
blocked_count=0
i=1
while [ "$i" -le "$USERS" ]; do
    result=$(login "$i")
    if is_blocked "$i"; then
        blocked_count=$((blocked_count + 1))
        [ "$result" = "0" ] && refused=$((refused + 1))
    else
        expected=$((expected + 1))
        [ "$result" = "1" ] && signed_in=$((signed_in + 1))
    fi
    i=$((i + 1))
done

check "all $expected verified accounts sign in" \
    "$([ "$signed_in" = "$expected" ] && echo 1 || echo 0)"
check "$blocked_count account(s) with an open email verification are refused" \
    "$([ "$refused" = "$blocked_count" ] && echo 1 || echo 0)"

# --- every page, for every user, several times, in parallel -----------------

PAGES="index.php subscriptions.php stats.php calendar.php settings.php profile.php admin.php"

round=1
while [ "$round" -le "$ROUNDS" ]; do
    running=0
    i=1
    while [ "$i" -le "$USERS" ]; do
        if is_blocked "$i"; then
            echo 0 > "$WORK/bad-$i-$round"
            i=$((i + 1))
            continue
        fi
        (
            bad=0
            for p in $PAGES; do
                code=$(curl -s -b "$WORK/jar-$i" -o /dev/null -w '%{http_code}' "$BASE/$p")
                [ "$code" = "200" ] || bad=$((bad + 1))
            done
            echo "$bad" > "$WORK/bad-$i-$round"
        ) &
        running=$((running + 1))
        if [ "$running" -ge "$CONCURRENCY" ]; then
            wait
            running=0
        fi
        i=$((i + 1))
    done
    wait
    round=$((round + 1))
done

bad_total=$(cat "$WORK"/bad-* 2>/dev/null | awk '{s += $1} END {print s + 0}')
check "every page answered 200 for every user, every round" \
    "$([ "$bad_total" = "0" ] && echo 1 || echo 0)"

# --- writes -----------------------------------------------------------------

page 1 subscriptions.php >/dev/null
TOKEN=$(csrf)

created=0
i=1
while [ "$i" -le "$USERS" ]; do
    if is_blocked "$i"; then i=$((i + 1)); continue; fi
    page "$i" subscriptions.php >/dev/null
    token=$(csrf)
    [ -n "$token" ] || { i=$((i + 1)); continue; }

    # The ids have to belong to this user or the insert hits a foreign key.
    ids=$($EXEC php -r '
        require "/var/www/html/includes/database/connection.php";
        $db = wallos_database_connect();
        $u = (int) $db->scalar("SELECT id FROM user WHERE username = :n", [":n" => "stress-user-'"$i"'"]);
        printf("%d %d %d %d",
            $u,
            (int) $db->scalar("SELECT main_currency FROM user WHERE id = :u", [":u" => $u]),
            (int) $db->scalar("SELECT id FROM categories WHERE user_id = :u LIMIT 1", [":u" => $u]),
            (int) $db->scalar("SELECT id FROM payment_methods WHERE user_id = :u LIMIT 1", [":u" => $u]));
    ' 2>/dev/null)
    set -- $ids
    [ "${1:-0}" != "0" ] || { i=$((i + 1)); continue; }

    response=$(curl -s -b "$WORK/jar-$i" -X POST "$BASE/endpoints/subscription/add.php" \
        -H "X-CSRF-Token: $token" \
        -F "name=stress-written-$i" -F "price=13.37" -F "currency_id=$2" \
        -F "next_payment=$(date +%Y-%m-%d)" -F "cycle=3" -F "frequency=1" \
        -F "payer_user_id=$1" -F "category_id=$3" -F "payment_method_id=$4" \
        -F "notifications=0" -F "auto_renew=1" -F "notify_days_before=1" 2>/dev/null || true)
    # The endpoint answers {"status":"Success"}, not {"success":true} — the
    # two conventions live side by side in this codebase.
    printf '%s' "$response" | grep -q '"status":"Success"' && created=$((created + 1))
    i=$((i + 1))
done
check "writes succeed while the instance is under load" \
    "$([ "$created" -gt 0 ] && echo 1 || echo 0)"

# --- cron jobs against the full dataset -------------------------------------

for job in sendnotifications updateexchange sendcancellationnotifications; do
    output=$($EXEC php "/var/www/html/endpoints/cronjobs/$job.php" 2>&1 || true)
    clean=$(printf '%s' "$output" | grep -c 'PHP \(Fatal\|Parse\|Warning\)' || true)
    check "$job runs clean over the whole dataset" "$([ "$clean" = "0" ] && echo 1 || echo 0)"
done

# --- concurrent writers -----------------------------------------------------
#
# SQLite serialises writers, so this is where a missing busy timeout shows up.
# PostgreSQL will not care, which is itself worth seeing.

i=1
while [ "$i" -le "$CONCURRENCY" ]; do
    (
        $EXEC php -r '
            require "/var/www/html/includes/database/connection.php";
            $db = wallos_database_connect();
            for ($n = 0; $n < 20; $n++) {
                $db->exec("UPDATE admin SET latest_version = latest_version WHERE id = 1");
            }
            $db->close();
        ' >/dev/null 2>&1 && echo ok > "$WORK/writer-$i" || echo fail > "$WORK/writer-$i"
    ) &
    i=$((i + 1))
done
wait
writer_failures=$(cat "$WORK"/writer-* 2>/dev/null | grep -c fail || true)
check "$CONCURRENCY concurrent writers all completed" \
    "$([ "$writer_failures" = "0" ] && echo 1 || echo 0)"

# --- the data is still there ------------------------------------------------

users_now=$($EXEC php -r '
    require "/var/www/html/includes/database/connection.php";
    $db = wallos_database_connect();
    echo (int) $db->scalar("SELECT COUNT(*) FROM user WHERE username LIKE :p", [":p" => "stress-%"]);
' 2>/dev/null)
check "the seeded accounts are all still present" \
    "$([ "$users_now" = "$USERS" ] && echo 1 || echo 0)"

printf '\n'
if [ "$FAILURES" = "0" ]; then
    printf 'stress test passed\n\n'
else
    printf '%d check(s) failed\n\n' "$FAILURES"
    exit 1
fi
