#!/bin/sh
# A copy of a running instance's SQLite database, kept as a test input.
#
#   dev/snapshot.sh                        take one, named by timestamp
#   dev/snapshot.sh --name before-79       take one under a name
#   dev/snapshot.sh --list                 what is stored
#   dev/snapshot.sh --show NAME            the manifest of one snapshot
#   dev/snapshot.sh --rehearse NAME        migrate it into a scratch schema
#
# --rehearse takes --schema and --keep, and passes --dry-run, --allow-non-empty
# and --skip-orphans through to dev/migrate-to-pgsql.php. The target is the
# usual WALLOS_DB_* environment, defaulting to the dev PostgreSQL container.
#
# Why: a migration has to survive real data, and real data is not what a
# generator produces. SQLite declares foreign keys and enforces them only when
# asked, so an installation that has been used accumulates rows PostgreSQL will
# refuse — a subscription pointing at a category somebody deleted, notification
# rows belonging to an account that is gone. Those rows are the interesting part
# of a migration and no fixture has them, because a fixture has no history.
#
# The copy is taken with VACUUM INTO through the sqlite3 command, from a
# read-only connection, and falls back to the backup API. Both take a
# transactionally consistent copy of a database that is being written to, which
# `cp` does not: a plain copy of a live SQLite file can catch a page halfway
# through a write and produce a file that opens and is wrong.
#
# This is the one tool in dev/ that talks to SQLite directly rather than through
# wallos_database_connect(), and it has to: copying a database file is not an
# operation the abstraction has, and a snapshot is a SQLite file by definition.
# What it does take from the abstraction is the answer to "which database" —
# dev/snapshot.php asks the configuration for the driver and this script refuses
# to snapshot an instance that does not run on SQLite, so it can never copy, or
# be pointed at, a file that merely happens to be lying around. On the instance
# in issue #91 that file was the backup kept as the rollback route.
#
# Snapshots contain real data. dev/snapshots/ is git-ignored; keep it that way.

set -eu
export LC_ALL=C

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENGINE=${CONTAINER_ENGINE:-podman}
CONTAINER=${WALLOS_CONTAINER:-wallos-dev}
EXEC=${WALLOS_EXEC:-}
STORE=${WALLOS_SNAPSHOT_DIR:-$ROOT/dev/snapshots}
CONTAINER_ROOT=/var/www/html

NAME=""
SOURCE=""
MODE=take
SHOW=""
REHEARSE=""
SCHEMA=""
KEEP=0
PASSTHROUGH=""

# Target for --rehearse: the same variables the application and
# dev/migrate-to-pgsql.php read, with the dev environment's defaults.
DB_HOST=${WALLOS_DB_HOST:-postgres}
DB_PORT=${WALLOS_DB_PORT:-5432}
DB_NAME=${WALLOS_DB_NAME:-wallos}
DB_USER=${WALLOS_DB_USER:-wallos}
DB_PASSWORD=${WALLOS_DB_PASSWORD:-wallos-dev}

usage() {
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --name) NAME=$2; shift 2 ;;
        --source) SOURCE=$2; shift 2 ;;
        --list) MODE=list; shift ;;
        --show) MODE=show; SHOW=$2; shift 2 ;;
        --rehearse) MODE=rehearse; REHEARSE=$2; shift 2 ;;
        --schema) SCHEMA=$2; shift 2 ;;
        --keep) KEEP=1; shift ;;
        # Passed straight to dev/migrate-to-pgsql.php, because the two questions
        # a rehearsal asks are its questions: may the target already hold rows,
        # and should rows that violate a foreign key be left behind rather than
        # refused.
        --allow-non-empty|--skip-orphans|--dry-run) PASSTHROUGH="$PASSTHROUGH $1"; shift ;;
        --exec) EXEC=$2; shift 2 ;;
        --container) CONTAINER=$2; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'unknown option: %s (try --help)\n' "$1" >&2; exit 2 ;;
    esac
done

[ -n "$EXEC" ] || EXEC="$ENGINE exec $CONTAINER"

mkdir -p "$STORE"

# ---------------------------------------------------------------------- list

