#!/bin/sh
# The upgrade path, rehearsed against a copy of a database that actually grew.
#
#   dev/shadow-migrate.sh /path/to/copy-of-production.db
#   WALLOS_SHADOW_SOURCE=/path/to/copy.db dev/shadow-migrate.sh
#
#   --source PATH     the same thing, spelled as an option
#   --pg-image REF    the throwaway PostgreSQL (default: postgres:14-alpine,
#                     the oldest version upstream still supports, which is
#                     where "this uses something newer than our floor" shows)
#   --engine NAME     container engine (default: $CONTAINER_ENGINE, else podman)
#   --help
#
# Exit codes: 0 every step passed, 1 a step failed, 2 usage or environment.
#
# Why this exists
#
# Three documents record the upgrade path as untested — docs/test-instance.md,
# docs/test-results-2026-08-20-nightrun.md and docs/test-results-2026-08-21.md —
# and all three give the same reason. A fresh installation records every
# migration as applied in the moment it creates the schema, and a fresh
# PostgreSQL installation installs a generated baseline that records them
# without running them at all. So no CI run, on either backend, has ever said
# anything about what the migration chain does to a schema that grew.
#
# That gap is not theoretical. Migration 000016 splits the notifications table
# and drops it; the drop ran while its own read of that table was still open,
# SQLite refused, the result was not checked, and the migration recorded itself
# as applied on every installation ever made — with the table still standing. It
# took 000065, nine years of releases later, to remove it. Every test that has
# ever run started from a database where 000016 had "already been applied".
#
# A copy of a real database is the only input that carries that history. This
# script takes one and walks it the whole way: the fork's migration chain, then
# dev/migrate-to-pgsql.php into a PostgreSQL instance it starts and removes
# itself, then a row count taken on both sides and compared, then a boot.
#
# What it never does
#
# It never touches the file it is given. The first thing it does is copy that
# file into a scratch directory of its own; every step after that works on the
# copy, and the last step reads the original's checksum again and fails if it
# changed. Give it a copy anyway — a backup, or dev/snapshot.sh output — because
# a database that is being written to cannot be copied with cp at all.
#
# Nothing here is a warning
#
# A migration that reports failure, a table that arrives with fewer rows than it
# left with, a container that does not answer on health.php: each one ends the
# run with a non-zero status. This is built to run nightly and unattended, and a
# nightly job that reports a problem as a hint is a nightly job nobody reads —
# which is the shape of the defect it was built to find.
#
# --skip-orphans is deliberately not passed through to dev/migrate-to-pgsql.php.
# A grown SQLite database accumulates rows that violate foreign keys PostgreSQL
# enforces and SQLite never has, and skipping them is data a real migration
# would leave behind. Here that has to go red and name the constraint, because
# somebody has to decide about those rows before the migration rather than read
# about them afterwards. dev/snapshot.sh --rehearse is the exploratory tool for
# the same question, and it does pass the flag through.
#
# In this fork the first tool to meet those rows is not the copy but migration
# 000072, which deletes the derived litter, names the business data it will not
# guess about, and refuses. So a database with orphans stops in step 3 rather
# than step 5, with the same effect and a better message. Both refusals are the
# design; neither is a reason to loosen this script.

set -eu
export LC_ALL=C

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENGINE=${CONTAINER_ENGINE:-podman}
IMAGE=${WALLOS_SHADOW_IMAGE:-wallos-shadow}
PG_IMAGE=${WALLOS_SHADOW_PG_IMAGE:-docker.io/library/postgres:14-alpine}
SOURCE=${WALLOS_SHADOW_SOURCE:-}
CONTAINER_ROOT=/var/www/html

# $$ in every container, volume and network name, so two runs on one machine
# cannot claim each other's resources — the same reasoning as
# dev/container-modes.sh, and it matters more here, because a nightly job and a
# hand-started one will eventually overlap.
SUFFIX=$$
NETWORK="wallos-shadow-net-$SUFFIX"
PG_CONTAINER="wallos-shadow-pg-$SUFFIX"
APP_CONTAINER="wallos-shadow-app-$SUFFIX"
DB_VOLUME="wallos-shadow-db-$SUFFIX"
LOGO_VOLUME="wallos-shadow-logos-$SUFFIX"

