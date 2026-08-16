#!/bin/sh
# SQLite boundary audit — a ratchet, not a wall.
#
# Issue #41 asks CI to prove that SQLite-specific APIs stay inside the database
# adapter boundary.  That boundary does not exist yet — building it is issue
# #20 — and the audit currently finds roughly 1500 matches across 200+ files.
#
# A gate that simply fails on those is worthless: nobody can act on it, so it
# gets switched off within a week.  This is a baseline gate instead.
# dev/db-audit-baseline.txt records, per file, how many SQLite-specific matches
# that file is allowed to have today:
#
#   * a file whose count grows              -> FAIL
#   * a file that is not in the baseline    -> FAIL
#   * a file whose count shrinks or clears  -> reported, still passes, and the
#                                              contributor is asked to --update
#
# The leakage therefore cannot grow while #20 is in progress, and every step of
# that work visibly shrinks the baseline.  When the last entry disappears the
# gate has become the wall the issue asks for, and the baseline can be deleted.
#
# Usage:
#   dev/db-audit.sh                  check the tree against the baseline
#   dev/db-audit.sh --report         print the current counts, never fails
#   dev/db-audit.sh --update         rewrite the baseline from the current tree
#
#   --root DIR         audit a different tree (the test suite uses this)
#   --baseline FILE    use a different baseline file
#   --engine rg|grep   force a search engine instead of preferring rg
#
# Exit codes: 0 pass, 1 regression, 2 usage or internal error.

set -eu

SELF_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SELF_DIR/.." && pwd)
BASELINE=$SELF_DIR/db-audit-baseline.txt
ENGINE=${WALLOS_DB_AUDIT_ENGINE:-auto}
MODE=check
DETAIL_LINES=15

# The SQLite fingerprint.  Gate 1 of issue #41, plus AUTOINCREMENT, which the
# issue lists under the Semgrep gate but which is just as SQLite-specific and
# just as cheap to catch textually.  Written with [[:space:]] rather than \s so
# that ripgrep and POSIX grep -E accept the identical pattern.
PATTERN='SQLite3|SQLITE3_|querySingle|lastInsertRowID|busyTimeout|PRAGMA|sqlite_master|pragma_table_info|INSERT[[:space:]]+OR[[:space:]]+REPLACE|AUTOINCREMENT'

# Directories the audit does not look at.  The first is vendored third-party
# code that Wallos does not own; the next two are the permitted SQLite
# implementation boundary from issue #41.  They do not exist yet — issue #20
# creates them — and listing them now means the baseline shrinks by itself as
# code moves in.
#
# .claude holds agent worktrees, which are full checkouts nested inside the
# repository.  Scanning them counts the whole tree a second time and reports
# every file as new, because the baseline stores root-relative paths.
EXCLUDED_PATHS='libs includes/database/sqlite migrations/sqlite .claude'

# The comment block at the top of this file is the documentation, so print that
# rather than keeping a second copy in sync with it.
usage() {
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

die() {
    printf 'db-audit: %s\n' "$1" >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case $1 in
        --check) MODE=check ;;
        --report) MODE=report ;;
        --update) MODE=update ;;
        --root)
            [ "$#" -ge 2 ] || die '--root needs a directory'
            ROOT=$(CDPATH= cd -- "$2" 2>/dev/null && pwd) || die "no such directory: $2"
            shift
            ;;
        --baseline)
            [ "$#" -ge 2 ] || die '--baseline needs a file'
            BASELINE=$2
            shift
            ;;
        --engine)
            [ "$#" -ge 2 ] || die '--engine needs rg, grep or auto'
            ENGINE=$2
            shift
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *) die "unknown argument: $1 (try --help)" ;;
    esac
    shift
done

case $ENGINE in
    auto)
        if command -v rg >/dev/null 2>&1; then
            ENGINE=rg
        else
            ENGINE=grep
        fi
        ;;
    rg) command -v rg >/dev/null 2>&1 || die 'ripgrep was requested but is not installed' ;;
    grep) ;;
    *) die "unknown engine: $ENGINE (expected rg, grep or auto)" ;;
esac

TMP=$(mktemp -d "${TMPDIR:-/tmp}/db-audit.XXXXXX") || die 'could not create a temporary directory'
trap 'rm -rf "$TMP"' EXIT
trap 'rm -rf "$TMP"; exit 130' INT
trap 'rm -rf "$TMP"; exit 143' TERM

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RED=$(printf '\033[31m')
    C_GREEN=$(printf '\033[32m')
    C_YELLOW=$(printf '\033[33m')
    C_OFF=$(printf '\033[0m')
