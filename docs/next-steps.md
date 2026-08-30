# Where the work stands

Written 2026-08-24, for picking the thread back up. Everything below is either
open or a decision somebody would otherwise have to rediscover. What is already
done lives in `CHANGELOG.md`; what is broken lives in the issue tracker. This
file is the part that is in neither.

## The state right now

Released: **5.8.9** (2026-08-30, as authorised),
`ghcr.io/thorstenhornung1/wallos:5.8.9`,
`sha256:1234d1e28f0f41a94995ab68eae67d9554b458d53c1e9fad58e97ab75c5b6e5e`.
It carries the #106 request counter (migration 000069) and the #124 secret
clear. 489 tests on SQLite and PostgreSQL 14 locally, the CI run on the tag
green, all five gates green: `dev/db-audit.sh`, `dev/sh-audit.sh`,
`dev/write-audit.php`, the Semgrep run, and `dev/js-audit.sh`.

The test instance is still pinned to **5.8.8**; moving the pin is the QA
operator's call. Worth making before the billing turn of **2026-09-01 (a
Tuesday)** — the counter can only observe the reset if it is running when
the reset happens; missed, the next window is 2026-10-01.

## What 2026-08-24 turned up, after this file was first written

The test instance was found running the 5.8.5 image on **SQLite**, with a
configured PostgreSQL running beside it, unused. A second claim — that the
database sat at migration 000062, two releases behind — was withdrawn: it came
from session memory, and the secured volume showed an empty, orphaned SQLite
file at 000057 predating the PostgreSQL switch, on a node the service was not
pinned to. Worth remembering as its own lesson: the figure survived three
messages and reached two issue descriptions before anyone read the disk.

The three PostgreSQL test reports in this directory are **not** affected. That
was checked before the instance was rebuilt, and `db/pre-pgsql-20260819.db` —
a backup taken the day the instance moved to PostgreSQL — settles it: the runs
of 19, 20 and 21 August were real PostgreSQL runs. The drift happened after
them.

Two defects came out of asking why nobody noticed for three days. #103 is
fixed (`37d1d2b`); #102 is open:

* **#102** — nothing in the application says which database it is running on.
  `wallos_database_configuration()` has no caller outside `includes/`; SQLite
  and PostgreSQL are indistinguishable through the web interface. This is why a
  test report can only assert its backend, never show it.
* **#103** — *fixed.* A failed migration stopped the run and told nobody. The runner sets
  `$migrationFailure` for "the caller to read", and no caller reads it:
  `migrate.php` answers 200 regardless, `import.php` answers
  `success: true` after a restore that did not finish, and both discard the
  runner's output with `ob_end_clean()`. This is the same defect as #97, #100
  and #101, in the one endpoint whose entire purpose is the outcome. It rests
  on the call sites and on a fixture in the repository, not on that instance.

**#103 is the most valuable thing here.** It is the mechanism by which an
instance can serve pages on a schema older than its code without anyone
learning of it. Whether that has already happened in the wild is open — the
one case that looked like proof did not hold up.

## Two decisions that do not follow from the code

**Production stays on upstream.** The production instance (abo.hornung-bn.de)
was raised from `bellamy/wallos:5.4.2` to `5.4.4` — the security update only —
and is deliberately **not** moving to this fork. In Thorsten's words: the fork
is still too experimental, and the way to get features into production is a pull
request the upstream maintainer merges.

The consequence for this repository: **a change is worth more when it can travel
alone.** Correctness fixes against upstream code are the valuable output;
anything that only makes sense on top of the database boundary is fork-internal
for now. `docs/fork-and-upstream.md` and issue #32 hold the longer form.

**The test instance is not ours to touch.** `test.hornung-bn.de` (`wallos-test`
stack, PostgreSQL 18, pinned to docker-infra-3) belongs to whoever is running
QA. Local work happens in `dev/compose.yaml` instead — see below.

## The road from here (2026-08-30, Thorsten's direction)

Three closing moves, then a release; the big feature work waits behind a
named condition.

1. **Tuesday 2026-09-01: calibrate #106 against the observed quota reset,
   close it, and milestone A with it. Then release 5.9.0** — milestone K,
   #17, #9, the CI hardening below; the CHANGELOG must carry the #92 caveat
   (already-inherited rows cannot be told apart; check accounts created
   shortly after a deletion). The real-data upgrade probe passed 2026-08-30:
   the 000070–72 chain ran clean on a copy of the dev instance's database,
   every count preserved, idempotent on retry.