PG_NAME=wallos
PG_USER=wallos
# Generated per run rather than written down. It guards a throwaway database on
# a network with no published port, for the length of one run; what a constant
# here would really do is teach the next reader that a credential in a script is
# normal.
PG_PASSWORD=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')

usage() {
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

die() {
    printf 'shadow-migrate: %s\n' "$1" >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --source) [ "$#" -ge 2 ] || die '--source needs a path'; SOURCE=$2; shift 2 ;;
        --pg-image) [ "$#" -ge 2 ] || die '--pg-image needs a reference'; PG_IMAGE=$2; shift 2 ;;
        --engine) [ "$#" -ge 2 ] || die '--engine needs a name'; ENGINE=$2; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        -*) die "unknown option: $1 (try --help)" ;;
        *) SOURCE=$1; shift ;;
    esac
done

[ -n "$SOURCE" ] || die 'name a copy of a grown SQLite database (try --help)'
[ -f "$SOURCE" ] || die "no such file: $SOURCE"
[ -r "$SOURCE" ] || die "cannot read: $SOURCE"
command -v "$ENGINE" >/dev/null 2>&1 || die "no container engine: $ENGINE"

# The scratch directory is where the whole run happens. TMPDIR decides where it
# lands, which matters on engines running inside a virtual machine: the
# directory has to be one the machine shares, or the container mounts an empty
# path and the copy is invisible to it.
SCRATCH_ROOT=${TMPDIR:-/tmp}
WORK=$(mktemp -d "${SCRATCH_ROOT%/}/wallos-shadow.XXXXXX")
COPY="$WORK/shadow.db"
REPORT="$WORK/report"
STARTED=$(date +%s)
FAILED=""
: > "$REPORT"

# ----------------------------------------------------------------- the report
#
# Every step appends one line, and the trap prints the whole thing on the way
# out — so the summary exists whether the run finished, refused, or died on
# something nobody anticipated. A report that only the successful path prints is
# a report missing in exactly the case it is for.

record() {
    printf '  %-6s %-16s %s\n' "$1" "$2" "$3" >> "$REPORT"
}

step() {
    printf '\n== %s\n' "$1"
}

ok() {
    record 'ok' "$1" "$2"
}

# Ends the run. The detail is what the cron log will show, so it names the thing
# that refused rather than the step that was running when it did.
fail() {
    record 'FAIL' "$1" "$2"
    FAILED="$1: $2"
    exit 1
}

# The tail of a captured command, indented, for a failure with more to say than
# one line. Called before the cleanup removes the scratch directory.
show() {
    [ -s "$1" ] || return 0
    printf '\n  the last %s line(s) of %s:\n' "$2" "$3"
    tail -n "$2" "$1" | sed 's/^/    /'
}

# ---------------------------------------------------------------- the cleanup
#
# Everything this script creates carries $SUFFIX in its name and is removed
# here, on every exit path including the ones nobody planned. Removing something
# that is already gone is a no-op, so the trap needs to know nothing about how
# far the run got.

cleanup() {
    _status=$?
    # Read before the scratch directory goes, because the report lives in it.
    _report=$(cat "$REPORT" 2>/dev/null || true)

    "$ENGINE" rm -f "$APP_CONTAINER" >/dev/null 2>&1 || true
    "$ENGINE" rm -f "$PG_CONTAINER" >/dev/null 2>&1 || true
    "$ENGINE" volume rm -f "$DB_VOLUME" >/dev/null 2>&1 || true
    "$ENGINE" volume rm -f "$LOGO_VOLUME" >/dev/null 2>&1 || true
    "$ENGINE" network rm "$NETWORK" >/dev/null 2>&1 || true

    if rm -rf "$WORK" 2>/dev/null; then
        _scratch='scratch copy removed'
    else
        # A bind mount written by a container running as root leaves files this
        # user cannot unlink, on engines that do not remap the id. Saying so
        # beats a silent leak in a directory nobody looks at — and the leaked
        # file is a copy of production data.
        _scratch="SCRATCH COPY LEFT BEHIND, remove it by hand: $WORK"
    fi

    printf '\nShadow migration report — %s, %s second(s)\n\n' \
        "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$(($(date +%s) - STARTED))"
    printf '%s\n' "$_report"
    printf '  %-6s %-16s %s\n' 'ok' 'cleanup' \
        "containers, volumes and network removed; $_scratch"

    if [ -n "$FAILED" ]; then
        printf '\nshadow migration FAILED — %s\n\n' "$FAILED"
    elif [ "$_status" -ne 0 ]; then
        printf '\nshadow migration ABORTED before it finished (exit %s)\n\n' "$_status"
    else
        printf '\nshadow migration passed\n\n'
    fi

    exit "$_status"
}
trap cleanup EXIT