if [ "$MODE" = list ]; then
    if ! ls "$STORE"/*.db >/dev/null 2>&1; then
        printf '\nNo snapshots in %s\n\n' "$STORE"
        exit 0
    fi

    printf '\nSnapshots in %s\n\n' "$STORE"
    printf '  %-28s %10s  %s\n' 'name' 'bytes' 'contents'
    for file in "$STORE"/*.db; do
        base=$(basename "$file" .db)
        manifest="$STORE/$base.manifest.txt"
        summary='(no manifest)'
        if [ -f "$manifest" ]; then
            rows=$(awk '/^rows  / { print $2; exit }' "$manifest")
            orphans=$(awk '/^  total .* row\(s\) across/ { print $2; exit }' "$manifest")
            [ -n "$orphans" ] || orphans=0
            summary="$rows rows, $orphans violating a foreign key"
        fi
        printf '  %-28s %10s  %s\n' "$base" "$(wc -c < "$file" | tr -d ' ')" "$summary"
    done
    printf '\n'
    exit 0
fi

# ---------------------------------------------------------------------- show

if [ "$MODE" = show ]; then
    manifest="$STORE/$SHOW.manifest.txt"
    [ -f "$manifest" ] || { printf 'no manifest for %s in %s\n' "$SHOW" "$STORE" >&2; exit 1; }
    printf '\n'
    cat "$manifest"
    printf '\n'
    exit 0
fi

# ------------------------------------------------------------------ rehearse

if [ "$MODE" = rehearse ]; then
    SNAPSHOT="$STORE/$REHEARSE.db"
    [ -f "$SNAPSHOT" ] || { printf 'no such snapshot: %s\n' "$SNAPSHOT" >&2; exit 1; }

    case "$SNAPSHOT" in
        "$ROOT"/*) IN_CONTAINER="$CONTAINER_ROOT/${SNAPSHOT#"$ROOT"/}" ;;
        *) printf 'the snapshot has to live under %s to be readable inside the container\n' "$ROOT" >&2; exit 2 ;;
    esac

    [ -n "$SCHEMA" ] || SCHEMA="rehearsal_$(date +%Y%m%d_%H%M%S)"

    # The target settings go into the command rather than into the exec, so that
    # any --exec form works: `env` is in the image, extra flags are not portable
    # between container engines.
    TARGET_ENV="env WALLOS_DB_DRIVER=pgsql WALLOS_DB_HOST=$DB_HOST WALLOS_DB_PORT=$DB_PORT \
WALLOS_DB_NAME=$DB_NAME WALLOS_DB_USER=$DB_USER WALLOS_DB_PASSWORD=$DB_PASSWORD"

    printf '\nRehearsing %s into %s/%s, schema %s\n\n' "$REHEARSE" "$DB_HOST" "$DB_NAME" "$SCHEMA"

    EXISTED=$($EXEC $TARGET_ENV php "$CONTAINER_ROOT/dev/snapshot.php" schema-create "$SCHEMA")

    STATUS=0
    $EXEC $TARGET_ENV php "$CONTAINER_ROOT/dev/migrate-to-pgsql.php" \
        --source "$IN_CONTAINER" --schema "$SCHEMA" $PASSTHROUGH || STATUS=$?

    if [ "$EXISTED" = "existed" ]; then
        # The schema was already there, so it is not this script's to remove:
        # a rehearsal against a real target's own schema has to leave it
        # standing.
        printf '\nSchema %s was not created by this run and is left alone.\n\n' "$SCHEMA"
    elif [ "$KEEP" -eq 1 ]; then
        printf '\nSchema %s kept. Drop it with:\n' "$SCHEMA"
        printf '  %s %s php %s/dev/snapshot.php schema-drop %s\n\n' \
            "$EXEC" "$TARGET_ENV" "$CONTAINER_ROOT" "$SCHEMA"
    else
        $EXEC $TARGET_ENV php "$CONTAINER_ROOT/dev/snapshot.php" schema-drop "$SCHEMA"
        printf '\nScratch schema %s dropped.\n\n' "$SCHEMA"
    fi

    exit "$STATUS"
fi

# ---------------------------------------------------------------------- take

command -v "$ENGINE" >/dev/null 2>&1 || { printf 'no container engine: %s\n' "$ENGINE" >&2; exit 2; }

if ! $EXEC sh -c 'command -v sqlite3' >/dev/null 2>&1; then
    printf 'the sqlite3 command is not in the image, and a safe copy needs it\n' >&2
    exit 2
fi

if [ -z "$SOURCE" ]; then
    # The driver decides whether there is anything to snapshot at all, and the
    # answer comes from the same configuration the application resolves.
    RESOLVED=$($EXEC php "$CONTAINER_ROOT/dev/snapshot.php" source)
    DRIVER=$(printf '%s' "$RESOLVED" | cut -f1)
    SOURCE=$(printf '%s' "$RESOLVED" | cut -f2)

    if [ "$DRIVER" != "sqlite" ]; then
        printf '\nThe instance is configured for %s, so it has no SQLite database to snapshot.\n' "$DRIVER"
        printf 'A file at %s on such an instance is a leftover or a rollback backup,\n' "$SOURCE"
        printf 'and copying it would be copying something nothing is reading. Name it with\n'
        printf -- '--source if that is really what you want.\n\n'
        exit 1
    fi
fi

[ -n "$NAME" ] || NAME="dev-$(date +%Y%m%d-%H%M%S)"
case "$NAME" in
    *[!A-Za-z0-9._-]*) printf 'a snapshot name may hold letters, digits, dot, dash and underscore\n' >&2; exit 2 ;;
esac

DEST="$STORE/$NAME.db"
MANIFEST="$STORE/$NAME.manifest.txt"
# Taken inside the container's own /tmp rather than into the working copy: an
# instance that does not mount the source tree has no dev/snapshots to write
# into, and the file is streamed out below in either case.
WORK="/tmp/wallos-snapshot-$NAME.db"

[ -e "$DEST" ] && { printf 'there is already a snapshot named %s\n' "$NAME" >&2; exit 1; }

printf '\nSnapshot %s\n' "$NAME"
printf '  source     %s\n' "$SOURCE"

$EXEC sh -c "rm -f '$WORK'"

# VACUUM INTO reads inside a transaction and writes a fresh, defragmented file;
# the read-only URI is what guarantees the running instance cannot be written to
# by this tool even by accident. .backup uses the online backup API and is the
# fallback for SQLite older than 3.27.
if $EXEC sh -c "sqlite3 'file:$SOURCE?mode=ro' \"VACUUM INTO '$WORK'\"" 2>/dev/null; then
    METHOD='VACUUM INTO'
else
    $EXEC sh -c "sqlite3 'file:$SOURCE?mode=ro' \".backup '$WORK'\""
    METHOD='backup API'
fi

printf '  method     %s\n' "$METHOD"

INTEGRITY=$($EXEC sh -c "sqlite3 '$WORK' 'PRAGMA integrity_check'" | head -1)
if [ "$INTEGRITY" != "ok" ]; then
    $EXEC sh -c "rm -f '$WORK'"
    printf '  integrity  %s — the copy was discarded\n\n' "$INTEGRITY" >&2
    exit 1
fi
printf '  integrity  ok\n'

SIZE_IN=$($EXEC sh -c "wc -c < '$WORK'" | tr -d ' \r')

# Streamed out rather than assumed to be on a shared mount, so an instance whose
# working copy is not mounted works the same way. The size is compared on both
# sides afterwards, because a truncated database file opens perfectly well.
$EXEC sh -c "cat '$WORK'" > "$DEST"
$EXEC sh -c "rm -f '$WORK'"

SIZE_OUT=$(wc -c < "$DEST" | tr -d ' ')
if [ "$SIZE_IN" != "$SIZE_OUT" ]; then
    rm -f "$DEST"
    printf '  transfer   %s bytes in, %s bytes out — the copy was discarded\n\n' "$SIZE_IN" "$SIZE_OUT" >&2
    exit 1
fi
printf '  bytes      %s\n' "$SIZE_OUT"

case "$DEST" in
    "$ROOT"/*) DEST_IN_CONTAINER="$CONTAINER_ROOT/${DEST#"$ROOT"/}" ;;
    *) DEST_IN_CONTAINER="" ;;
esac

if [ -n "$DEST_IN_CONTAINER" ]; then
    $EXEC php "$CONTAINER_ROOT/dev/snapshot.php" inventory "$DEST_IN_CONTAINER" "$NAME" > "$MANIFEST"
    printf '  manifest   %s\n\n' "$MANIFEST"
    sed 's/^/  /' "$MANIFEST"
    printf '\nRehearse a migration against it with:\n'
    printf '  dev/snapshot.sh --rehearse %s\n\n' "$NAME"
else
    printf '  manifest   not written: %s is not inside %s, so the container cannot read it\n\n' "$DEST" "$ROOT"
fi