2. **PostgreSQL, closed hard**: #20 and #21 are closed as done in substance;
   #80 (the version matrix in CI) is the remaining piece and is in work on
   `ci/postgres-matrix`. After it, milestone E is history and what is left
   of PostgreSQL is the upstream conversation (#32).
3. **Rootless, closed hard**: milestone K is closed; the four container
   modes are being pinned as an executable gate on `ci/container-modes` so
   they cannot regress silently.
4. **Fixer, closed**: #9 landed (union fetch — one shared-credential request
   per scheduled refresh, whatever the users' lists). After Tuesday's
   calibration, decide whether the deferred per-user failure line on the
   settings page is still worth building.
5. **Upstream**: #1181 and #1184 are watched; three single-file branches
   stay prepared for the maintainer's first reaction. The goal behind #32 is
   getting the PostgreSQL work portable upstream.
6. **Parked until PostgreSQL can be ported upstream — Thorsten's explicit
   call**: the big new feature blocks, milestone J (families, households,
   shared workspaces) first among them.

The night of 2026-08-30/31 closed most of this list: #80 landed (the CI
matrix asks PostgreSQL's own support range; milestone E is history), the
four container modes run as an executable gate on every push — its first
real run caught db/wallos.db riding COPY . . into locally built images —
and the local QA round proved the day's changes on a live instance while
catching four defects of our own, all fixed the same night (a vacuum-green
e2e check among them). An upstream-issue triage of all 62 open issues found
seven defects dormant in this fork; #126 (SSRF allowlist order), #127
(swallowed logo failures) and #128 (webhooks posting JSON unlabelled) are
fixed, #129–#132 (IPv6-less hosts, notification i18n, CSV export, row
alignment) wait as the small backlog. Upstream itself is idle: nothing
merged there since our v5_6_0 merge; the single portable find (#1183's
guards) is in. The test instance holds a recorded #106 baseline
(local_calls=2, quota mid-429) for Tuesday's calibration; three QA access
decisions wait on Thorsten (QA account, admin access, an Authentik QA
login).

## Next, in the order I would take them

### 1. Upstream pull requests — the highest-value work left

`docs/upstream-candidates.md` holds the verified list, written 2026-08-24 by
checking each candidate against the upstream tree. Read it before proposing
anything: **four PRs from this fork are already merged**, and the comparison
base is `upstream/v5_6_0`, not `upstream/main`.

The first three, in order, are `totp.php` (a replay guard that never runs —
one word missing from a SELECT), `logout.php` (`$userId` never assigned, so
every logout leaves a valid token), and `migrations/000016.php` (one
`finalize()`, wrong in every installation ever made).

The base question is settled in `docs/upstream-candidates.md`: `v5_6_0`, not
`main`. The first PR went out 2026-08-28 — **#1181**, the `totp.php` replay
guard. After two days without any reaction, Thorsten approved sending the next
one: **#1184**, the `logout.php` token delete, went out 2026-08-30.

### 2. Upstream pull requests

Open upstream: **#1181** (`totp-replay`, 2026-08-28) and **#1184**
(`logout-token`, 2026-08-30). Still prepared as single-file branches:
`upstream-fix/disable-totp` (58 lines), `upstream-fix/verify-email` (+21/−3),
`upstream-fix/password-reset` (+48/−9).

Beyond those, the candidates from the 5.8.x work that carry no PostgreSQL
dependency, roughly by how much a reviewer has to take on trust:

* **#95, the eighteen dead buttons.** Every installation has them, the diff is
  mechanical, and the gate that finds them is 40 lines. Best first PR.
* **The migration runner.** Migration 000016 has recorded itself as applied with
  its work unfinished in every installation ever made. That is probably the
  single most valuable thing this fork has found for upstream, and it needs the
  most explaining.
* **#93** (deleting a referenced payment method), **#97** (refusals answering
  200), **#101** once it is fixed.

Not portable: the archive, the PostgreSQL baseline, the upgrade test, the
boundary itself. Those are the #32 conversation.

### 3. QA — done, with one gap left over

