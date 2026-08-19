# The SQLite boundary gate

Issue #41 asks CI to prove one thing:

> SQLite-specific APIs and SQL constructs are provably confined to the SQLite
> implementation boundary.

Without that proof the boundary erodes on the first pull request that adds
`SQLITE3_INTEGER` somewhere convenient, and a second backend breaks silently.

The second backend exists now. The PostgreSQL run of 2026-08-19 found three
defects — [#89](https://github.com/thorstenhornung1/Wallos/issues/89),
[#90](https://github.com/thorstenhornung1/Wallos/issues/90),
[#91](https://github.com/thorstenhornung1/Wallos/issues/91) — and they were one
mistake in three places: code written against SQLite's behaviour rather than
against `WallosDatabase`. Two of the three were invisible to every gate on this
page as it stood, and the third lived in a file type none of them read. That is
what this revision is about.

## Which gates are active

| Gate | State | Where |
| --- | --- | --- |
| 1. Textual audit, PHP | **active, blocking** | `dev/db-audit.sh`, job `db-boundary` |
| 1b. Textual audit, shell | **active, blocking** | `dev/sh-audit.sh`, job `db-boundary` |
| 2. Semgrep rules | **active, blocking** | `dev/semgrep/`, job `db-boundary-semgrep` |
| 3. PHPStan rule | **deferred**, sketched below | — |

The numbering is issue #41's. Gate 1b is new: it is gate 1's idea applied to
the file type gate 1 and gate 2 both skip.

Gate 1 is a **ratchet** — leakage may shrink, never grow — because roughly
fifteen hundred call sites still speak SQLite directly and a gate that failed on
those would be switched off within a week. Gates 1b and 2 are **walls**: they
demand zero, and they get it.

## Run them locally

```sh
dev/db-audit.sh              # gate 1, exactly as CI runs it
dev/db-audit.sh --report     # inventory: every file, worst first
dev/db-audit.sh --update     # record the current tree as the new baseline

dev/sh-audit.sh              # gate 1b
dev/sh-audit.sh --report     # print findings, never fail

dev/semgrep/run.sh           # gate 2
dev/semgrep/run.sh --report  # print findings, never fail
```

Gates 1 and 1b need no container, no PHP and no dependency: they use `rg` when
it is installed and fall back to `grep -E` when it is not, because CI runners
and developer machines differ.

Gate 2 needs Semgrep, which is not a dependency of Wallos and does not have to
be installed. `dev/semgrep/run.sh` uses the `semgrep` on `PATH` if there is
one, and otherwise runs the official image through podman or docker. If neither
is available it says so and exits 2 — which is deliberately not the same answer
as "clean". A gate that could not run must never report a pass.

Run gate 2 from a git checkout. Semgrep anchors a rule's `paths: exclude` at the
repository root, and in a plain directory with no `.git` it anchors them
somewhere else and stops excluding `includes/database/`: measured, 15 findings
instead of 9 on the same tree. The error is loud rather than silent, so a
tarball build over-reports rather than under-reports, but the answer only means
what it says inside a checkout.

## Where the boundary is

Since #20 it exists, under `includes/database/`:

```
includes/database/connection.php        backend-neutral: the interface and the factory
includes/database/configuration.php     which backend, and where
includes/database/sqlite/database.php   the SQLite implementation
includes/database/pgsql/                the PostgreSQL implementation
```

`wallos_database_connect()` is the only way application code opens a
connection, and gate 2 asserts that no file outside `includes/database/` still
calls `new SQLite3(`.

The SQLite implementation **extends** `SQLite3` rather than wrapping it. That is
what lets fifteen hundred existing call sites keep working untouched — a
boundary nobody can adopt gradually is a boundary that never gets adopted.
Alongside the inherited methods it adds the operations whose SQLite spelling
would otherwise stay scattered: `scalar()`, `tableExists()`, `columnExists()`,
`tablesWithColumn()`, transactions, `lastInsertId()`, `driver()`.

The whole directory is excluded from every gate, because dialect-specific code
is exactly what belongs there. So is `migrations/`.

### The compatibility surface is part of the contract

This matters more than it looks, and getting it wrong is what kept gate 2
switched off for so long.

`WallosPgsqlDatabase` deliberately speaks some SQLite:

* `WallosPgsqlResult::fetchArray()` maps `SQLITE3_ASSOC`, `_NUM` and `_BOTH`
  onto the matching `PDO::FETCH_*` modes.
* `WallosPgsqlStatement::bindValue()` takes the `SQLITE3_*` type constants
  "accepted for compatibility", and uses `SQLITE3_INTEGER` and `SQLITE3_FLOAT`
  to decide how to coerce a value PostgreSQL would otherwise refuse.
* `WallosPgsqlDatabase` implements `querySingle()`, `lastInsertRowID()` and
  `busyTimeout()` — the last one accepted and ignored — so that call sites do
  not have to ask which backend they are on.

Those spellings are therefore **portable**, not leaks. A gate that fails on them
is not being strict, it is being wrong: it forbids the idiom the boundary
exists to provide. Gate 1 counts them anyway, because it is a ratchet and a
count that shrinks is the signal it is built to give. Gate 2 does not, because
it is a wall and a wall has to be right.

## Gate 1 — the textual audit

`dev/db-audit.sh` compares against `dev/db-audit-baseline.txt`, which records
the current count for every file:

| Situation | Result |
| --- | --- |
| a file's count grows | **FAIL** |
| a file appears that is not in the baseline | **FAIL** |
| a file's count shrinks, or the file is gone | passes, reported, asks for `--update` |
| nothing changed | passes |

The leakage therefore cannot grow while the call sites migrate, and every step
of that work visibly shrinks a checked-in file. When the baseline is empty, the
confinement issue #41 asks for is proven, the gate has become the wall, and the
baseline can be deleted.

Measured while this was written: **1562 matches in 225 files**. The number moves
with every commit; `dev/db-audit.sh --report` prints the current one, and the
baseline file is the record that matters.

### What a contributor sees

Adding one SQLite call to a file that is already in the baseline:

```
SQLite boundary audit — 1483 matches in 206 file(s) (engine: rg)

FAIL  1 file(s) exceed the baseline
        includes/connect.php                                     5 -> 6 (+1)

Where:

  includes/connect.php
    includes/connect.php:5:$db = new SQLite3($databaseFile);
    includes/connect.php:6:$db->busyTimeout(5000);
    includes/connect.php:7:$db->querySingle("PRAGMA journal_mode");
```

followed by an explanation of what to do instead. Touching a file that is not
in the baseline at all reports `not in the baseline` and the same detail.

Removing calls instead:

```
OK    1 file(s) improved — commit the smaller baseline:
        includes/connect.php                                     5 -> 3 (-2)

        dev/db-audit.sh --update
```

That passes. Improvements never fail a build; they only ask to be recorded.

### What gate 1 scans

Every `*.php` file in the repository, except:

| Excluded | Why |
| --- | --- |
| `libs/` | vendored third-party code Wallos does not own |
| `includes/database/` | the adapters — dialect-specific by design |
| `migrations/sqlite/` | documented SQLite-only migrations |
| `.claude/` | agent worktrees: full checkouts nested in the repository |

The fingerprint is gate 1 of issue #41 verbatim, plus `AUTOINCREMENT`:

```
SQLite3  SQLITE3_  querySingle  lastInsertRowID  busyTimeout
PRAGMA   sqlite_master  pragma_table_info  INSERT OR REPLACE  AUTOINCREMENT
```

The pattern is written with `[[:space:]]` rather than `\s` so that ripgrep and
POSIX `grep -E` accept the same string. Counts are **matching lines**, not
matching tokens — the unit both engines agree on without argument.

### Gate 1 is tested

`tests/cases/db_audit_test.php` covers the parser and the ratchet: unchanged,
grown, new file, shrunk, cleared, deleted, `--update` round-trip, excluded
directories, every individual fingerprint, engine parity, corrupt baselines,
and that the committed baseline still matches the tree. Run it with:

```sh
dev/test.sh db_audit
```

That test file deliberately spells the trigger words as concatenations
(`'SQL' . 'ite3'`). The audit scans every `*.php` file including its own tests,
and a test that landed in the baseline would move the ratchet it guards every
time somebody edited it.

## Gate 1b — the shell audit

`dev/sh-audit.sh`, new, and the reason it exists is
[#91](https://github.com/thorstenhornung1/Wallos/issues/91).

The development tooling writes most of its PHP inside a shell script:

```sh
$EXEC php -r '
    $db = new SQLite3("/var/www/html/db/wallos.db");
    ...
'
```

Gate 1 scans `*.php` and gate 2 declares `languages: [php]`, so to both of them
that is a shell string. Five such connections lived in `dev/benchmark.sh` and
`dev/e2e.sh`, invisible to every check in this repository, until the PostgreSQL
run found them the expensive way: `dev/benchmark.sh` seeded through the
abstraction into PostgreSQL, then measured and cleaned up against a stale SQLite
file, ran for 24 minutes and reported success. The numbers meant nothing, and
`cleanup_bench()` aimed a `DELETE` at the file that was being kept as the
rollback route off PostgreSQL.

The gate scans every `*.sh` in the repository and rejects five things:

| Rejected | Instead |
| --- | --- |
| `new SQLite3(` | `wallos_database_connect()` |
| `FROM user` unquoted | `FROM "user"` — reserved word in PostgreSQL |
| `sqlite_master` | `$db->tableExists()` / `tablesWithColumn()` |
| `pragma_table_info` | `$db->columnExists()` |
| a hardcoded `db/wallos.db` | `wallos_database_path()`, or nothing at all |

Comment lines are dropped before matching — a shell `#`, a PHP `//`, the `*`
continuation of a docblock. The sentence "opening `db/wallos.db` here would be
wrong" must not fail the build that the sentence exists to explain.

Three files are skipped, and they are the three gates themselves:
`dev/db-audit.sh` keeps the fingerprint in a search pattern, `dev/sh-audit.sh`
documents it, and `dev/semgrep/run.sh` prints it in a "write this instead"
message. None of them opens a database. Listing them keeps all three readable;
the concatenation trick that works for `tests/cases/db_audit_test.php` would
turn a help message into a puzzle.

`FROM user` is matched case-sensitively, on purpose. Wallos writes SQL keywords
in upper case, and the case-insensitive version matched four English comments
("Update user main currency", "Get budget from user table") for every real site.
Lower-case SQL slips past; a comment that fails the build teaches contributors
to switch the gate off, which costs more.

Measured on this branch: **0 matches**. It is a wall.

## Gate 2 — Semgrep, now switched on

`dev/semgrep/sqlite-boundary.yml` holds the rules; `dev/semgrep/run.sh` runs
them over `includes/`, `endpoints/`, `api/` and the root `*.php` files, minus
`includes/database/` and `migrations/`, and demands **zero**.

### Why it was off, and what had to change

The original four rules could not be switched on, and the note in this document
said so honestly: 1354 findings against a boundary that did not exist yet.

The boundary exists now, and the rules still could not be switched on. Measured
against the enforced scope before this revision:

```
1119 findings, of which
1101  SQLITE3_ASSOC / _TEXT / _INTEGER / _FLOAT / _NUM / _BOTH
  17  querySingle() / lastInsertRowID() / busyTimeout()
   1  sqlite_master
```

**1118 of those 1119 are not violations.** They are the compatibility surface
described above — the vocabulary the boundary promises to accept. The obstacle
was never "too many findings to fix"; it was that the rules were asking for the
wrong thing, and no amount of migration work would ever have made them pass.

So the rules were narrowed to what genuinely does not survive a change of
backend, and extended to the two shapes that let #89 and #90 through.

### What it rejects now

| Rule | Rejects | In scope today |
| --- | --- | --- |
| `sqlite3-class-outside-boundary` | `new SQLite3(`, `SQLite3::` | 0 |
| `sqlite3-only-method-outside-boundary` | the 17 SQLite3 methods `WallosPgsqlDatabase` does **not** implement — `createFunction`, `enableExceptions`, `loadExtension`, `openBlob`, `escapeString`, `columnName`, … | 0 |
| `sqlite3-constant-outside-boundary` | any `SQLITE3_*` outside the accepted vocabulary | 0 |
| `sqlite-only-sql-outside-boundary` | `PRAGMA`, `sqlite_master`, `pragma_table_info`, `INSERT OR REPLACE`, `AUTOINCREMENT` | 2, excluded — see below |
| `sqlite3-type-outside-boundary` | a `SQLite3` parameter, return type or `instanceof` | 0 |
| `sqlite3-typed-property-outside-boundary` | a property declared `SQLite3` | 0 |
| `unquoted-mixed-case-alias` | `as camelCase` without quotes | 0 |
| `unquoted-reserved-table-outside-boundary` | `FROM user` unquoted | 0 |

The constant rule is **inverted** relative to the original. The accepted
vocabulary is a closed list — the fetch modes `WallosPgsqlResult` maps, the bind
types `WallosPgsqlStatement` understands, and the `SQLITE3_OPEN_*` flags that
`wallos_database_connect($path, $flags)` documents as SQLite-only for the three
callers that legitimately name a file. Anything outside that list is a constant
no backend but SQLite has ever been asked to understand. Keep the list in step
with `includes/database/pgsql/{result,statement}.php`.

### What Semgrep adds over gate 1

Exactly one thing, and it is the whole reason to pay for a second engine: it
parses PHP.

Defect #90 was two functions declaring `SQLite3 $database`. A text search for
`SQLite3 $` over the same tree returns 53 hits, 51 of them docblocks. Gate 2
returns the two that matter and none of the 51.

### The one exclusion, and why

`endpoints/cronjobs/createdatabase.php` is excluded **from the SQL rule only**.
It returns at line 18 when the driver is `pgsql`; everything below that builds
the SQLite schema statement by statement, so `PRAGMA` and `sqlite_master` there
are the point rather than a leak. It is the same category as
`migrations/sqlite/`, in a directory that does not say so. It stays in scope for
every other rule in the file, gate 1 still counts its lines, and the two
findings are named here so that nothing disappeared quietly:

```
endpoints/cronjobs/createdatabase.php:269   SELECT name FROM sqlite_master WHERE …
endpoints/cronjobs/createdatabase.php:285   PRAGMA table_info(subscriptions)
```

Line 285 is worth a note. The original rule's regex was

```
\b(PRAGMA\s+\w|sqlite_master|…)\b
```

and the trailing `\b` applies to every alternative, so `PRAGMA\s+\w` could only
ever match a one-letter word. `PRAGMA table_info(subscriptions)` was silently
not a finding for as long as the rule existed. Fixed here.

### It is run with `--strict`

A Semgrep rule that times out, or a file Semgrep cannot parse, is reported as a
warning and contributes no findings. Without `--strict` the gate would answer
"clean" for a file it never looked at.

That is not hypothetical. The original constant rule was written as
`pattern: $CONSTANT` with a `metavariable-regex`, which makes Semgrep bind every
identifier in the file and then filter — and it timed out on `settings.php`, the
largest file in the repository. It is a `pattern-regex` now, and the gate fails
rather than shrugging if anything times out again.

### The rules are mutation-tested

A probe file carrying one of each violation, plus the near-misses that must stay
silent, was dropped into `includes/` and the gate run against it. All 16
violations were reported; all 6 near-misses — a `@param SQLite3` docblock,
`FROM "user"`, `FROM users`, `as "userCount"`, `SQLITE3_ASSOC`,
`lastInsertRowID()` — were not.

### What gate 2 does not cover

Stated because a guard that sounds complete is worse than one with a known edge:

* **`dev/*.php` and `tests/`** are out of scope. `dev/migrate-to-pgsql.php`
  reads a SQLite file on purpose, and the audit's own tests spell the trigger
  words deliberately. `cronjobs/` at the repository root is out of scope too and
  has no stated reason beyond nobody having looked yet.
* **51 docblocks in 15 files** now misdescribe the contract: 50 `@param SQLite3
  $db` and one `@var \SQLite3 $db` in `endpoints/db/migrate.php`. They are
  documentation, not a runtime break, so gate 2 does not fail on them. They are
  still wrong, and they are what a text search for the defect drowns in.
* **A mixed-case alias written without `AS`** — `SELECT COUNT(*) userCount` —
  is not matched. Wallos does not write SQL that way today.
* **Lower-case SQL keywords.** `from user` and `select … as userCount` slip past
  the two textual rules. Both are measured at zero because the codebase
  capitalises keywords; the alternative was a rule that fails on English prose.

## Gate 3 — PHPStan: still deferred

The rule would be roughly this:

```php
/** @implements Rule<Node\Expr\New_> */
final class SqliteOnlyInsideAdapter implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $class = $node->class->toString();

        if (!str_starts_with($class, 'SQLite3')) {
            return [];
        }

        if (str_contains($scope->getFile(), '/includes/database/sqlite/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message($class . ' may only be used inside the SQLite adapter.')
                ->identifier('wallos.sqliteOutsideAdapter')
                ->build(),
        ];
    }
}
```

The two objections recorded when this was first written have not both survived,
and the honest position has moved:

1. **Wallos vendors its libraries and has no Composer setup.** Unchanged. A
   custom PHPStan rule is a PHP class implementing PHPStan's `Rule` interface,
   so PHPStan's own classes must be loadable when the rule is compiled — in
   practice `composer require --dev phpstan/phpstan`. Introducing Composer to a
   project that deliberately avoids it is a larger decision than this issue.

2. **"There is almost nothing for it to type yet" no longer holds.** The
   `WallosDatabase` interface exists, two implementations exist, and #90 was
   precisely a type error. What has changed is that gate 2 now catches that
   shape without Composer: Semgrep parses the type hint out of the AST and does
   not need to resolve it.

So gate 3 is deferred for a better reason than before: it is no longer the
cheapest way to get what it was wanted for. What it would still add over gate 2
is the case where nothing in the line says "SQLite" — `$db->querySingle()` on a
variable whose declared type is `WallosSqliteDatabase`. That is worth having
eventually. It is not worth a build system for.

## In CI

`.github/workflows/build-release.yaml`:

* **`db-boundary`** runs gate 1 and gate 1b. Both are grep, both need nothing
  installed, and they report in seconds. Gate 1b runs with `if: always()` so a
  failure in gate 1 does not hide it — the two look at different files, and
  learning about both in one run beats two round trips.
* **`db-boundary-semgrep`** runs gate 2. It is a job of its own because it is
  the only part that needs an install; making the grep gates wait on `pipx`
  would spend the property that made them worth having. Semgrep is **pinned**:
  an unpinned linter turns somebody else's release into a red build on a branch
  that changed nothing, and the first fix anybody reaches for then is to switch
  the gate off.

Both answer an architecture question rather than a "does the code work"
question, which is why neither is a step inside `test`. A failure turns the
whole workflow red — that is the enforcement. `build` does not depend on either:
refusing to produce an image over a boundary regression only creates pressure to
switch the gates off.

## Definition of done, honestly stated

Issue #41's checklist against this branch:

```
rg SQLite audit                 ratcheted — see dev/db-audit-baseline.txt
shell audit                     PASS   (0 matches — a wall)
Semgrep SQLite leak rules       PASS   (0 findings — a wall)
PHPStan DB architecture rule    DEFERRED, and now for a weaker reason — see above
SQLite test suite               PASS
```

Gate 1 holds the line on the fifteen hundred call sites that still speak SQLite
and is honest about being a ratchet rather than a proof. Gates 1b and 2 are
proofs, over the shapes they cover, of the thing #89, #90 and #91 each violated:
that application code depends on `WallosDatabase` and not on SQLite.

Tested rather than asserted: run against the tree as it stood before commit
`018a45b`, gate 2 reports exactly nine findings — the seven unquoted aliases of
#89 and the two `SQLite3` type hints of #90, and nothing else. Run against the
tree before `dev/*.sh` was repaired, gate 1b reports eleven — the five
connections of #91 and six unquoted `FROM user` that no issue had noticed.