else
    C_RED=''
    C_GREEN=''
    C_YELLOW=''
    C_OFF=''
fi

# Lists every candidate file, root-relative and sorted, so that both engines
# see exactly the same set.  ripgrep's own ignore rules are switched off below
# for the same reason: the audit must not depend on .gitignore.
find_files() {
    set -- . '(' -path ./.git
    for excluded in $EXCLUDED_PATHS; do
        set -- "$@" -o -path "./$excluded"
    done
    set -- "$@" ')' -prune -o -type f -name '*.php' -print

    find "$@" | sed 's|^\./||' | LC_ALL=C sort
}

# Writes "<path><TAB><matching lines>" for every file with at least one match.
scan_into() {
    destination=$1

    : >"$destination"
    files=$(find_files)
    [ -n "$files" ] || return 0

    set --
    while IFS= read -r file; do
        [ -n "$file" ] || continue
        set -- "$@" "$file"
    done <<LIST
$files
LIST

    [ "$#" -gt 0 ] || return 0

    set +e
    if [ "$ENGINE" = rg ]; then
        rg --no-ignore --hidden --no-heading --with-filename --count --regexp "$PATTERN" -- "$@" >"$TMP/raw"
    else
        grep -c -H -E -e "$PATTERN" -- "$@" >"$TMP/raw"
    fi
    status=$?
    set -e

    # 0 = matches found, 1 = none found; anything else is a real failure.
    [ "$status" -le 1 ] || die "search failed (engine $ENGINE, exit $status)"

    # Both engines emit "<path>:<count>"; split on the last colon so a path
    # containing one cannot corrupt the count.
    awk '{
        pos = 0
        for (i = length($0); i > 0; i--) {
            if (substr($0, i, 1) == ":") { pos = i; break }
        }
        if (pos == 0) next
        count = substr($0, pos + 1) + 0
        if (count > 0) printf "%s\t%d\n", substr($0, 1, pos - 1), count
    }' "$TMP/raw" | LC_ALL=C sort >"$destination"
}

totals_line() {
    awk -F'\t' '{ matches += $2; files++ }
        END { printf "%d matches in %d file(s)\n", matches, files }' "$1"
}

cd "$ROOT"
scan_into "$TMP/current"

case $MODE in
    report)
        printf 'SQLite boundary audit — %s (engine: %s)\n\n' "$(totals_line "$TMP/current")" "$ENGINE"
        sort -t"$(printf '\t')" -k2 -rn "$TMP/current" \
            | awk -F'\t' '{ printf "  %6d  %s\n", $2, $1 }'
        exit 0
        ;;
    update)
        {
            cat <<HEADER
# SQLite boundary baseline — generated by dev/db-audit.sh --update
#
# One line per file: <path><TAB><lines matching the SQLite fingerprint>.
#
# This file exists because the database adapter boundary does not exist yet
# (issue #20).  Failing CI on every one of these matches would be unactionable
# and the gate would be switched off, so dev/db-audit.sh ratchets instead: a
# count may fall, never rise, and a file absent from this list may not start
# matching.  Every step of the adapter work shrinks this file; when it is empty
# the confinement issue #41 asks for is proven, and this baseline can go.
#
# Do not edit by hand — run dev/db-audit.sh --update and commit the diff.
#
# total: $(totals_line "$TMP/current")
HEADER
            cat "$TMP/current"
        } >"$TMP/baseline.new"

        mv "$TMP/baseline.new" "$BASELINE"
        printf '%sbaseline updated%s: %s — %s\n' "$C_GREEN" "$C_OFF" "$BASELINE" "$(totals_line "$TMP/current")"
        exit 0
        ;;
esac

[ -f "$BASELINE" ] || die "no baseline at $BASELINE — create one with dev/db-audit.sh --update"

printf 'SQLite boundary audit — %s (engine: %s)\n\n' "$(totals_line "$TMP/current")" "$ENGINE"