# ------------------------------------------------------------ php in the image
#
# Both helpers run their command in a throwaway container built from this tree,
# so what executes is the fork's own code rather than whatever is installed on
# the host. Neither names a backend in PHP: the environment selects it exactly
# as it does for the running application, which is what lets one statement ask
# both sides the same question and get comparable answers.

sqlite_run() {
    "$ENGINE" run --rm -v "$WORK:/shadow:Z" -e WALLOS_DB_PATH=/shadow/shadow.db \
        "$IMAGE" "$@"
}

pgsql_run() {
    "$ENGINE" run --rm --network "$NETWORK" -v "$WORK:/shadow:Z" \
        -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST="$PG_CONTAINER" \
        -e WALLOS_DB_NAME="$PG_NAME" -e WALLOS_DB_USER="$PG_USER" \
        -e WALLOS_DB_PASSWORD="$PG_PASSWORD" \
        "$IMAGE" "$@"
}

# What a database holds, in a form both backends answer identically: one line
# for the migration state, one per table. The names come from $db->tables()
# rather than from a list in this file, because a list here would be one
# migration away from being wrong — and the comparison in step 6 is only worth
# anything if both sides were derived the same way.
READ_STATE='
    require "/var/www/html/includes/database/connection.php";
    $db = wallos_database_connect();
    if ($db->tableExists("migrations")) {
        printf("migrations\t%d\t%s\n",
            (int) $db->scalar("SELECT COUNT(*) FROM \"migrations\""),
            (string) $db->scalar("SELECT MAX(migration) FROM \"migrations\""));
    }
    foreach ($db->tables() as $table) {
        printf("table\t%s\t%d\n", $table,
            (int) $db->scalar("SELECT COUNT(*) FROM \"" . $table . "\""));
    }
    $db->close();
'

applied_count() { awk -F '\t' '$1 == "migrations" { print $2; exit }' "$1"; }
latest_migration() { awk -F '\t' '$1 == "migrations" { print $3; exit }' "$1"; }
table_count() { awk -F '\t' '$1 == "table" { n++ } END { print n + 0 }' "$1"; }
row_total() { awk -F '\t' '$1 == "table" { n += $3 } END { print n + 0 }' "$1"; }

printf '\nWallos shadow migration\n'
printf '  source     %s\n' "$SOURCE"
printf '  engine     %s\n' "$ENGINE"
printf '  scratch    %s\n' "$WORK"

# ------------------------------------------------------------------ the image
#
# Built on every run rather than reused. The chain under test is the one in this
# tree right now, and an image from yesterday would quietly test yesterday's.

step "Building $IMAGE from $ROOT"
BUILD_STATUS=0
"$ENGINE" build -t "$IMAGE" "$ROOT" > "$WORK/build.log" 2>&1 || BUILD_STATUS=$?
if [ "$BUILD_STATUS" -ne 0 ]; then
    show "$WORK/build.log" 20 'the build log'
    fail 'image' "$ENGINE build exited $BUILD_STATUS"
fi
printf '  built\n'

# -------------------------------------------------------- 1. the scratch copy

step '1. Copying the source into the scratch directory'
SOURCE_BEFORE=$(cksum < "$SOURCE")
cp "$SOURCE" "$COPY"

# The copy is ours and the migration chain has to be able to write to it. cp
# carries the source's mode across, and a backup is very often mode 400 or 440 —
# which produced "attempt to write a readonly database" from migration 000064
# the first time this script was pointed at one, a failure about the file
# permission wearing the costume of a broken migration.
chmod u+w "$COPY"

SOURCE_BYTES=$(wc -c < "$SOURCE" | tr -d ' ')
COPY_BYTES=$(wc -c < "$COPY" | tr -d ' ')
if [ "$SOURCE_BYTES" != "$COPY_BYTES" ]; then
    # A truncated database file opens perfectly well and answers questions about
    # the pages that made it, which is why the size is compared rather than
    # assumed — the same check dev/snapshot.sh makes on its own transfer.
    fail 'copy' "$SOURCE_BYTES byte(s) in, $COPY_BYTES byte(s) out"
