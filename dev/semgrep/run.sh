#!/bin/sh
# Gate 2 of issue #41 — run the SQLite boundary rules, exactly as CI runs them.
#
# The rules are in dev/semgrep/sqlite-boundary.yml and are documented there.
# This script exists so that "what CI runs" is one command a contributor can
# type, rather than a docker invocation copied out of a README that drifts.
#
# Usage:
#   dev/semgrep/run.sh              fail on any finding      (exit 1)
#   dev/semgrep/run.sh --report     print findings, never fail
#   dev/semgrep/run.sh --json       machine-readable output
#
#   --engine BIN    force a container engine (podman, docker)
#   --local         require the semgrep on PATH, never a container
#
# Semgrep is not a dependency of Wallos and does not have to be installed: if
# it is not on PATH the script runs the official image through podman or
# docker. If neither is available it says so and exits 2, which is not the same
# answer as "clean" — a gate that cannot run must not report a pass.
#
# What is scanned:
#
#   includes/  endpoints/  api/  and the root *.php files
#
# minus includes/database/ and migrations/, which the rules exclude because
# dialect-specific code belongs there by design. dev/ and tests/ are out of
# scope for a different reason: dev/migrate-to-pgsql.php reads a SQLite file on
# purpose, and the audit's own tests spell the trigger words deliberately. The
# shell scripts under dev/ have their own gate, dev/sh-audit.sh, because
# Semgrep cannot see PHP inside `php -r '...'`.
#
# Run it from a git checkout. Semgrep anchors a rule's `paths: exclude` at the
# repository root, and in a plain directory with no .git it anchors them
# somewhere else and stops excluding includes/database/ — measured: 15 findings
# instead of 9 on the same tree. The failure is loud rather than silent, so a
# tarball build reports too much rather than too little, but the answer only
# means what it says inside a checkout.
#
# Exit codes: 0 clean, 1 findings, 2 usage or no engine.

set -eu

SELF_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SELF_DIR/../.." && pwd)
CONFIG=dev/semgrep/sqlite-boundary.yml
IMAGE=docker.io/semgrep/semgrep
MODE=check
FORMAT=text
ENGINE=${CONTAINER_ENGINE:-}
LOCAL_ONLY=0

die() {
    printf 'semgrep boundary: %s\n' "$1" >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case $1 in
        --check) MODE=check ;;
        --report) MODE=report ;;
        --json) FORMAT=json ;;
        --local) LOCAL_ONLY=1 ;;
        --engine)
            [ "$#" -ge 2 ] || die '--engine needs a value'
            ENGINE=$2
            shift
            ;;
        -h|--help)
            awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

cd "$ROOT"

# The scanned set, built here rather than in the rule file: Semgrep's
# paths.include globs match on basenames as well as paths, so `*.php` there
# would quietly pull in every PHP file in the repository. Naming the targets on
# the command line is unambiguous.
set -- includes endpoints api
for file in *.php; do
    [ -e "$file" ] || continue
    set -- "$@" "$file"
done

# --strict, because a rule that times out or a file Semgrep cannot parse is
# reported as a warning and contributes no findings. Without it the gate would
# answer "clean" for a file it never looked at, which is the one answer a gate
# must never give by accident.
ARGS="--config $CONFIG --metrics=off --disable-version-check --quiet"
[ "$FORMAT" = json ] && ARGS="$ARGS --json"
[ "$MODE" = check ] && ARGS="$ARGS --error --strict"

run_local() {
    # shellcheck disable=SC2086
    semgrep $ARGS "$@"
}

run_container() {
    engine=$1
    shift
    # shellcheck disable=SC2086
    "$engine" run --rm \
        -v "$ROOT:/src:ro" \
        -w /src \
        -e SEMGREP_SEND_METRICS=off \
        "$IMAGE" semgrep $ARGS "$@"
}

STATUS=0

if [ -n "$ENGINE" ]; then
    command -v "$ENGINE" >/dev/null 2>&1 || die "no such container engine: $ENGINE"
    run_container "$ENGINE" "$@" || STATUS=$?
elif command -v semgrep >/dev/null 2>&1; then
    run_local "$@" || STATUS=$?
elif [ "$LOCAL_ONLY" = 1 ]; then
    die 'semgrep is not on PATH and --local was given'
elif command -v podman >/dev/null 2>&1; then
    run_container podman "$@" || STATUS=$?
elif command -v docker >/dev/null 2>&1; then
    run_container docker "$@" || STATUS=$?
else
    die 'neither semgrep, podman nor docker is available — the gate did not run'
fi

if [ "$MODE" = report ]; then
    exit 0
fi

if [ "$STATUS" -ne 0 ]; then
    if [ "$FORMAT" = text ]; then
        cat >&2 <<'EOF'

The SQLite boundary rejected the findings above.

What to do instead:

  new SQLite3(...)            wallos_database_connect()
  SQLite3 $db type hint       WallosDatabase $db
  as camelCase                as "camelCase"
  FROM user                   FROM "user"
  PRAGMA / sqlite_master      $db->tableExists() / columnExists() /
                              tablesWithColumn(), or a migration under
                              migrations/sqlite/

The rules and the reasoning behind each are in dev/semgrep/sqlite-boundary.yml;
the boundary itself is includes/database/, documented in
docs/sqlite-boundary.md.

If nothing is listed above, this is --strict speaking: a rule timed out or a
file did not parse, so part of the tree was not checked. That is a failure and
not a pass, which is the whole point of running with it.
EOF
    fi
    exit 1
fi

exit 0
