#!/bin/sh
# End-to-end smoke test against the running dev environment.
#
#   podman compose -f dev/compose.yaml up -d --build
#   dev/e2e.sh
#
# Verifies the parts a unit test cannot reach: the real startup path, the
# migration chain, page rendering, and mail actually leaving the application.

set -eu

BASE=${WALLOS_BASE:-http://localhost:8383}
MAILPIT=${MAILPIT_BASE:-http://localhost:8025}
ENGINE=${CONTAINER_ENGINE:-podman}
CONTAINER=${WALLOS_CONTAINER:-wallos-dev}
JAR=$(mktemp)
JAR2=$(mktemp)
FAILURES=0

check() {
    if [ "$2" = "1" ]; then
        printf '  ok  %s\n' "$1"
    else
        printf 'FAIL  %s\n' "$1"
        FAILURES=$((FAILURES + 1))
    fi
}

contains() {
    if printf '%s' "$1" | grep -q "$2"; then echo 1; else echo 0; fi
}

absent() {
    if printf '%s' "$1" | grep -q "$2"; then echo 0; else echo 1; fi
}

printf '\nWaiting for %s\n' "$BASE"
i=0
while [ "$i" -lt 60 ]; do
    if curl -fsS -o /dev/null "$BASE/health.php" 2>/dev/null; then break; fi
    i=$((i + 1))
    sleep 2
done

# --- startup ---------------------------------------------------------------
LOGS=$("$ENGINE" logs "$CONTAINER" 2>&1 || true)

# Asked of the database rather than the log: the log only mentions a migration
# on the run that applied it, so a restarted container would fail a log check
# while being perfectly up to date.
LATEST_MIGRATION=$("$ENGINE" exec "$CONTAINER" php -r '
    $db = new SQLite3("/var/www/html/db/wallos.db");
    echo (string) $db->querySingle("SELECT MAX(migration) FROM migrations");
' 2>/dev/null || true)

check "the migration chain is fully applied" \
    "$(contains "$LATEST_MIGRATION" '000062')"
check "startup produced no PHP errors" "$(absent "$LOGS" 'PHP \(Fatal\|Parse\|Warning\)')"

# --- account ---------------------------------------------------------------
curl -fsS -c "$JAR" "$BASE/registration.php" -o /dev/null
curl -fsS -b "$JAR" -c "$JAR" -X POST "$BASE/registration.php" \
    --data-urlencode "username=e2e" --data-urlencode "email=e2e@example.com" \
    --data-urlencode "password=E2ePass123!" --data-urlencode "confirm_password=E2ePass123!" \
    --data-urlencode "main_currency=1" --data-urlencode "language=en" -o /dev/null || true

rm -f "$JAR"; JAR=$(mktemp)
curl -fsS -c "$JAR" "$BASE/login.php" -o /dev/null
curl -fsS -b "$JAR" -c "$JAR" -X POST "$BASE/login.php" \
    --data-urlencode "username=e2e" --data-urlencode "password=E2ePass123!" -o /dev/null

SETTINGS=$(curl -fsS -b "$JAR" "$BASE/settings.php")
ADMIN=$(curl -fsS -b "$JAR" "$BASE/admin.php")
check "settings page renders" "$(contains "$SETTINGS" 'Use instance SMTP')"
check "admin page renders" "$(contains "$ADMIN" 'Instance Integrations')"

# The admin page above renders because of a role row, not because this account
# happens to be the first in the table. A second account must not get in.
ROLES=$("$ENGINE" exec "$CONTAINER" php -r '
    $db = new SQLite3("/var/www/html/db/wallos.db");
    $r = $db->query("SELECT user_id, role, source FROM user_roles");
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) { echo $x["user_id"], ":", $x["role"], ":", $x["source"], "\n"; }
' 2>/dev/null)
check "the first account holds a local admin role" "$(contains "$ROLES" '1:admin:local')"

curl -fsS -c "$JAR2" "$BASE/registration.php" -o /dev/null
curl -fsS -b "$JAR2" -c "$JAR2" -X POST "$BASE/registration.php" \
    --data-urlencode "username=e2e2" --data-urlencode "email=e2e2@example.com" \
    --data-urlencode "password=E2ePass123!" --data-urlencode "confirm_password=E2ePass123!" \
    --data-urlencode "main_currency=1" --data-urlencode "language=en" -o /dev/null || true
rm -f "$JAR2"; JAR2=$(mktemp)
curl -fsS -c "$JAR2" "$BASE/login.php" -o /dev/null
curl -fsS -b "$JAR2" -c "$JAR2" -X POST "$BASE/login.php" \
    --data-urlencode "username=e2e2" --data-urlencode "password=E2ePass123!" -o /dev/null
SECOND_ADMIN=$(curl -fsS -b "$JAR2" "$BASE/admin.php" || true)
check "a second account cannot reach the admin page" \
    "$(absent "$SECOND_ADMIN" 'Instance Integrations')"

# --- secrets stay server side ----------------------------------------------
for SECRET in instance-smtp-password instance-currency-key sk-instance-ai-key; do
    check "settings page hides $SECRET" "$(absent "$SETTINGS" "$SECRET")"
    check "admin page hides $SECRET" "$(absent "$ADMIN" "$SECRET")"
done
check "managed fields are marked read-only" "$(contains "$ADMIN" 'data-managed-by="WALLOS_SMTP_HOST"')"

# --- mail ------------------------------------------------------------------
CSRF=$(printf '%s' "$SETTINGS" | grep -o 'window.csrfToken = "[^"]*"' | sed 's/.*"\(.*\)"/\1/')
TEST_RESULT=$(curl -fsS -b "$JAR" -X POST "$BASE/endpoints/notifications/testemailnotifications.php" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -d '{"smtpmode":"instance"}')
check "instance SMTP delivers the test mail" "$(contains "$TEST_RESULT" '"success":true')"

INBOX=$(curl -fsS "$MAILPIT/api/v1/messages")
check "the mail arrived at mailpit" "$(contains "$INBOX" 'Wallos Instance')"

# --- cron ------------------------------------------------------------------
for JOB in sendnotifications sendcancellationnotifications updateexchange sendverificationemails sendresetpasswordemails; do
    OUTPUT=$("$ENGINE" exec "$CONTAINER" php "/var/www/html/endpoints/cronjobs/$JOB.php" 2>&1 || true)
    check "$JOB runs without PHP errors" "$(absent "$OUTPUT" 'Fatal error\|Parse error\|Warning:')"
done

rm -f "$JAR" "$JAR2"

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
    printf 'end-to-end checks passed\n'
else
    printf '%s end-to-end check(s) failed\n' "$FAILURES"
    exit 1
fi