fi
printf '  %s byte(s) copied to %s\n' "$COPY_BYTES" "$COPY"
ok 'copy' "$COPY_BYTES byte(s) into a scratch file; nothing below opens the original"

# ----------------------------------------------------- 2. where the copy stands

step '2. Reading where the copy stands'
BEFORE="$WORK/state-before"
STATE_STATUS=0
sqlite_run php -r "$READ_STATE" > "$BEFORE" 2> "$WORK/state-before.err" || STATE_STATUS=$?
if [ "$STATE_STATUS" -ne 0 ] || [ ! -s "$BEFORE" ]; then
    show "$WORK/state-before.err" 20 'the error output'
    fail 'before' 'the copy could not be read through the database boundary'
fi

BEFORE_APPLIED=$(applied_count "$BEFORE")
[ -n "$BEFORE_APPLIED" ] || fail 'before' \
    'the copy has no migrations table, so it is not a Wallos database'

BEFORE_LATEST=$(latest_migration "$BEFORE")
BEFORE_TABLES=$(table_count "$BEFORE")
BEFORE_ROWS=$(row_total "$BEFORE")

printf '  %s migration(s) applied, latest %s\n' "$BEFORE_APPLIED" "$BEFORE_LATEST"
printf '  %s row(s) in %s table(s), the largest being\n' "$BEFORE_ROWS" "$BEFORE_TABLES"
awk -F '\t' '$1 == "table" && $3 > 0 { printf "%-34s %10d\n", $2, $3 }' "$BEFORE" |
    sort -k2 -rn | head -12 | sed 's/^/    /'
ok 'before' \
    "$BEFORE_APPLIED migration(s) applied, latest $BEFORE_LATEST, $BEFORE_ROWS row(s) in $BEFORE_TABLES table(s)"

# ------------------------------------------------------- 3. the fork's chain
#
# Run through endpoints/db/migrate.php rather than by including the runner from
# a snippet written here. That endpoint is the caller
# tests/cases/migration_callers_test.php pins: it reads $migrationFailure, keeps
# what the runner printed rather than dropping the buffer, and exits non-zero on
# the command line. A caller that includes the runner and carries on is issue
# #103 exactly, and writing a second one in the tool built to catch it would be
# repeating the defect inside its own detector.

step '3. Running the fork migration chain against the copy'
CHAIN_STATUS=0
sqlite_run php "$CONTAINER_ROOT/endpoints/db/migrate.php" \
    > "$WORK/chain.log" 2>&1 || CHAIN_STATUS=$?
sed 's/^/  /' "$WORK/chain.log"

if [ "$CHAIN_STATUS" -ne 0 ]; then
    # migrate.php names the file it stopped on, and that name is the diagnosis:
    # it belongs in the one-line report, not only in the transcript above. Two
    # different things end up here — a migration that could not do its work, and
    # one like 000072 that found data it will not decide about and refused — so
    # the line names the file and points at the transcript rather than telling
    # the reader which of the two it was.
    BROKEN=$(awk '/^Migration failed: / { print $3; exit }' "$WORK/chain.log")
    [ -n "$BROKEN" ] || BROKEN="the chain exited $CHAIN_STATUS without naming a migration"
    fail 'chain' "$BROKEN stopped the chain; its own message above says why"
fi

AFTER_CHAIN="$WORK/state-after-chain"
sqlite_run php -r "$READ_STATE" > "$AFTER_CHAIN" 2>/dev/null || true
[ -s "$AFTER_CHAIN" ] || fail 'chain' 'the copy could not be read after the chain ran'

CHAIN_APPLIED=$(applied_count "$AFTER_CHAIN")
CHAIN_LATEST=$(latest_migration "$AFTER_CHAIN")

# Compared against the tree rather than against a number written down here — the
# same way dev/e2e.sh and dev/container-modes.sh decide what "fully applied"
# means. A number in this file is stale the day the next migration lands, and a
# check that has to be edited by hand after every migration is a check that
# eventually gets deleted instead.
EXPECTED_MIGRATION=$(basename "$(ls "$ROOT"/migrations/*.php | sort | tail -1)")
case "$CHAIN_LATEST" in
    *"$EXPECTED_MIGRATION") ;;
    *) fail 'chain' \
        "the chain reported success but the copy stands at $CHAIN_LATEST, not $EXPECTED_MIGRATION" ;;
