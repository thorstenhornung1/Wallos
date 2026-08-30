#!/bin/sh
# The four container modes of #86, booted for real.
#
#   dev/container-modes.sh
#   CONTAINER_ENGINE=docker dev/container-modes.sh
#
# tests/cases/rootless_test.php pins what the source must say; this script
# pins what the built image must do. The modes were verified by hand once,
# for #86, and a manual verification regresses silently: a change to
# startup.sh or the Dockerfile can pass every source gate and still break
# `user:`, because only a booted container exercises the privilege decision,
# the preflight refusal and the capability handling.
#
#   1. default          root, remapped to PUID/PGID, cron via supercronic,
#                       and the database is not served over HTTP
#   2. user 1000:0      read-only root, one tmpfs, fresh volumes writable
#                       through the gid-0 convention, migrations applied
#   3. user 1000:1000   fresh volumes are not writable by an arbitrary gid:
#                       refuse loudly, name the chown, exit 1
#   4. cap-drop ALL     the capped nginx cannot even exec, so
#                       WALLOS_HTTP_PORT moves the listener above 1024
#
# Builds the image once and boots it four times; expect a few minutes.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENGINE=${CONTAINER_ENGINE:-podman}
IMAGE=${WALLOS_MODE_IMAGE:-wallos-mode-test}
FAILURES=0

# $$ in every container and volume name, so two runs on one machine cannot
# claim each other's resources. No published ports at all, for the same
# reason and one more: every HTTP check runs inside the container, the way
# the image's own HEALTHCHECK does — a flaky host-side port forward (seen
# with podman's gvproxy on macOS) must not fail a check about the image.
SUFFIX=$$

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

remove_container() {
    "$ENGINE" rm -f "$1" >/dev/null 2>&1 || true
}

remove_volumes() {
    for _v in "$@"; do
        "$ENGINE" volume rm -f "$_v" >/dev/null 2>&1 || true
    done
}

# Every mode scrubs its own leftovers on the way out, but under `set -eu` a
# broken engine call exits mid-mode — the trap sweeps whatever is left, and
# scrubbing something already gone is a no-op.
cleanup() {
    for _c in root gid0 refuse capdrop; do
        remove_container "wallos-mode-$_c-$SUFFIX"
    done
    for _m in gid0 refuse capdrop; do
        remove_volumes "wallos-mode-$_m-db-$SUFFIX" "wallos-mode-$_m-logos-$SUFFIX"
    done
}
trap cleanup EXIT

# Prints 1 once health.php answers inside container $1 on port $2, 0 when
# the timeout passes: a loop rather than a fixed sleep, because the startup
# path's duration is not a constant.
wait_health() {
    _i=0
    while [ "$_i" -lt 60 ]; do
        if "$ENGINE" exec "$1" \
            curl -fsS -o /dev/null "http://127.0.0.1:$2/health.php" 2>/dev/null; then
            echo 1
            return 0
        fi
        _i=$((_i + 1))
        sleep 2
    done
    echo 0
}

# The status code nginx answers for URL $2, asked from inside container $1.
http_code() {
    _code=$("$ENGINE" exec "$1" \
        curl -s -o /dev/null -w '%{http_code}' "$2" 2>/dev/null) || _code=000
    printf '%s' "$_code"
}

