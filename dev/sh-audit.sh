#!/bin/sh
# The SQLite boundary, for shell scripts.
#
# Gate 2 (dev/semgrep/run.sh) declares `languages: [php]`, so Semgrep parses
# .php files and nothing else. The development tooling writes most of its PHP
# inside a shell script:
#
#     $EXEC php -r '
#         $db = new SQLite3("/var/www/html/db/wallos.db");
#         ...
#     '
#
# which is a shell string as far as every PHP tool in this repository is
# concerned. Five such connections lived in dev/benchmark.sh and dev/e2e.sh,
# invisible to both gates, until a PostgreSQL test run found them the expensive
# way: the benchmark seeded through the abstraction into PostgreSQL, then
# measured and cleaned up against a stale SQLite file, reported success, and
# the numbers meant nothing (issue #91).
#
# This gate is three lines of grep with the explanation attached. It is a wall,
# not a ratchet: the count is zero and there is no reason to let it rise.
#
# Usage:
#   dev/sh-audit.sh              check every shell script  (exit 1 on a finding)
#   dev/sh-audit.sh --report     print findings, never fail
#
#   --root DIR    audit a different tree
#
# Exit codes: 0 clean, 1 findings, 2 usage or internal error.

set -eu
export LC_ALL=C

SELF_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SELF_DIR/.." && pwd)
MODE=check

# What a shell script must not contain.
#
#   new SQLite3(        a connection that bypasses wallos_database_connect(),
#                       so it reads a file even when the instance runs on
#                       PostgreSQL
#   FROM user           `user` is a reserved word in PostgreSQL: unquoted it
#                       means the session user, not the table. Upper case only,
#                       because Wallos writes SQL keywords that way and the
#                       case-insensitive version matches English prose.
#   sqlite_master       SQLite's own catalogue; ask the boundary instead
#   pragma_table_info   likewise
#   db/wallos.db        a hardcoded path to the SQLite file. Even reached
#                       through the abstraction this is wrong on an instance
#                       that keeps its data elsewhere — and on this instance
#                       that path held the backup kept as the rollback route
#                       after the move to PostgreSQL, so a DELETE against it
#                       was aimed at the only copy of the old data.
PATTERN='new[[:space:]]+SQLite3\(|(FROM|JOIN|INTO|UPDATE)[[:space:]]+user([^A-Za-z0-9_]|$)|sqlite_master|pragma_table_info|db/wallos\.db'

# The boundary gates themselves. All three carry the fingerprint as data — a
# search pattern, a comment, the "write this instead" help text — and none of
# them opens a database. Listing them here rather than obfuscating the strings
# keeps all three readable; dev/db-audit.sh solves the same problem for its own
# test file by spelling the words as concatenations, which is the right answer
# for PHP and an unreadable one for a help message.
SKIP='dev/db-audit.sh dev/sh-audit.sh dev/semgrep/run.sh'

usage() {
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

die() {
    printf 'sh-audit: %s\n' "$1" >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case $1 in
        --check) MODE=check ;;
        --report) MODE=report ;;
        --root)
            [ "$#" -ge 2 ] || die '--root needs a directory'
            ROOT=$(CDPATH= cd -- "$2" 2>/dev/null && pwd) || die "no such directory: $2"
            shift
            ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

[ -d "$ROOT" ] || die "no such directory: $ROOT"

skipped() {
    for entry in $SKIP; do
        [ "$1" = "$entry" ] && return 0
    done
    return 1
}

# Comment lines are dropped before matching. A shell `#`, a PHP `//` and the
# continuation `*` of a docblock all describe the thing rather than doing it,
# and the honest sentence "opening db/wallos.db here would be wrong" must not
# fail the build that the sentence exists to explain.
scan() {
    grep -nE "$PATTERN" "$1" 2>/dev/null |
        grep -vE '^[0-9]+:[[:space:]]*(#|//|\*)' || true
}

FILES=$(cd "$ROOT" && find . -type d \( -name .git -o -name libs -o -name .claude -o -name node_modules -o -name vendor \) -prune -o -type f -name '*.sh' -print |
    sed 's|^\./||' | sort)

TOTAL=0
FILE_COUNT=0
FINDINGS=''

for file in $FILES; do
    skipped "$file" && continue
    hits=$(cd "$ROOT" && scan "$file")
    [ -n "$hits" ] || continue
    count=$(printf '%s\n' "$hits" | wc -l | tr -d ' ')
    TOTAL=$((TOTAL + count))
    FILE_COUNT=$((FILE_COUNT + 1))
    detail=$(printf '%s\n' "$hits" | sed 's|^|    '"$file"':|')
    if [ -z "$FINDINGS" ]; then
        FINDINGS=$detail
    else
        FINDINGS=$(printf '%s\n%s' "$FINDINGS" "$detail")
    fi
done

printf 'Shell SQLite boundary audit — %d match(es) in %d file(s)\n\n' "$TOTAL" "$FILE_COUNT"

if [ "$TOTAL" -eq 0 ]; then
    printf 'OK    no shell script reaches SQLite directly\n'
    exit 0
fi

printf '%s\n\n' "$FINDINGS"

if [ "$MODE" = report ]; then
    exit 0
fi

cat >&2 <<'EOF'
FAIL  a shell script talks to SQLite instead of to the database boundary.

PHP inside `php -r '...'` is still application code, and the instance it runs
against may not be SQLite. Open the configured connection instead:

    $EXEC php -r '
        require "/var/www/html/includes/database/connection.php";
        $db = wallos_database_connect();
        ...
    '

and inside it use the portable spellings:

    new SQLite3("…/db/wallos.db")   wallos_database_connect()
    FROM user                       FROM "user"
    sqlite_master / PRAGMA          $db->tableExists() / columnExists() /
                                    tablesWithColumn()

A tool that writes to a different database than the application reads reports
success and measures nothing — that is issue #91. The reasoning is in
docs/sqlite-boundary.md.
EOF

exit 1