esac

APPLIED_NOW=$((CHAIN_APPLIED - BEFORE_APPLIED))
printf '\n  %s migration(s) applied, the copy now stands at %s\n' "$APPLIED_NOW" "$CHAIN_LATEST"

if [ "$APPLIED_NOW" -eq 0 ]; then
    # Worth saying plainly rather than reporting as a pass. A source already at
    # the head of the chain exercises none of it, which is the same blind spot
    # that put this script in the plan: the run below still tests the copy into
    # PostgreSQL and the boot, but it says nothing about the migrations.
    ok 'chain' \
        "nothing to apply: the source was already at $EXPECTED_MIGRATION, so the chain was not exercised"
else
    ok 'chain' "$APPLIED_NOW migration(s) applied, now through $EXPECTED_MIGRATION"
fi

# ------------------------------------------------------------- 4. PostgreSQL

step "4. Starting a throwaway PostgreSQL ($PG_IMAGE)"
"$ENGINE" network create "$NETWORK" >/dev/null
# No published port at all. Every question is asked from a container on this
# network, so a host-side forward would add a way for the run to fail that has
# nothing to do with the migration — and would put a database holding a copy of
# production data on a host port for as long as the run takes.
"$ENGINE" run -d --name "$PG_CONTAINER" --network "$NETWORK" \
    -e POSTGRES_DB="$PG_NAME" -e POSTGRES_USER="$PG_USER" \
    -e POSTGRES_PASSWORD="$PG_PASSWORD" \
    "$PG_IMAGE" >/dev/null

# A loop rather than a fixed sleep: how long a first start takes is not a
# constant, and a sleep long enough to be safe is a minute added to every run.
READY=0
i=0
while [ "$i" -lt 60 ]; do
    if "$ENGINE" exec "$PG_CONTAINER" pg_isready -U "$PG_USER" -d "$PG_NAME" >/dev/null 2>&1; then
        READY=1
        break
    fi
    i=$((i + 1))
    sleep 2
done

if [ "$READY" -ne 1 ]; then
    "$ENGINE" logs "$PG_CONTAINER" > "$WORK/postgres.log" 2>&1 || true
    show "$WORK/postgres.log" 20 'the PostgreSQL log'
    fail 'postgres' "$PG_IMAGE did not become ready within 120 seconds"
fi
printf '  ready\n'
ok 'postgres' "$PG_IMAGE up as $PG_CONTAINER, no published port"

# ------------------------------------------------------- 5. into PostgreSQL
#
# The target has no schema yet, so the tool applies the baseline itself and then
# copies into it. Everything it does happens in one transaction, and it refuses
# rather than guesses: a source whose applied migrations disagree with the
# baseline, a value that will not fit the column it is going into, a row that
# violates a foreign key PostgreSQL enforces. Those refusals are findings, and
# they are why this step gets its reason quoted into the report.

step '5. Copying the migrated database into PostgreSQL'
COPY_STATUS=0
pgsql_run php "$CONTAINER_ROOT/dev/migrate-to-pgsql.php" --source /shadow/shadow.db \
    > "$WORK/migrate.log" 2>&1 || COPY_STATUS=$?
sed 's/^/  /' "$WORK/migrate.log"

if [ "$COPY_STATUS" -ne 0 ]; then
    REASON=$(awk '/^Refused: / { sub(/^Refused: /, ""); print; exit }' "$WORK/migrate.log")
    [ -n "$REASON" ] || REASON="dev/migrate-to-pgsql.php exited $COPY_STATUS"
    fail 'pgsql copy' "$REASON"
fi

COPIED=$(awk '/row\(s\) would be copied into/ { print $1; exit }' "$WORK/migrate.log")
[ -n "$COPIED" ] || COPIED='an unstated number of'
ok 'pgsql copy' "$COPIED row(s) copied, every sequence set past the ids it copied"

# ----------------------------------------------------------- 6. the row counts
#
# Counted here rather than read out of the tool's own report. migrate-to-pgsql.php
# verifies itself and rolls back when its own counts disagree, which is right,
# and is also exactly the claim that must not be taken on trust: #103 was a
# caller believing a success signal nobody had checked. So both sides are asked
# again, from outside, with one statement.
#
# Before the boot smoke, deliberately. Starting the application writes to the
# migrated database — updatenextpayment, the cron reports — and a comparison
# taken afterwards would be measuring against rows the boot itself added.