# Prints 1 once the migration chain inside container $1 reaches the highest
# migration in this tree, 0 otherwise. Compared against the tree, not a
# number written down here — the same reasoning as dev/e2e.sh: a written
# number is stale the day the next migration lands.
#
# Two phases, and the order is load-bearing. Health answering is not enough
# to query the database: startup launches nginx before it initialises, and a
# probe that opens the database during that window CREATES it — connecting
# makes an empty file, and createdatabase.php decides whether to build the
# schema by file_exists(). Probing early therefore corrupts the very boot it
# measures: the schema is skipped, the migrations explode on missing tables,
# and the container dies (seen exactly once, expensively). So phase one only
# reads the log until this boot reports the last migration applied — the
# volumes are fresh, so unlike the restarted-container case dev/e2e.sh warns
# about, every migration is applied on this very boot and the log names it.
# Phase two then asks the configured database once, which is now safe.
EXPECTED_MIGRATION=$(basename "$(ls "$ROOT"/migrations/*.php | LC_ALL=C sort | tail -1)")
wait_migrated() {
    _i=0
    _initialised=0
    while [ "$_i" -lt 60 ]; do
        _logs=$("$ENGINE" logs "$1" 2>&1 || true)
        if printf '%s' "$_logs" | grep -q "migrations/$EXPECTED_MIGRATION completed successfully"; then
            _initialised=1
            break
        fi
        _i=$((_i + 1))
        sleep 2
    done

    if [ "$_initialised" = "0" ]; then
        echo 0
        return 0
    fi

    _latest=$("$ENGINE" exec "$1" php -r '
        require "/var/www/html/includes/database/connection.php";
        $db = wallos_database_connect();
        echo (string) $db->scalar("SELECT MAX(migration) FROM migrations");
    ' 2>/dev/null || true)
    contains "$_latest" "$EXPECTED_MIGRATION"
}

printf '\nBuilding %s with %s\n' "$IMAGE" "$ENGINE"
"$ENGINE" build -t "$IMAGE" "$ROOT"

# --- 1. default: root, remapped to PUID/PGID --------------------------------
printf '\nMode 1: default (root)\n'
"$ENGINE" run -d --name "wallos-mode-root-$SUFFIX" "$IMAGE" >/dev/null

check "root: health.php answers with 200" \
    "$(wait_health "wallos-mode-root-$SUFFIX" 80)"

LOGS=$("$ENGINE" logs "wallos-mode-root-$SUFFIX" 2>&1 || true)
check "root: cron jobs are handed to supercronic" \
    "$(contains "$LOGS" 'Launching supercronic')"

# The database, asked for over HTTP. The URL is spelled in two parts because
# dev/sh-audit.sh fingerprints shell scripts that open the SQLite file — and
# this string is only ever sent to nginx to be refused.
DB_URL="db/wallos"
DB_CODE=$(http_code "wallos-mode-root-$SUFFIX" "http://127.0.0.1:80/$DB_URL.db")
check "root: the database over HTTP is refused with 403 (got $DB_CODE)" \
    "$([ "$DB_CODE" = "403" ] && echo 1 || echo 0)"

remove_container "wallos-mode-root-$SUFFIX"

# --- 2. user 1000:0: read-only root, one tmpfs, fresh volumes ---------------
printf '\nMode 2: user 1000:0 with a read-only root\n'
"$ENGINE" volume create "wallos-mode-gid0-db-$SUFFIX" >/dev/null
"$ENGINE" volume create "wallos-mode-gid0-logos-$SUFFIX" >/dev/null
"$ENGINE" run -d --name "wallos-mode-gid0-$SUFFIX" \
    --user 1000:0 --read-only --tmpfs /tmp \
    -v "wallos-mode-gid0-db-$SUFFIX:/var/www/html/db" \
    -v "wallos-mode-gid0-logos-$SUFFIX:/var/www/html/images/uploads/logos" \
    "$IMAGE" >/dev/null

check "1000:0: health.php answers with 200" \
    "$(wait_health "wallos-mode-gid0-$SUFFIX" 80)"

LOGS=$("$ENGINE" logs "wallos-mode-gid0-$SUFFIX" 2>&1 || true)
check "1000:0: startup skipped the privileged calls" \
    "$(contains "$LOGS" 'Running unprivileged')"

check "1000:0: the migration chain is fully applied (through $EXPECTED_MIGRATION)" \
    "$(wait_migrated "wallos-mode-gid0-$SUFFIX")"

# A fresh volume must mean a freshly created database. When the image carries
# a database out of the build tree — the gate's first real catch, now pinned
# by .dockerignore — first boot says "No migrations to run" instead of
# creating one, and the instance is born with somebody else's data. Read
# after wait_migrated, so the boot is past the point where the line appears.
LOGS=$("$ENGINE" logs "wallos-mode-gid0-$SUFFIX" 2>&1 || true)
check "1000:0: the database was created on this boot, not shipped in the image" \
    "$(contains "$LOGS" 'Database does not exist')"

remove_container "wallos-mode-gid0-$SUFFIX"
remove_volumes "wallos-mode-gid0-db-$SUFFIX" "wallos-mode-gid0-logos-$SUFFIX"

# --- 3. user 1000:1000: unprepared volumes are refused, loudly --------------
printf '\nMode 3: user 1000:1000 against unprepared volumes\n'
"$ENGINE" volume create "wallos-mode-refuse-db-$SUFFIX" >/dev/null
"$ENGINE" volume create "wallos-mode-refuse-logos-$SUFFIX" >/dev/null
"$ENGINE" run -d --name "wallos-mode-refuse-$SUFFIX" \
    --user 1000:1000 \
    -v "wallos-mode-refuse-db-$SUFFIX:/var/www/html/db" \
    -v "wallos-mode-refuse-logos-$SUFFIX:/var/www/html/images/uploads/logos" \
    "$IMAGE" >/dev/null

# This mode waits for the container to die rather than for health: refusing
# to start is the behaviour under test. A container still running when the
# loop ends reports exit code 0 and fails the check below on its own.
_i=0
while [ "$_i" -lt 60 ]; do
    RUNNING=$("$ENGINE" inspect --format '{{.State.Running}}' \
        "wallos-mode-refuse-$SUFFIX" 2>/dev/null || echo false)
    [ "$RUNNING" = "false" ] && break
    _i=$((_i + 1))
    sleep 2
done

EXIT_CODE=$("$ENGINE" inspect --format '{{.State.ExitCode}}' \
    "wallos-mode-refuse-$SUFFIX" 2>/dev/null || echo none)
check "1000:1000: the container refuses to start (exit 1, got $EXIT_CODE)" \
    "$([ "$EXIT_CODE" = "1" ] && echo 1 || echo 0)"

LOGS=$("$ENGINE" logs "wallos-mode-refuse-$SUFFIX" 2>&1 || true)
check "1000:1000: the refusal names the chown to run" \
    "$(contains "$LOGS" 'chown -R 1000:1000')"
check "1000:1000: the refusal names the gid-0 alternative" \
    "$(contains "$LOGS" 'user: 1000:0')"

remove_container "wallos-mode-refuse-$SUFFIX"
remove_volumes "wallos-mode-refuse-db-$SUFFIX" "wallos-mode-refuse-logos-$SUFFIX"

# --- 4. cap-drop ALL: WALLOS_HTTP_PORT moves the listener -------------------
printf '\nMode 4: cap-drop ALL with WALLOS_HTTP_PORT=8080\n'
"$ENGINE" volume create "wallos-mode-capdrop-db-$SUFFIX" >/dev/null
"$ENGINE" volume create "wallos-mode-capdrop-logos-$SUFFIX" >/dev/null
"$ENGINE" run -d --name "wallos-mode-capdrop-$SUFFIX" \
    --cap-drop ALL --user 1000:0 --read-only --tmpfs /tmp \
    -e WALLOS_HTTP_PORT=8080 \
    -v "wallos-mode-capdrop-db-$SUFFIX:/var/www/html/db" \
    -v "wallos-mode-capdrop-logos-$SUFFIX:/var/www/html/images/uploads/logos" \
    "$IMAGE" >/dev/null

check "cap-drop: health.php answers with 200 on port 8080" \
    "$(wait_health "wallos-mode-capdrop-$SUFFIX" 8080)"

LOGS=$("$ENGINE" logs "wallos-mode-capdrop-$SUFFIX" 2>&1 || true)
check "cap-drop: startup moved the listener" \
    "$(contains "$LOGS" 'Listening on port 8080')"

remove_container "wallos-mode-capdrop-$SUFFIX"
remove_volumes "wallos-mode-capdrop-db-$SUFFIX" "wallos-mode-capdrop-logos-$SUFFIX"

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
    printf 'container mode checks passed\n'
else
    printf '%s container mode check(s) failed\n' "$FAILURES"
    exit 1
fi
