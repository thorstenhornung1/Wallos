#!/bin/sh
# Every JavaScript file the application serves must at least parse.
#
# This exists because of one missing comma. scripts/admin.js line 223 gained
# an object literal entry without a comma before it (7e5cbe4, 2026-08-16), and
# a parse error does not disable one function — it discards the whole file.
# Every admin-page button whose handler lived there was dead in every release
# from 5.8.1 through 5.8.6, and nothing noticed: the endpoints behind the
# buttons are fine, so every server-side test kept passing, and the suite
# never parses a line of JavaScript (issue #119). A human clicking the button
# found it twelve days later.
#
# `node --check` is a parse, not a lint: no style opinions, no configuration,
# no dependencies beyond node itself. If node is not on PATH the script runs
# the official image through podman or docker. If neither is available it
# says so and exits 2 — a gate that cannot run must not report a pass.
#
# Usage:
#   dev/js-audit.sh              check every served .js file
#   dev/js-audit.sh --local      require node on PATH, never a container
#
# Exit codes: 0 pass, 1 parse error, 2 cannot run.

set -u

SELF_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SELF_DIR/.." && pwd)

LOCAL_ONLY=0
[ "${1:-}" = "--local" ] && LOCAL_ONLY=1

# The files the browser is actually given: everything under scripts/,
# including the vendored and generated ones — a parse error in a shipped
# third-party file kills pages exactly the way one of ours does — plus the
# service worker. dev/ and tests/ ship nowhere and are out of scope.
FILES=$(find "$ROOT/scripts" -name '*.js' | sort; ls "$ROOT/service-worker.js" 2>/dev/null)

if [ -z "$FILES" ]; then
    echo "js-audit: no JavaScript files found under scripts/ — refusing to pass on an empty set" >&2
    exit 2
fi

run_check() {
    # $1 = file; prints nothing on success, the parser's message on failure.
    if command -v node >/dev/null 2>&1; then
        node --check "$1" 2>&1
        return $?
    fi

    if [ "$LOCAL_ONLY" = "1" ]; then
        echo "js-audit: --local given and node is not on PATH" >&2
        return 2
    fi

    for engine in podman docker; do
        if command -v "$engine" >/dev/null 2>&1; then
            "$engine" run --rm -v "$ROOT":/app:ro docker.io/library/node:22-alpine \
                node --check "/app${1#"$ROOT"}" 2>&1
            return $?
        fi
    done

    echo "js-audit: neither node nor a container engine is available" >&2
    return 2
}

failures=0
checked=0

for file in $FILES; do
    checked=$((checked + 1))
    output=$(run_check "$file")
    status=$?

    if [ "$status" -eq 2 ]; then
        echo "$output" >&2
        exit 2
    fi

    if [ "$status" -ne 0 ]; then
        failures=$((failures + 1))
        echo "FAIL  $file"
        echo "$output" | sed 's/^/      /'
    fi
done

if [ "$failures" -gt 0 ]; then
    echo ""
    echo "FAIL  $failures of $checked JavaScript file(s) do not parse"
    exit 1
fi

echo "OK    all $checked JavaScript files parse"
exit 0