set +e
awk -F'\t' \
    -v basefile="$BASELINE" \
    -v failfile="$TMP/failing" \
    -v red="$C_RED" -v green="$C_GREEN" -v yellow="$C_YELLOW" -v off="$C_OFF" '
    FILENAME == basefile {
        if ($0 ~ /^[ \t]*#/ || $0 ~ /^[ \t]*$/) next

        if (NF != 2 || $2 !~ /^[0-9]+$/) {
            printf "%smalformed baseline line %d%s: %s\n", red, FNR, off, $0
            malformed = 1
            next
        }

        if ($1 in base) {
            printf "%sduplicate baseline entry%s: %s\n", red, off, $1
            malformed = 1
        }

        base[$1] = $2 + 0
        border[++nb] = $1
        next
    }

    {
        current[$1] = $2 + 0
        corder[++nc] = $1
    }

    END {
        if (malformed) { exit 2 }

        for (i = 1; i <= nc; i++) {
            path = corder[i]

            if (!(path in base)) {
                added[++nadded] = path
                print path > failfile
            } else if (current[path] > base[path]) {
                grown[++ngrown] = path
                print path > failfile
            } else if (current[path] < base[path]) {
                shrunk[++nshrunk] = path
            }
        }

        for (i = 1; i <= nb; i++) {
            path = border[i]
            if (!(path in current)) { cleared[++ncleared] = path }
        }

        if (nadded) {
            printf "%sFAIL%s  %d file(s) outside the baseline now use a SQLite-specific API\n", red, off, nadded
            for (i = 1; i <= nadded; i++) {
                printf "        %-56s %d match(es), not in the baseline\n", added[i], current[added[i]]
            }
            printf "\n"
        }

        if (ngrown) {
            printf "%sFAIL%s  %d file(s) exceed the baseline\n", red, off, ngrown
            for (i = 1; i <= ngrown; i++) {
                path = grown[i]
                printf "        %-56s %d -> %d (+%d)\n", path, base[path], current[path], current[path] - base[path]
            }
            printf "\n"
        }

        if (nshrunk || ncleared) {
            printf "%sOK%s    %d file(s) improved — commit the smaller baseline:\n", green, off, nshrunk + ncleared
            for (i = 1; i <= nshrunk; i++) {
                path = shrunk[i]
                printf "        %-56s %d -> %d (-%d)\n", path, base[path], current[path], base[path] - current[path]
            }
            for (i = 1; i <= ncleared; i++) {
                path = cleared[i]
                printf "        %-56s %d -> 0 (cleared)\n", path, base[path]
            }
            printf "\n        %sdev/db-audit.sh --update%s\n\n", yellow, off
        }

        if (nadded || ngrown) { exit 1 }

        if (!nshrunk && !ncleared) {
            printf "%sOK%s    no change against the baseline\n", green, off
        }

        exit 0
    }
' "$BASELINE" "$TMP/current"
verdict=$?
set -e

if [ "$verdict" -eq 2 ]; then
    printf '\nThe baseline is not readable. Regenerate it with dev/db-audit.sh --update.\n' >&2
    exit 2
fi

if [ "$verdict" -ne 0 ] && [ -s "$TMP/failing" ]; then
    printf 'Where:\n'
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        printf '\n  %s\n' "$path"
        if [ "$ENGINE" = rg ]; then
            rg --no-ignore --hidden --no-heading --with-filename --line-number \
                --regexp "$PATTERN" -- "$path" 2>/dev/null | head -n "$DETAIL_LINES" | sed 's/^/    /'
        else
            grep -n -H -E -e "$PATTERN" -- "$path" 2>/dev/null | head -n "$DETAIL_LINES" | sed 's/^/    /'
        fi
    done <"$TMP/failing"

    cat <<'EXPLANATION'

SQLite-specific APIs must stay inside the database adapter boundary, so that a
second backend cannot be broken silently by application code (issue #41).

That boundary is still being built (issue #20), which is why this gate ratchets
rather than demanding zero: what is already there is recorded in the baseline,
but it may not grow.

  * Use the Wallos database API instead of the SQLite3 class, its constants and
    its SQLite-only methods.
  * SQL that only SQLite understands (PRAGMA, sqlite_master, pragma_table_info,
    INSERT OR REPLACE, AUTOINCREMENT) belongs in a SQLite-only migration.
  * Genuinely SQLite-only code belongs under includes/database/sqlite/ or
    migrations/sqlite/, which the audit does not scan.

Raising the baseline to make this pass makes issue #20 bigger. Do it only with
a reason stated in the pull request.
EXPLANATION
fi

exit "$verdict"
