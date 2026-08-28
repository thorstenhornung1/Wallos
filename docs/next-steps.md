# Where the work stands

Written 2026-08-24, for picking the thread back up. Everything below is either
open or a decision somebody would otherwise have to rediscover. What is already
done lives in `CHANGELOG.md`; what is broken lives in the issue tracker. This
file is the part that is in neither.

## The state right now

Released: **5.8.6** (2026-08-28), `ghcr.io/thorstenhornung1/wallos:5.8.6`,
`sha256:6541d6ddf25bcc066107185d9aaca7fead33c914a3560c036fa3c6d6a41b65dd`.
466 tests on SQLite and PostgreSQL 14 and 18, all four gates green:
`dev/db-audit.sh`, `dev/sh-audit.sh`, `dev/write-audit.php`, and the Semgrep run.

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
guard — and the rest wait for the maintainer's reaction to it.

### 2. Upstream pull requests

`upstream-fix/totp-replay` went out 2026-08-28 as upstream PR #1181. Still
prepared as single-file branches, waiting on the reaction to it:
`upstream-fix/logout-token` (16 lines), `upstream-fix/disable-totp` (58),
`upstream-fix/verify-email` (+21/−3), `upstream-fix/password-reset` (+48/−9).

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

Still open: **SQLite sections 4, 5 and 7.** CI runs the whole suite on SQLite
first, but no human has driven those sections there since 5.7.0. That is a
larger gap than another PostgreSQL confirmation.

And **#104**: the test instance's currency key works, while the plan called it
invalid — one QA round spent about six hundred live calls on the assumption.
The four normative places now prescribe a check instead of asserting the state
(`bcabad9`), but the key itself still needs rotating, and only Thorsten can do
that through Portainer.

### 4. The parts of closed-enough issues that are still open

* **#92** — the repair migration ships, so existing orphans are gone and no new
  ones are created. Still open: a monotonic id on `user.id` (a table rebuild on
  the most referenced table in the application) and enforced foreign keys
  (switching on the pragma turns every existing violation into a hard error).
  Both deserve their own decision.
* **#87** — 23 discarded write results and 315 unchecked prepares remain, from
  66 and 368. The ratchet holds the number. The open design question is whether
  the boundary should offer a write returning rows-affected-or-null; 304 of the
  315 carry a statement that changes data, which is the number to decide against.

### 5. #85 and #86 — the container

#85 (the whole webroot is copied into the writable layer on every start) is
worth doing on its own and has no blast radius. #86 (running unprivileged) is
measured in detail in the issue: dcron is the hard blocker and `supercronic`
replaces it, `setcap` solves port 80 without breaking existing compose files,
and read-only root leaves 27 kB in the writable layer.

Two things worth knowing before that is picked up. The migration hazard the
issue describes — a volume owned `82:82` becoming unwritable under
`--user 1000:1000`, with the application swallowing it — **is no longer silent**:
`updatenextpayment.php` has no discarded write results left and six reporting
sites, so the cron records a failure, the counter survives the next success, and
the admin page warns. And the work is almost the same whether it is optional or
mandatory: every piece (supercronic, setcap, nginx paths in `/tmp`) is inert
when running as root, so `user:` in compose can be the only switch. Exactly one
line breaks today — `startup.sh:5` writes to `/var/log/startup.log` under
`set -euo pipefail`.

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