step '6. Comparing row counts, table by table'
AFTER_COPY="$WORK/state-after-copy"
pgsql_run php -r "$READ_STATE" > "$AFTER_COPY" 2> "$WORK/state-after-copy.err" || true
if [ ! -s "$AFTER_COPY" ]; then
    show "$WORK/state-after-copy.err" 20 'the error output'
    fail 'row counts' 'PostgreSQL could not be read back after the copy'
fi

# The tables dev/migrate-to-pgsql.php declares as the target's own, asked of
# that file rather than repeated here. `migrations` is seeded by the PostgreSQL
# baseline and deliberately not copied, so a difference there is what the
# migration intends rather than something it lost.
OWNED=$("$ENGINE" run --rm "$IMAGE" php -r \
    'require "/var/www/html/dev/migrate-to-pgsql.php";
     echo implode(" ", wallos_migrate_target_owned_tables());' 2>/dev/null || true)
printf '  not compared: %s (the target owns them)\n' "${OWNED:-none}"

COMPARED=$(awk -F '\t' -v owned="$OWNED" '
    BEGIN { split(owned, list, " "); for (i in list) { skip[list[i]] = 1 } }
    $1 == "table" && !($2 in skip) { n++ }
    END { print n + 0 }
' "$AFTER_CHAIN")

# Every table the SQLite side holds after the chain has to arrive with exactly
# the rows it had. Fewer is data the migration lost. More is data it invented,
# which on a target that was emptied and refilled in one transaction means the
# copy did not start from the state this script measured.
#
# A table the target does not have at all is the same question asked louder: its
# rows had nowhere to go. Empty is the one case that is merely worth mentioning,
# because nothing was lost — so those lines are marked as notes and do not fail
# the run.
MISMATCHES="$WORK/mismatches"
awk -F '\t' -v owned="$OWNED" '
    BEGIN { split(owned, list, " "); for (i in list) { skip[list[i]] = 1 } }
    NR == FNR { if ($1 == "table") { sqlite[$2] = $3 } next }
    { if ($1 == "table") { pgsql[$2] = $3 } }
    END {
        for (name in sqlite) {
            if (name in skip) { continue }
            if (!(name in pgsql)) {
                if (sqlite[name] == 0) {
                    printf "note  %s exists only in SQLite and is empty, so no row was lost\n", name
                } else {
                    printf "%s holds %d row(s) in SQLite and does not exist in PostgreSQL\n",
                        name, sqlite[name]
                }
                continue
            }
            if (pgsql[name] < sqlite[name]) {
                printf "%s lost %d of %d row(s): PostgreSQL holds %d\n",
                    name, sqlite[name] - pgsql[name], sqlite[name], pgsql[name]
            } else if (pgsql[name] > sqlite[name]) {
                printf "%s gained %d row(s): SQLite holds %d, PostgreSQL %d\n",
                    name, pgsql[name] - sqlite[name], sqlite[name], pgsql[name]
            }
        }
    }
' "$AFTER_CHAIN" "$AFTER_COPY" | sort > "$MISMATCHES"

awk '/^note /' "$MISMATCHES" | sed 's/^/  /'
PROBLEMS=$(awk '!/^note /' "$MISMATCHES" | wc -l | tr -d ' ')

if [ "$PROBLEMS" -ne 0 ]; then
    awk '!/^note /' "$MISMATCHES" | sed 's/^/  /'
    FIRST=$(awk '!/^note /' "$MISMATCHES" | head -1)
    fail 'row counts' "$PROBLEMS of $COMPARED table(s) disagree: $FIRST"
fi

printf '  %s table(s) compared, each holds what it held in SQLite\n' "$COMPARED"
ok 'row counts' "$COMPARED table(s) compared, none lost or gained a row"

# ---------------------------------------------------------- 7. the boot smoke
#
# The mode is the one dev/container-modes.sh already proves the image supports:
# an unprivileged user, a read-only root filesystem, one tmpfs, and fresh
# volumes reached through the gid-0 convention. Chosen so this smoke grants no
# privilege the question needs — and so a failure here is about the database,
# because the mode itself is covered by its own gate.
#
# Two things are checked, and the order between them is load-bearing. On a
# database this script has just migrated, startup must find nothing left to do;
# if it announces migrations instead, the migrations table arrived in a state
# the application disagrees with and it is about to replay SQLite DDL against
# PostgreSQL. But that happens after nginx is already serving — startup.sh
# launches php-fpm and nginx first and initialises the database afterwards — so
# health.php answers a second or two before the log can say anything about
# migrations. Checking the log at that moment reports "migrations left to run"
# for every healthy boot, which is what the first version of this step did.
# So: wait for the log to reach its verdict, then confirm health.

step '7. Booting the container against the migrated PostgreSQL'
"$ENGINE" volume create "$DB_VOLUME" >/dev/null
"$ENGINE" volume create "$LOGO_VOLUME" >/dev/null
"$ENGINE" run -d --name "$APP_CONTAINER" --network "$NETWORK" \
    --user 1000:0 --read-only --tmpfs /tmp \
    -v "$DB_VOLUME:$CONTAINER_ROOT/db" \
    -v "$LOGO_VOLUME:$CONTAINER_ROOT/images/uploads/logos" \
    -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST="$PG_CONTAINER" \
    -e WALLOS_DB_NAME="$PG_NAME" -e WALLOS_DB_USER="$PG_USER" \
    -e WALLOS_DB_PASSWORD="$PG_PASSWORD" \
    "$IMAGE" >/dev/null

VERDICT=timeout
i=0
while [ "$i" -lt 60 ]; do
    "$ENGINE" logs "$APP_CONTAINER" > "$WORK/boot.log" 2>&1 || true

    if grep -q 'No migrations to run' "$WORK/boot.log"; then
        VERDICT=clean
        break
    fi
    if grep -q '^Migration ' "$WORK/boot.log"; then
        VERDICT=migrating
        break
    fi
    # A container that has stopped will never print either line, and waiting
    # out the full two minutes for a boot that ended in four seconds buys
    # nothing but a slower report.
    RUNNING=$("$ENGINE" inspect --format '{{.State.Running}}' "$APP_CONTAINER" 2>/dev/null || echo false)
    if [ "$RUNNING" = "false" ]; then
        VERDICT=stopped
        break
    fi

    i=$((i + 1))
    sleep 2
done

case "$VERDICT" in
    clean) printf '  startup found nothing left to migrate\n' ;;
    migrating)
        show "$WORK/boot.log" 25 'the container log'
        fail 'boot smoke' \
            'startup ran migrations against a database this script had just migrated' ;;
    stopped)
        show "$WORK/boot.log" 25 'the container log'
        EXIT_CODE=$("$ENGINE" inspect --format '{{.State.ExitCode}}' "$APP_CONTAINER" 2>/dev/null || echo unknown)
        fail 'boot smoke' "the container stopped before it reached the database (exit $EXIT_CODE)" ;;
    *)
        show "$WORK/boot.log" 25 'the container log'
        fail 'boot smoke' 'startup did not reach the migration chain within 120 seconds' ;;