The 2026-08-24 run closed all three open sections (8.4 backup/restore on
PostgreSQL, migration 000067 against orphans, section 6): see
`docs/test-results-2026-08-24.md`. It also found that the instance had been on
SQLite since 2026-08-22 while three reports called it PostgreSQL, which is
where #102 comes from.

Sections 4 and 5 were driven on SQLite on 2026-08-28
(`docs/test-results-2026-08-28-sqlite.md`): all pass, and section 4 found
#119 — one missing comma that had kept every admin-page button dead since
5.8.1, invisible to a suite that never parses the JavaScript. Still open:
**section 7 (OIDC)**, which needs an Authentik to talk to.

And **#104** is closed — **without rotating the key**, by decision of
2026-08-28. The documentation half was done since `bcabad9` (check what is
mounted, never assert it); the rotation half was dropped deliberately: the
guardrails are layered now (`--rates` opt-in, the #117 startup skip, per-run
failure caching, hard QA fences), the quota proved to recover in a window
rather than at the month's turn, and the shared live key is accepted as the
instance's working state. The larger half of **#106** landed 2026-08-30
(`caebe32`): Wallos counts its own provider requests per calendar month for
both providers — fixer.io's total silence was the missing half of #104 — and
the settings page states exhaustion, the local count and when rates last
refreshed. What remains is calibration: the turn of 2026-09-01 shows whether
the provider's billing period agrees with the calendar month and where the
warning threshold should sit. The issue stays open for exactly that.

### 4. The parts of closed-enough issues that are still open

* **#87** — 23 discarded write results and 305 unchecked prepares remain, from
  66 and 368. The ratchet holds the number. The open design question is whether
  the boundary should offer a write returning rows-affected-or-null; nearly all
  of the remainder carry a statement that changes data, which is the number to
  decide against.

### 5. Milestone K is closed (2026-08-30)

#85, #86, #92 and #125 all landed unreleased on main in one session: the
container's writes are bounded, `user:` and a read-only root work with one
tmpfs at /tmp, account ids are monotonic, declared foreign keys are enforced
on both backends, and the dead nginx configuration is gone. The operator
guidance lives in `docs/switching-to-this-fork.md`.

**The next release notes must carry the #92 caveat**: where an account id was
already reused before the fix, the inherited rows belong to a live account and
no query can tell them apart — anyone who deleted an account and created one
shortly after should review what that account owns. The text is in commit
45abf4a. The same release wants a version bump that says "migrations and
container semantics changed", not a patch number.

## Working locally

`dev/compose.yaml` brings up the container, PostgreSQL and Mailpit. The
container defaults to SQLite; to point it at the PostgreSQL service, add an
override with `WALLOS_DB_DRIVER: pgsql`, `WALLOS_DB_HOST: postgres` and the
credentials from the compose file (`wallos`/`wallos-dev`). That override is not
in the repository on purpose — it is a local choice, not a project default.

```sh
dev/test.sh                    # the suite on SQLite; builds its image once
dev/test.sh archive            # one group
dev/db-audit.sh                # SQLite boundary ratchet
php dev/write-audit.php        # unchecked writes ratchet, --report for detail
dev/benchmark.sh --base http://localhost:8383 --user <u> --password '<p>'
```

For the suite against PostgreSQL, set `WALLOS_TEST_DRIVER=pgsql` plus
`WALLOS_TEST_DB_*` and run `php tests/run.php` in a container that has
`pdo_pgsql` and `zip` — `dev/test.sh` builds exactly that image.

## Two habits worth keeping

**The textual gates match on words, including in comments.** A sentence
explaining why SQLite's schema table is not used counts as using it, and "the
same reasoning as tablesWithColumn()" reads to the alias gate as an unquoted
mixed-case alias. Both times the fix was to rewrite the comment. Blunt in the
safe direction, but this project writes its reasoning down, so it comes up.

**A gate seen only in its green state is not a gate.** Every one added here was
checked by breaking the thing it guards: an id restored to shadow its handler, a
migration returning false, `AUTOINCREMENT` appended to an `ALTER TABLE` that
PostgreSQL rejects. Twice that turned up a defect in the gate itself — the write
audit reported thirteen checked writes as unchecked because a ternary's colon
looked like a statement boundary, and a repair migration emptied a fresh
installation because default rows are seeded against user 1 before user 1
exists. Neither would have been found by watching them pass.
