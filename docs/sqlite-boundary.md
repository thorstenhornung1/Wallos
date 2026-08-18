# The SQLite boundary gate

Issue #41 asks CI to prove one thing:

> SQLite-specific APIs and SQL constructs are provably confined to the SQLite
> implementation boundary.

Without that proof the boundary erodes on the first pull request that adds
`SQLITE3_INTEGER` somewhere convenient, and a second backend breaks silently.

## Which gates are active

| Gate | State | Where |
| --- | --- | --- |
| 1. Textual audit | **active, blocking in CI** | `dev/db-audit.sh`, job `db-boundary` |
| 2. Semgrep rules | written, run by hand, **not in CI** | `dev/semgrep/sqlite-boundary.yml` |
| 3. PHPStan rule | **deferred**, sketched below | — |

One gate that genuinely works is worth more than three that are cosmetic.
Gate 1 does the gating. The reasoning for the other two is at the end, so that
nobody has to re-derive it.

## Run it locally

```sh
dev/db-audit.sh              # the gate, exactly as CI runs it
dev/db-audit.sh --report     # inventory: every file, worst first
dev/db-audit.sh --update     # record the current tree as the new baseline
```

No container, no PHP, no dependency: the script uses `rg` when it is installed
and falls back to `grep -E` when it is not, because CI runners and developer
machines differ. Both engines are given the identical file list and produce
byte-identical output; the test suite asserts that.

## Where the boundary is

Since #20 it exists, under `includes/database/`:

```
includes/database/connection.php        backend-neutral: the interface and the factory
includes/database/sqlite/database.php   the SQLite implementation
```

`wallos_database_connect()` is the only way application code opens a connection,
and a test asserts that no file outside `includes/database/` still calls
`new SQLite3(`.

The implementation **extends** `SQLite3` rather than wrapping it. That is what
lets fifteen hundred existing call sites keep working untouched — a boundary
nobody can adopt gradually is a boundary that never gets adopted. Alongside the
inherited methods it adds the operations whose SQLite spelling would otherwise
stay scattered: `scalar()`, `tableExists()`, `columnExists()`, transactions,
`lastInsertId()`, `driver()`.

The whole directory is excluded from the audit, because dialect-specific code is
exactly what belongs there. A second backend gets a directory beside
`sqlite/`.

## Why it is a ratchet and not a wall

The boundary exists, but the call sites behind it still speak SQLite directly.
Measured on this branch, the audit finds:

```
1534 matches in 217 files
```

A gate that simply failed on those would be worthless. Nobody could act on it,
so it would be switched off within a week, and the erosion the issue is about
would continue unobserved.

So `dev/db-audit.sh` compares against `dev/db-audit-baseline.txt`, which
records the current count for every file:

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

## What a contributor sees

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

## What is scanned

Every `*.php` file in the repository, except:

| Excluded | Why |
| --- | --- |
| `libs/` | vendored third-party code Wallos does not own |
| `includes/database/sqlite/` | the permitted SQLite adapter (issue #20 creates it) |
| `migrations/sqlite/` | documented SQLite-only migrations |

The last two do not exist yet. Listing them now means the baseline shrinks by
itself as code moves into the boundary, which is exactly the signal wanted.

The fingerprint is gate 1 of issue #41 verbatim, plus `AUTOINCREMENT`:

```
SQLite3  SQLITE3_  querySingle  lastInsertRowID  busyTimeout
PRAGMA   sqlite_master  pragma_table_info  INSERT OR REPLACE  AUTOINCREMENT
```

`AUTOINCREMENT` is listed under gate 2 in the issue but is just as
SQLite-specific and just as cheap to catch textually; it accounts for 7 of the
1482 matches. The pattern is written with `[[:space:]]` rather than `\s` so
that ripgrep and POSIX `grep -E` accept the same string.

Counts are **matching lines**, not matching tokens — the unit both engines agree
on without argument.

## The audit is tested

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

## In CI

`.github/workflows/build-release.yaml` runs the audit as a separate
`db-boundary` job rather than a step inside `test`. It answers an architecture
question, not a "does the code work" question, and needs no PHP, so it reports
in seconds and in parallel. A failure turns the whole workflow red — that is
the enforcement. `build` does not depend on it: refusing to produce an image
over a boundary regression only creates pressure to switch the gate off.

---

## Gate 2 — Semgrep: written, not wired

`dev/semgrep/sqlite-boundary.yml` holds four rules covering the SQLite3
classes, the SQLite-only methods, the `SQLITE3_*` constants and SQLite-only SQL.
Run them with no repository dependency:

```sh
podman run --rm -v "$PWD:/src:ro" -w /src docker.io/semgrep/semgrep \
    semgrep --config dev/semgrep/sqlite-boundary.yml --error
```

Verified against this branch: the configuration is valid and produces 1354
findings in 190 files, 0 errors.

They are **not** in CI, deliberately:

* They cannot pass. 1354 findings, and no way to make them pass until #20 lands.
* Semgrep has no per-file baseline of the kind `dev/db-audit.sh` keeps. Its
  `--baseline-commit` needs full git history and only makes sense on pull
  requests, so it would be a second ratchet with a different shape and weaker
  coverage than the one that already works.
* A permanently red CI step gets ignored, then deleted.

What Semgrep will add once the count is low enough to demand zero: it
distinguishes a method call from the same word in a comment or a variable name,
so it can be made strict without the false positives a text search would
produce. Switch it on in `build-release.yaml` at that point and retire the
baseline.

## Gate 3 — PHPStan: deferred

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

registered through `phpstan.neon`:

```neon
services:
    -
        class: Wallos\PHPStan\SqliteOnlyInsideAdapter
        tags: [phpstan.rules.rule]
```

with sibling rules on `Node\Expr\MethodCall` for `querySingle`/`lastInsertRowID`
/`busyTimeout` and on `Node\Expr\ConstFetch` for `SQLITE3_*`.

It is deferred for two reasons, one practical and one that matters more:

1. **Wallos vendors its libraries and has no Composer setup.** A custom PHPStan
   rule is a PHP class that implements PHPStan's `Rule` interface, so PHPStan's
   own classes have to be loadable when the rule is compiled. In practice that
   means `composer require --dev phpstan/phpstan`. Introducing Composer to a
   project that deliberately avoids it is a larger decision than this issue, and
   issue #41 does not need it to be answered.

2. **There is almost nothing for it to type yet.** PHPStan earns its place over
   a text search by catching `$db->querySingle()` where nothing in the line says
   "SQLite" — but only when `$db` has a declared type. This repository declares
   `SQLite3 $db` in 41 places across 16 files; everything else is a global
   `$db` created by `includes/connect.php` and pulled in by `require`, which
   PHPStan cannot follow. The type-aware advantage is therefore small today.

Both objections dissolve at the same moment: issue #20 introduces a real
adapter interface with a real type, and the rule becomes both cheap and
genuinely useful. **The right time to write gate 3 is immediately after #20,
not before.** Until then it would be a rule that inspects an architecture that
does not exist.

## Definition of done, honestly stated

Issue #41's checklist against this branch:

```
rg SQLite audit                 PASS   (ratcheted at 1482 matches in 206 files)
Semgrep SQLite leak rules       WRITTEN, not enforced — see above
PHPStan DB architecture rule    DEFERRED to just after #20 — see above
SQLite test suite               PASS
```

The audit is the gate that holds the line. It is enough to keep the promise
that matters — no PostgreSQL adapter work begins while the SQLite leakage can
still grow — and it is honest about being a ratchet rather than a proof.