esac

# Asked from inside the container, the way the image's own HEALTHCHECK does: a
# flaky host-side port forward must not fail a check about the database.
HEALTHY=0
i=0
while [ "$i" -lt 30 ]; do
    if "$ENGINE" exec "$APP_CONTAINER" \
        curl -fsS -o /dev/null http://127.0.0.1:80/health.php 2>/dev/null; then
        HEALTHY=1
        break
    fi
    i=$((i + 1))
    sleep 2
done

if [ "$HEALTHY" -ne 1 ]; then
    "$ENGINE" logs "$APP_CONTAINER" > "$WORK/boot.log" 2>&1 || true
    show "$WORK/boot.log" 25 'the container log'
    fail 'boot smoke' 'health.php did not answer within 60 seconds'
fi
printf '  health.php answered\n'
ok 'boot smoke' 'startup had nothing left to migrate, and health.php answered 200'

# --------------------------------------------------------- 8. the source again

step '8. Checking the file we were given is still what it was'
SOURCE_AFTER=$(cksum < "$SOURCE")
if [ "$SOURCE_BEFORE" != "$SOURCE_AFTER" ]; then
    # Nothing after step 1 opens the source, so this should be impossible. If it
    # ever fires, whoever runs the pipeline has to know before they treat that
    # file as an intact fallback.
    fail 'source' "the source changed during the run: $SOURCE_BEFORE -> $SOURCE_AFTER"
fi
printf '  unchanged (cksum %s)\n' "$SOURCE_AFTER"
ok 'source' 'byte-for-byte what it was before the run'
