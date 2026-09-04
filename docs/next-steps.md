# Where the work stands

Written 2026-08-24, for picking the thread back up. Everything below is either
open or a decision somebody would otherwise have to rediscover. What is already
done lives in `CHANGELOG.md`; what is broken lives in the issue tracker. This
file is the part that is in neither.

## The state right now

Released: **5.9.0** (2026-08-31, as authorised),
`ghcr.io/thorstenhornung1/wallos:5.9.0`,
`sha256:f92e0d51bfd4bcd080bd85b18c07e81701210456f6e9a6ab95839db47d861a83`.
Milestone K whole, one settings row per user (#17), the union fetch (#9),
the self-updating PostgreSQL matrix (#80), the dormant upstream defects
\#126–#128, the QA round's four findings — and supercronic instead of dcron,
which the test instance's #106 counter caught firing every job twice.
Released ahead of the billing turn on Thorsten's decision, so Tuesday's
calibration measures this release: expected counter movement is exactly +1
per cron night. 529 tests on SQLite and PostgreSQL, all gates, e2e, the four
container modes booted, the release notes carry the #92 caveat. The test
instance follows the current release (operator decision 8308acc) and is
being redeployed to this tag.

**Unreleased on `main` since then:** the upstream 5.5.0 merge and two security
fixes it turned up — a remember-me token the 2FA login left un-revokable, and
the theme-cookie XSS on the registration page. Both are in `CHANGELOG.md`
under *Unreleased*; the next release notes want them, and the SSRF change
(standard accounts now need an explicit instance opt-in to reach allowlisted
private addresses, which is upstream's default) is an operator-visible
behaviour change that belongs in them too.

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

## Where this stands after the 2026-09-02 merge session

Written for picking the thread back up. The section below this one is the
state before this session; kept for the reasoning.

**Upstream 5.5.0 is merged** (`7697283`) and `main` carries it. The merge base
had moved: #1187 squashed `v5_6_0` into upstream `main`, so git fell back to
5.4.4 and nine of the twenty-four conflicts were content this fork already
had. `docs/upstream-candidates.md`, section "2026-09-02: the merge, and what
it found", holds the mechanics — including the one-line check that tells a
real conflict from a squash artefact, and the byte-level trap in the two files
with mixed line endings.

**The merge found a live defect in the fork.** `totp.php` issued a remember-me
token and never named it in `$_SESSION['token']`, which is the only thing
`logout.php` revokes — so an account with 2FA kept a usable token across a
logout while an account without 2FA did not. Upstream #1184 again, in the one
login path its fix had not touched. Fixed, with a gate over every
token-issuing path.

**And a second one, reachable without an account.** Upstream's own 5.5.0 XSS
fix for the theme cookies missed `registration.php`, which still wrote the
cookie into an inline script. Fixed in the fork (`7ddc45d`) and prepared as a
fourth upstream branch. The gate that found it also turned up
`passwordreset.php` and `verifyemail.php` reading both cookies unvalidated —
not injectable, fixed for uniformity.

**Four upstream branches now wait on Thorsten's send**, all rebased onto
`upstream/main` and all one file:

| branch | file | size |
|---|---|---|
| `upstream-fix/registration-theme-xss` | `registration.php` | +4/−3 |
| `upstream-fix/verify-email` | `verifyemail.php` | +24/−6 |
| `upstream-fix/password-reset` | `passwordreset.php` | +48/−9 |
| `upstream-fix/disable-totp` | `endpoints/user/disable_totp.php` | +58/−14 |

Re-verification was not a formality: all three older defects still exist on
`upstream/main`, but `verify-email` had to be rewritten. It redirected a
failure to `login.php?validated=false`, and upstream's `login.php` only reads
`validated == "true"` — the person would have landed on a page with nothing to
say. It now falls through to `verifyemail.php`'s own error box. A stray
reference to a fork issue number went with it.

533 tests green on SQLite and PostgreSQL, boundary audit unchanged (two files
improved during the merge), write audit at baseline.

**In order, next:**

1. **The send decision.** Four branches, ready. The
   nothing-without-Thorsten's-approval rule stands; the registration XSS is
   the natural first, being four lines of a shape the maintainer just wrote
   himself.
2. **The daily shadow migration** (shape approved 2026-08-31): a nightly copy
   of the production SQLite database → fork migration chain →
   `dev/migrate-to-pgsql.php` → verification + read-only boot smoke. Script
   `dev/shadow-migrate.sh` to build; the operator session wires the schedule
   and the production-backup access.
3. **The upstream distillate for #32**: a port branch on `upstream/main`
   carrying ONLY boundary → PostgreSQL backend → migration tool, as three
   reviewable PRs; open the #32 RFC conversation first. Once the distillate
   exists, point the shadow pipeline at it.
4. **Small backlog:** #129 (IPv6-less hosts), #130 (notification i18n), #131
   (CSV export), #132 (row alignment), the #87 design question (23 discarded
   results, 305 unchecked prepares, write-returning-rows decision),
   Performance #18/#19, the 4a note (an OIDC save without an oauth_settings
   row would persist discovery URLs — unverified).
5. **Milestone J stays parked** until PostgreSQL can be ported upstream —
   Thorsten's explicit call.

## B3, the OIDC roundtrip — mostly closed on 2026-09-03

It was never unblocked; it was **routed around**. The `oidc qa user` credential
stayed unavailable (the operator session does not hand out secrets, by project
rule), and the way through was that any Authentik account with application
access and a verified email does the job. Thorsten signed in as
`thorsten.hornung`. The account was **auto-provisioned** rather than linked to
an existing one — the username is Authentik's `preferred_username` and the
names come from the `name` claim — so the standard-user path is what got
tested, which is what B3 wanted anyway.

The lesson worth keeping: a blocked credential is not always a blocked test.
The old QA user is still worth repairing (read the real `username` attribute in
Authentik's Directory → Users and set the password there, which also settles
the "wrong object" hypothesis), but nothing waits on it.

**Green, live, on 5.9.0:**

* the sign-in itself, `require_email_verified` included
* **7.3, the RP-initiated logout** — the end-session request with
  `id_token_hint` was accepted and came back as
  `login.php?logged_out=1&state=…`. No 400.
* **the role model** — the Admin page is not reachable for the provisioned
  account. That the OIDC path grants no admin role was a test in the repo and
  is now an observation on a running instance.
* **#34/#35 re-confirmed** — the account came up in German.

**What it found:** `docs/test-instance.md` §7.2 listed #40 ("an auto-created
user always gets EUR") alongside #34/#35 as fixed in 5.6.0 and then declared
nothing in the section a current limitation. Only the language half was ever
fixed; `oidc_create_user.php` still hardcodes `$main_currency_id = 1`. On a EUR
instance the wrong mechanism and the right answer coincide, so the screenshot
that shows EUR proves nothing — a tester following the old text would have
ticked it off. Corrected in `ceda3e6`.

* **#123, walked in the field and green.** Signed in through OIDC, replaced the
  task with `docker service update --force wallos-test_wallos` (stop-first, so
  the new container brings up an empty `/tmp/wallos-sessions` and the PHP
  session is genuinely gone), reloaded — still signed in, so the session came
  back through `includes/remember_me.php` from the `wallos_login` cookie, which
  is the path that used to lose the id token — and signed out. Back at
  `login.php?logged_out=1&state=…`, no 400. The token survives the container it
  was issued in: `backchannel.php:121` records it in `oidc_sessions.id_token`
  (migration 000069) and `remember_me.php:85-99` reads it back by
  `login_token`. Recorded on the issue itself; it had been closed on the code
  fix alone, with no instance to prove it on.

  The preconditions were checked in code *before* the experiment, and that was
  the point: had sessions survived a task replacement, the logout would have
  succeeded for the ordinary reason and we would have recorded a pass that
  proved nothing.

**With that, section 7 has nothing red left.** What the 2026-08-28 OIDC run
filed as "not coverable without operator action" is covered, except 7.4
(back-channel logout, which needs the provider to initiate) and the parts of
7.5 that need a heavily configured account.

**On who ran the restart:** the operator session's permission classifier had
blocked cluster writes, and it asked this session to run the command instead.
That is how a permission decision gets laundered across sessions rather than
respected, so this session declined and handed it to Thorsten — who then
instructed the operator session directly. His instruction is his own
authorisation; a peer's request to route around its own block is not. Worth
keeping as the rule for next time, because the outcome looked identical and the
reasoning is the only thing that separates them.

One thing the merge improved for exactly this: the OIDC user-info call now
reports its HTTP status and curl error into the fork's OIDC diagnostics, which
had them for the token exchange only.

## Where this stood after 2026-09-02, before the merge

Written for picking the thread back up after a context clear. The section
below this one is the 2026-08-30 plan it executes; kept for the reasoning.

**Done and verified since that plan was written:** 5.9.0 released
(2026-08-31, ahead of the billing turn on Thorsten's call), deployed on the
test instance, and it measured itself: the 2026-09-01 calibration confirmed
all four expectations (counter rollover, one union call per cron night for
all users, status=ok with the failure history frozen by design) — **#106
closed, milestone A closed**, no code change needed. The GitHub release
objects for 5.8.9/5.9.0 were missing (checkforupdates read v5.8.8 as
latest); both published, and the workflow now creates the release object on
every tag (711ca86) — verified live by the instance's next checker run.
Milestones B, D, E, F, H, I, K are closed; open remain A′s successors only:
C, G, J, Performance (2), Price history.

**Upstream moved on 2026-09-01** — both our PRs merged, release 5.5.0, base
is `upstream/main` now. The full new situation, the landmines for the 5.5.0
merge (migration renumbering ≥000073, his ssrf_helper vs our #126), and the
re-verification duty for the three remaining single-file branches live in
`docs/upstream-candidates.md`, section "2026-09-01: the maintainer moved".
Sending anything still needs Thorsten's explicit approval.

**In order, next:**

1. **Merge upstream 5.5.0 into the fork** (successor to b373668) — with the
   mapped landmines from upstream-candidates.md.
2. **Re-verify the three prepared single-file branches against 5.5.0**, then
   ask Thorsten for the send.
3. **The daily shadow migration** (Thorsten approved the shape 2026-08-31):
   a nightly copy of the production SQLite database → fork migration chain →
   `dev/migrate-to-pgsql.php` → verification + read-only boot smoke. Script
   `dev/shadow-migrate.sh` to build; the operator session wires the schedule
   and the production-backup access.
4. **The upstream distillate for #32**: a port branch on `upstream/main`
   carrying ONLY boundary → PostgreSQL backend → migration tool, as three
   reviewable PRs; open the #32 RFC conversation first. Once the distillate
   exists, point the shadow pipeline at it.
5. **Small backlog:** #129 (IPv6-less hosts), #130 (notification i18n), #131
   (CSV export), #132 (row alignment), the #87 design question (23 discarded
   results, 305 unchecked prepares, write-returning-rows decision),
   Performance #18/#19, the 4a note (an OIDC save without an oauth_settings
   row would persist discovery URLs — unverified).
6. **Milestone J stays parked** until PostgreSQL can be ported upstream —
   Thorsten's explicit call.

**One loose end outside the repo:** the OIDC QA roundtrip (B3) on the test
instance is credential-blocked — two "Invalid password" rejections of a
value whose transmission is proven byte-exact (sha256 prefix afd21957; the
operator session holds the credential file). Authentik is healthy again
(the CephFS incident of 2026-08-31 is fixed and hardened). Waiting on
Thorsten's own private-window counter-probe as user `oidc qa user`; the
likely cause is the password being set on a different Authentik object than
the one the login form matches. `require_email_verified` is active on the
instance and waits as the next hurdle after the password. Coordination runs
via the operator session ("diagnose-belegparser-llm-config"), which owns
the test instance and its infrastructure.

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
  66 and 368, and since 2026-09-04 a third number: **15 unreported writes**, a
  write nobody read followed on the same branch by a response claiming success.
  The ratchet holds all three.

  The open design question is whether the boundary should offer a write
  returning rows-affected-or-null. **The number to decide against is not 305.**
  This entry used to say "nearly all of the remainder carry a statement that
  changes data", which came from the audit's own classifier — and that
  classifier could not follow `$sql = "..."; $db->prepare($sql)`, which is 282
  of 459 call sites here. It answered "unknown" for almost all of them, and
  unknown counted as a write. Corrected: **94 of the 305 carry a write, 211
  only read.** Two independent measurements agree on that figure.

  A SELECT whose prepare is unchecked is a different and milder defect: on PHP
  8.3 `false->bindValue()` is a fatal, so it crashes loudly rather than
  reporting a success that did not happen. The 15 are the number issue #87 is
  actually about.

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
