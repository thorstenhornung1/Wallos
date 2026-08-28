# Test run 2026-08-28 — 5.8.6 on PostgreSQL, release check

Release check for the five changes 5.8.6 ships:
[#102](https://github.com/thorstenhornung1/Wallos/issues/102) (the admin page
names its database), [#103](https://github.com/thorstenhornung1/Wallos/issues/103)
(migration callers read the runner's answer),
[#114](https://github.com/thorstenhornung1/Wallos/issues/114) (progress bar
stays empty before the start date),
[#99](https://github.com/thorstenhornung1/Wallos/issues/99) (notification cron
loads only accounts with work) and
[#101](https://github.com/thorstenhornung1/Wallos/issues/101) (currency provider
failures are classified). The update was schema-neutral — no new migrations —
and the instance confirms that: 66 migrations, highest `000067`, unchanged from
5.8.5.

**Three findings.** The `add.php` insert path is broken on PostgreSQL whenever
no logo is supplied (new, not a 5.8.6 regression), the status-code half of the
#103 fix does not actually reach the caller in this image, and the live fixer
key's monthly quota is exhausted. Details below; none of the five shipped
changes regressed anything that was previously working.

## Environment

Read from the running instance, as section 9 of the plan requires — every row
below is command output, none is filled in by hand.

```sh
$ docker service inspect wallos-test_wallos \
    --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}'
ghcr.io/thorstenhornung1/wallos:5.8.6@sha256:6541d6ddf25bcc066107185d9aaca7fead33c914a3560c036fa3c6d6a41b65dd

$ $EXEC php -r 'include "/var/www/html/includes/version.php"; echo "Wallos $version\n";'
Wallos v5.8.6

$ docker exec $(docker ps -qf name=wallos-test_wallos) env | grep '^WALLOS_DB_'
WALLOS_DB_DRIVER=pgsql
WALLOS_DB_HOST=postgres
WALLOS_DB_NAME=wallos
WALLOS_DB_PASSWORD_FILE=/run/secrets/db_password
WALLOS_DB_PORT=5432
WALLOS_DB_SSLMODE=disable
WALLOS_DB_USER=wallos

$ $EXEC php -r 'require "/var/www/html/includes/database/connection.php";
                $d = wallos_database_connect();
                printf("%s | %s\n", $d->driver(), $d->scalar("SELECT version()"));
                printf("migrations: %d\n", (int) $d->scalar("SELECT COUNT(*) FROM migrations"));'
pgsql | PostgreSQL 18.6 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit
migrations: 66
```

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.6` |
| Digest | `sha256:6541d6ddf25bcc066107185d9aaca7fead33c914a3560c036fa3c6d6a41b65dd` |
| Version | `Wallos v5.8.6` |
| Driver, from the environment | `WALLOS_DB_DRIVER=pgsql` |
| Database, from the connection | PostgreSQL 18.6, dedicated, node-local volume |
| Schema | 42 tables, 66 migrations, highest `migrations/000067.php` — identical to 5.8.5, as a schema-neutral update should be |
| Platform | Docker Swarm, pinned to `docker-infra-3`; container started 2026-08-28 03:48:03 UTC |
| Accounts | `qaadmin` (admin, id 1), `admin` (id 113), `thorsten.hornung` (id 114, OIDC) |

All UI and endpoint tests ran as `qaadmin` through
`https://test.hornung-bn.de` with a curl cookie jar and the CSRF token from
`settings.php`, driven from `docker-infra-3`. Database checks went through
`docker exec` into the `postgres` container (peer auth), read-only except for
the fixtures named below, every one of which was removed in the same run.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| #102 admin page names the database (gate check) | **pass** | `Backend: PostgreSQL 18.6`, `Data: wallos@postgres:5432/wallos`, no default marker |
| #103 migrate.php, success path | **pass** | `No migrations to run.`, HTTP 200, CLI exit 0 |
| #103 migrate.php, failure path — honesty | **pass** | failure named, not recorded, later migrations skipped, retried next start |
| #103 migrate.php, failure path — status code | **fail** (finding 2) | HTTP **200** despite the failure: `headers already sent`; CLI exit **0** |
| #114 future start date keeps the bar empty | **pass** | start +1 day → `width: 0%`, start +50 days → `width: 0%` (pre-fix: 96 % and 33 %) |
| #114 a running subscription still shows progress | **pass** | 10 days into a 30-day cycle → `width: 33%` |
| #99 cron loads only due accounts, end to end | **pass** | due account passed the prefilter, mail delivered; workless run 64 ms, clean |
| #99 cron diagnostics on the admin page | **pass** | `Payment notifications: Succeeded … (daily at 09:00)`, run recorded ok |
| #101 provider failures are classified | **pass** | startup run recorded `quota is exhausted (HTTP 429)` plus the provider's own words |
| Subscription creation via `add.php` without a logo | **fail** (finding 1) | `bind message supplies 19 parameters, but prepared statement … requires 22` |
| Section 6 (currency live tests) | **skipped** (#104) | live secret `wallos_test_currency_api_key_v2` mounted; quota already exhausted |
| #102 negative path (`sqlite (default)`) | **not covered** | would require reconfiguring the service; covered by shipped tests |

## #102 — the gate check, first

The reason this panel exists: the instance ran on SQLite for three days while
three reports recorded PostgreSQL, and nothing in the application could have
shown the difference. The plan for this run said: if the panel shows
`sqlite (default)`, stop immediately.

It does not. With an administrator session on `admin.php`:

```html
<h2>Database</h2>
…
<strong>Backend:</strong> PostgreSQL 18.6
<strong>Data:</strong>    wallos@postgres:5432/wallos
```

Three properties, each checked:

* **The backend and its version** — `PostgreSQL 18.6`, which agrees with what
  the connection itself answers (`SELECT version()`, environment block above).
  The environment says what the instance was told; the panel and the connection
  say what it did. All three agree.
* **The origin** — `wallos@postgres:5432/wallos`, the actual DSN target minus
  the password. No credential appears anywhere in the panel.
* **The default marker is absent.** `WALLOS_DB_DRIVER` is set, so the panel
  does not carry the `(not configured — this is the default)` suffix. The
  negative path — an unconfigured instance showing `SQLite … (not configured)`
  — was **not covered** at runtime: producing it means removing the driver from
  the running service, which is exactly the incident this panel exists to
  prevent. It is covered by `tests/cases/database_diagnostics_test.php`.

Gate passed; the run continued.

## #103 — migrate.php reports the migration state

**Success path, both callers.** CLI and HTTP with an administrator session:

```
$ docker exec <wallos> php /var/www/html/endpoints/db/migrate.php
No migrations to run.
exit=0

$ curl -b <admin cookies> -w '\nHTTP=%{http_code}' …/endpoints/db/migrate.php
No migrations to run.
HTTP=200
```

**Failure path, exercised rather than trusted.** A deliberately failing
migration was planted — `migrations/000068.php` containing nothing but
`return false;`, so it touches no schema — then removed in the same test:

```
$ curl -b <admin cookies> -w '\nHTTP=%{http_code}' …/endpoints/db/migrate.php
Migration migrations/000068.php failed and was not recorded; it will be retried
on the next start. Later migrations were skipped.
<b>Warning</b>: http_response_code(): Cannot set response code - headers already
sent (output started at /var/www/html/includes/run_migrations.php:70) in
<b>/var/www/html/endpoints/db/migrate.php</b> on line <b>34</b>
Migration failed: 000068.php
HTTP=200
```

```
migrations table during the failed run:  66 rows, highest 000067  (unchanged)
after removing the fixture:              No migrations to run., HTTP 200, 66 rows
```

What holds and what does not, kept apart:

* **The runner's honesty holds.** The failure is named, the migration is not
  recorded, later migrations are skipped, the next start retries. The
  `error_log` line arrives. This is the 5.8.3 behaviour, intact.
* **The status code does not arrive — the stated point of the fix.** The commit
  argues that the caller most likely to be listening "reads status codes and
  not prose". That caller still reads **200**: `run_migrations.php` echoes the
  failure before `migrate.php` reaches `http_response_code(500)`, and this
  image runs `output_buffering=0`, so the headers are gone. The same run from
  the CLI — the other unattended caller — exits **0**:

  ```
  $ docker exec <wallos> php /var/www/html/endpoints/db/migrate.php   # fixture planted
  Migration migrations/000068.php failed and was not recorded; …
  Migration failed: 000068.php
  exit=0
  ```

  So a deployment script or cron watching either channel still sees success on
  a run that stopped halfway. The shipped test
  (`tests/cases/migration_callers_test.php`) is a textual gate on the source,
  which is why this passed CI: the check *is* in the code, it just fires after
  the output that disarms it. Finding 2 below; the fix is an `ob_start()`
  before the runner (migrate.php already knows the technique — the commit
  message names it for the other two callers) plus an explicit `exit(1)` for
  the CLI case.

## #114 — the progress bar before the start date

`endpoints/subscription/add.php` refused every attempt to create the fixture
(finding 1), so the three rows were planted directly in the database — the fix
under test lives in the render path (`includes/subscription_progress.php`,
called from `endpoints/subscriptions/get.php` and `subscriptions.php`), which
does not care how the row arrived. Monthly cycle, user `qaadmin`, today is
2026-08-28:

| fixture | start_date | next_payment | pre-fix showed | 5.8.6 shows |
| --- | --- | --- | --- | --- |
| QA-114-future-1d | 2026-08-29 (tomorrow) | 2026-08-29 | 96 % | **`width: 0%`** |
| QA-114-future-50d | 2026-10-17 (+50 days) | 2026-10-17 | 33 % | **`width: 0%`** |
| QA-114-control-running | 2026-08-18 (−10 days) | 2026-09-17 | — | **`width: 33%`** |

```
$ curl -b <cookies> …/endpoints/subscriptions/get.php | grep -o 'QA-114-[a-z0-9-]*\|width: [0-9]*%'
QA-114-future-1d        width: 0%
QA-114-control-running  width: 33%
QA-114-future-50d       width: 0%
```

The control row is what makes the two zeros meaningful: a bar that is empty
for a subscription that has not begun and at 33 % ten days into a thirty-day
cycle is the fix working, not a bar that stopped rendering. (The pre-fix
values are the ones the commit measured against the unchanged code, including
the 33 % this very date pair produced.)

The display setting `show_subscription_progress` was off for `qaadmin`, was
enabled through its endpoint for the test, and was set back to off afterwards.
All three rows were deleted through `endpoints/subscription/delete.php` and the
table verified empty of `QA-114-%` rows.

## #99 — the cron only processes accounts with work

The regression risk of the step-3 prefilter is not that it loads too much — it
is that it silently *loses* an account that has work. So the end-to-end case is
the test: email notifications were enabled for `qaadmin` (instance SMTP), a
subscription due tomorrow with `notify = 1` was planted, and the job run:

```
$ docker exec <wallos> php /var/www/html/endpoints/cronjobs/sendnotifications.php
Subscription: QA-99-Melde-Test
Next payment date: 2026-08-29
Current date: 2026-08-28
Difference: 1

Email Notifications sent
exit=0
```

Mailpit, empty before the run, holds exactly one message afterwards:

```
From: Wallos Test <wallos@test.hornung-bn.de>   To: qaadmin@test.hornung-bn.de
Subject: Wallos Notification
Snippet: The following subscriptions are up for renewal: QA-99-Melde-Test for €4.99 (Tomorro…
```

The due account passed the prefilter, resolved the instance transport and
delivered — through the same configuration chain sections 5.1–5.6 of the plan
verify. After the cleanup a control run went back to silence:

```
sendnotifications  ok  64 ms   (no account with notifications enabled)
sendnotifications  ok  115 ms  (control run after cleanup, silent)
```

**The cron diagnostics on the admin page** record all of it — the panel that
answers "is cron alive" without anyone reading a log:

```
Scheduled jobs: 12 jobs: 1 failed, 4 never reported.
Payment notifications:  Succeeded … (daily at 09:00).
Verification emails:    Succeeded 84 seconds ago (every 2 minutes): email verification is not required
Update check:           Succeeded 5 minutes ago (…): latest release is v5.8.6
```

The one failed job is `updateexchange` (next section); the four that never
reported are the weekly/monthly jobs whose first scheduled slot has not
arrived since the database was rebuilt on 2026-08-24, plus the schema
installation that a non-empty database correctly skips.

**Observation, kept separate.** The query-count shape itself — two loads for
everybody, four more only for accounts with work — is asserted by
`tests/cases/performance_test.php` and its textual gate, not re-measured here.
What this run adds is the half a test suite cannot: the deployed image, the
real transport, and an account with work arriving in the mailbox.

## #101 — the four failures, told apart

Verified without a single provoked provider call. `updateexchange` runs at
container startup, and the 5.8.6 deploy therefore produced one run of the job
minutes before this session began. Its recorded outcome, from `cron_runs` and
the container log:

```
[Wallos cron] ERROR job=updateexchange duration=846ms updated=1;
exchange rates for user 113 were not updated: The currency provider refused the
request: its quota is exhausted (HTTP 429). It said: You have exceeded the
maximum rate limitation allowed on your subscription plan. Please refer to the
"Rate Limits" section of the API Documentation for details.;
exchange rates for user 114 were not updated: …same…
```

The same text renders on the admin page under **Scheduled jobs → Exchange
rates**. This is the classification working on live data:

* Before 5.8.6 every one of these read `The currency provider could not be
  reached.` — the message the 2026-08-24 run showed as indistinguishable from
  an outage, which is what #101 reported.
* Now it says which of the four it was — **HTTP 429, quota exhausted** — and
  appends the provider's own sentence, which names the actual cause more
  precisely than any category could.

The other three classes (401/403 rejected key, 5xx provider fault, genuine
no-response) were **not** provoked — each would cost a provider call the leash
forbids — and rest on `tests/cases/provider_status_test.php`, which exercises
`wallos_provider_failure_message()` without a network. The rejected-key class
is additionally covered at runtime by the parallel SQLite run
(`test-results-2026-08-28-sqlite.md`), whose instance carries a deliberately
invalid key and can spend its refusals freely; this instance never ran
`updateexchange` during the session — the startup run quoted above predates it
and was the deploy's own.

## Skipped, and why

### Section 6 — the currency live tests (#104)

The plan requires checking which secret is mounted before section 6. Done,
from the manager:

```
$ docker service inspect wallos-test_wallos --format '{{json .Spec.TaskTemplate.ContainerSpec.Secrets}}'
… "SecretName":"wallos_test_currency_api_key_v2"  (target: currency_api_key)
… plus wallos_test_ai_api_key, wallos_test_db_password,
  wallos_test_oidc_client_secret, wallos_test_smtp_password
```

`wallos_test_currency_api_key_v2` is the **live** fixer.io key of #104 — a free
tier of ~100 calls a month, quota bound to the account, reset only at the next
billing period. Section 6 was therefore not run, no rate refresh was triggered,
and no mass conversion was performed. Only names and existence of secrets were
read, never contents.

The startup log settles what the key would have answered anyway: **the quota
is already exhausted** (HTTP 429, previous section). Any section-6 run would
have measured 429s and still burned whatever the provider counts against the
account. See finding 3.

### Also not covered

* **#102's negative path** (`SQLite … (not configured)`) — needs the driver
  removed from the running service; shipped tests cover the logic.
* **#103 through the restore path** (`import.php`) — unchanged from 2026-08-24:
  needs an archive from an older schema; `restore.php` returns before
  `import.php` on PostgreSQL. The migrate.php caller was exercised instead,
  and the status-code defect found there presumably does not apply to
  import.php, which builds its answer as JSON after reading the buffer — not
  re-verified here.
* **Plan sections 4, 5 and 7 in full** — this was a release check for five
  changes, not a full plan run. The update is schema-neutral and none of the
  five changes touches OIDC, SMTP resolution or account lifecycle; 5.1/5.6
  behaviour was incidentally re-confirmed on 5.8.6 by the #99 test above.

## Findings

### 1. `add.php` cannot insert a subscription without a logo on PostgreSQL — new issue

Every attempt to create the #114 fixture through the endpoint failed,
reproducibly:

```
$ curl -b <cookies> -H "X-CSRF-Token: …" -X POST …/endpoints/subscription/add.php \
    --data-urlencode "name=QA-114-future-50d" … (no logo, no logo-url)
Error: SQLSTATE[08P01]: <<Unknown error>>: 7 ERROR:  bind message supplies 19
parameters, but prepared statement "pdo_stmt_0000000a" requires 22
```

The cause is visible in the source. The INSERT names all 22 placeholders
unconditionally — including `:logo`, `:logoTextColor`, `:logoVariant` — but the
binds for those three run only `if ($logo != "")`
(`endpoints/subscription/add.php`, INSERT built at ~line 336, conditional bind
at ~line 376). SQLite treats an unbound named parameter as NULL, so upstream
and every SQLite instance never noticed; PostgreSQL counts, and refuses. The
UPDATE path is consistent (both SQL and binds share the same condition), and
`clone.php` binds everything unconditionally — the INSERT-without-logo path in
`add.php` is the one broken case found.

Impact: on a PostgreSQL instance, **creating any subscription without a logo
fails**, through the UI as much as through curl — the browser form submits an
empty `logo-url` and no file, which is exactly this request. Not a 5.8.6
regression: the code is unchanged since the #82 validation commit. That it
was not caught earlier says the endpoint's no-logo path was never exercised on
PostgreSQL — earlier runs seeded via the benchmark or carried existing rows.
Belongs in an issue; the fix is either binding the three parameters
unconditionally (NULL when empty) or making the column list conditional the
way the UPDATE already is.

### 2. #103's status code never reaches the caller in this image

Evidence and mechanism in the #103 section above. The runner is honest, the
prose is right, nothing is recorded that did not happen — but
`http_response_code(500)` fires after `run_migrations.php` has already echoed,
`output_buffering` is `0` in this image, and the HTTP answer stays **200**;
the CLI exit code stays **0**. Both unattended callers the commit names as its
audience therefore still see success on a failed run. Needs an `ob_start()`
around the runner include (as import.php does) and an explicit non-zero exit
for CLI; the textual test gate should become one that asserts the delivered
status.

### 3. The fixer quota is exhausted, and every container restart spends more

Operational, for the instance rather than the code. The startup run answered
HTTP 429 for users 113 and 114 (`updated=1` — the first account went through
before the refusals). Two consequences worth acting on:

* Exchange rates on the test instance are stale from here to the provider's
  next billing period, and `updateexchange` will show as the one failed job in
  the cron panel until then — correctly, and #101 now makes the panel say why.
* `updateexchange` runs **at startup**, so every redeploy of the stack spends
  provider calls with no one asking for rates. On a free tier of ~100 calls a
  month, deploy-frequency alone can consume the budget. Worth an issue or a
  stack-level answer (e.g. skipping the startup run when rates are fresh).

### 4. Minor: deleting a subscription answers `[i18n String Missing]`

```
$ curl … -d '{"id":7213}' …/endpoints/subscription/delete.php
{"success":true,"message":"[i18n String Missing]"}
```

`endpoints/subscription/delete.php` translates `subscription_deleted`, and the
key exists in neither `includes/i18n/en.php` nor `de.php`. Cosmetic — the
deletion itself works — but the string reaches the UI toast.

## Fixtures and cleanup

Everything this run created, and its removal, verified:

| Fixture | Created via | Removed via | Verified |
| --- | --- | --- | --- |
| `migrations/000068.php` (returns false) | shell in the container, twice | `rm`, same test | success path re-run: `No migrations to run.`, 66 rows |
| 3 × `QA-114-*` subscriptions (ids 7213–7215) | SQL insert (add.php broken, finding 1) | `endpoints/subscription/delete.php` | `QA-%` count 0 |
| `QA-99-Melde-Test` subscription (id 7216) | SQL insert | same endpoint | `QA-%` count 0 |
| `show_subscription_progress = 1` for user 1 | settings endpoint | same endpoint, back to `0` | settings row `1|0` |
| `email_notifications` row for user 1 | notifications endpoint | SQL delete (row did not exist before) | table count 0 |
| 1 captured mail in Mailpit | the #99 cron run | `DELETE /api/v1/messages` (box was empty before) | `total: 0` |
| Cookie jar and page dumps in `/tmp` on the node | curl session | `logout.php`, then `rm` | no `qa-*` files left |

The final state matches the state found, with two deliberate exceptions: the
`cron_runs` rows for `sendnotifications` now date from this run's manual
executions (the nightly schedule overwrites them tonight), and the instance's
remaining two subscriptions (`Test`, `Futurama`, user 113) were never touched.

## Conclusions, kept separate from the observations

* **All five shipped changes do what they claim on the deployed instance**,
  four of them verified end to end, #101 on live failure data the deploy
  itself produced. The gate check that motivated the release — a database
  panel that would have caught the three-day SQLite incident — is in place and
  correct.
* **The one partial failure (#103) is in the delivery, not the logic.** The
  distinction matters for the fix: nothing about the runner needs to change,
  only when the caller speaks relative to when the runner does.
* **PostgreSQL keeps finding code written against SQLite's tolerance.** The
  `add.php` bind mismatch is the same defect class as #89/#90/#91: SQLite
  forgives (unbound parameter → NULL), PostgreSQL counts. The existing Semgrep
  boundary would not catch this one — the code goes through the abstraction;
  it is the *contract* (bind everything you name) that differs. A grep for
  conditional binds against unconditional placeholders would.
