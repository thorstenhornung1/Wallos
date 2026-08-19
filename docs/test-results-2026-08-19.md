# Test run 2026-08-19 — PostgreSQL, Docker Swarm

Execution of [`docs/test-instance.md`](test-instance.md) against a **fresh
PostgreSQL instance** of 5.8.0. Section 8.1 passed. Two defects found, both
filed: [#89](https://github.com/thorstenhornung1/Wallos/issues/89) and
[#90](https://github.com/thorstenhornung1/Wallos/issues/90).

The instance was rebuilt for this run rather than migrated, so section 8.2 does
not apply and its foreign-key refusal was never exercised.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.0@sha256:d7a1e1c5cbb6af0d24698878721e1577bced9ddc04744f760aaff67dd188db8d` |
| Database | PostgreSQL 18.6, dedicated to this instance, node-local volume |
| Platform | Docker Swarm, task and database both pinned to `docker-infra-3` |
| Ingress | Traefik v3.6, `test.hornung-bn.de` / `mail.test.hornung-bn.de` |
| Accounts | `dummy` (admin, id 1), `dummy2` (id 2) |

```
$ php -r 'include "/var/www/html/includes/version.php"; echo "Wallos $version\n";'
Wallos v5.8.0
```

**Deviation from the plan:** `WALLOS_DB_SSLMODE` is `disable`, the plan says
`prefer`. Functionally identical here — the `postgres:18-alpine` image serves no
TLS, so `prefer` falls back to plaintext on the same overlay network.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| 8.1 fresh PostgreSQL instance | **pass** | `pgsql`, 42 tables, 62 migrations |
| 4 first accounts | **pass**, with findings | `dummy` holds `admin`/`local` |
| 5.1 instance SMTP with nothing configured | **pass** | mail from `wallos@test.hornung-bn.de` |
| 5.2 secret never reaches the browser | **pass** (2 of 3 pages) | `settings.php`, `admin.php` clean; `index.php` **not covered** |
| 5.3 environment-managed fields | **pass** | `admin` row stays empty while mail is sent |
| 5.4 a user inherits without being given anything | **pass** | `dummy2` receives via the instance sender |
| 5.5 a user can still run their own transport | **pass** | same recipient, sender `user2@…` |
| 5.6 notifications actually go out | **blocked** | the cron dies before it sends → [#90](https://github.com/thorstenhornung1/Wallos/issues/90) |
| 5.7 a broken secret file does not fall back | **pass** | send refused, path named, no mail |
| 6 load | **not completed** | the benchmark writes to the wrong database → [#91](https://github.com/thorstenhornung1/Wallos/issues/91) |
| 7.4 back-channel logout, refusal path | **pass** | bare `invalid_request`, reason only in the log |
| 7.5 OIDC secret not in the admin API | **pass** | `client_secret_set: true`, 0 hits |
| 7.1–7.3, 7.4 end to end | **not covered** | needs a browser session against Authentik |
| 5.8 cron jobs run clean | **fail** | fatal in `sendnotifications` → [#90](https://github.com/thorstenhornung1/Wallos/issues/90) |
| account deletion removes every row | **pass** | 336 rows → 0, no orphans |

## Details

### 8.1 — the backend is genuinely PostgreSQL

```
driver:     pgsql
tables:     42
migrations: 62
```

Exactly the expected values. The baseline installed itself on first start:

```
PostgreSQL database is empty. Applying the baseline schema...
Baseline schema applied.
No migrations to run.
```

Two independent cross-checks that nothing fell back to SQLite:

* 21 sequences exist in `information_schema.sequences` — SQLite has none.
* `wallos.db` carries mtime 07:23:53 while the container started at 07:27:14,
  and the mtime did not advance across 5½ minutes of operation including a cron
  run. The checksum differs from the backup taken minutes earlier, but that
  proves nothing: `sqlite3 .backup` rewrites page by page and never produces a
  byte-identical copy.

### 4 — accounts, and why the first one must be local

```
 id | username | rolle   | quelle
  1 | dummy    | admin   | local
  2 | dummy2   | (keine) | -
```

`wallos_claim_first_admin()` is called only from `registration.php`, deliberately
not from OIDC provisioning. Signing in through Authentik first would have left
the installation with no administrator at all.

**Observation.** `main_currency` takes a currency **code**, not an id. Passing
`1` was rejected cleanly as an invalid currency. This is the 5.8.0 fix working:
before it, a failed `array_search` returning `false` was read as index `0` and
registration continued with the wrong currency, then wrote NULL into a NOT NULL
column with a foreign key on it — which on PostgreSQL is a hard constraint error
rather than a silently wrong value.

**Observation.** An account was created with the two-character password `xy`.
There appears to be no minimum length.

### 5.1 / 5.3 — configuration lives outside the database

Mailbox after the test send:

```
from Wallos Test <wallos@test.hornung-bn.de>  to dummy@example.com
subject: Wallos Benachrichtigung
```

While that mail was on its way:

```
 smtp_address | smtp_port | from_email
--------------+-----------+------------
              |       587 |
```

And the user's own row, saved in `instance` mode:

```
 user_id | enabled | smtp_address | smtp_port | from_email
       1 |       1 |              |       587 |
```

Nothing from the instance transport is copied into either row.

### 5.4 / 5.5 — inheritance and isolation

```
user2@test.hornung-bn.de   ->  dummy2@example.com     (5.5, own transport)
wallos@test.hornung-bn.de  ->  dummy2@example.com     (5.4, inherited)
wallos@test.hornung-bn.de  ->  dummy@example.com      (5.1, admin)
```

**Note for the plan.** The *test* endpoint in `custom` mode reads the SMTP fields
from the request, not from the saved row — it tests the form before it is saved.
A call carrying only `smtpmode: custom` fails with "fill all fields". Correct
behaviour, but it costs a round trip to discover.

### 5.2 — what was and was not covered

```
  settings.php    204105 bytes | smtp_pw:0 oidc_secret:0 db_pw:0
  admin.php        98120 bytes | smtp_pw:0 oidc_secret:0 db_pw:0
  index.php            0 bytes | smtp_pw:0 oidc_secret:0 db_pw:0
```

The grep compared against the contents of `/run/secrets/*` inside the same
command, so no secret was ever printed.

**`index.php` is not covered.** Zero bytes means the page never rendered — the
count proves nothing, exactly as the plan warns. Recording it as a third pass
would repeat the error this plan already documents from the previous run.

The OIDC admin API was also clean (`getoidcsettings.php`, 0 hits), which covers
part of the 5.8.0 fix that kept `OIDC_CLIENT_SECRET_FILE` out of JSON and HTML.

### 5.8 — one of five cron jobs is fatal

```
  sendnotifications                warnings/errors: 1
      Fatal error: Uncaught TypeError: computeAmountNeededInPeriod():
      Argument #4 ($database) must be of type SQLite3, WallosPgsqlDatabase given
  sendcancellationnotifications    warnings/errors: 0
  updateexchange                   warnings/errors: 0
  sendverificationemails           warnings/errors: 0
  sendresetpasswordemails          warnings/errors: 0
```

The same cause reaches two more places, both measured rather than inferred:

```
stats.php                                62969 bytes | TypeError: 1
api/subscriptions/get_period_budget.php    591 bytes | TypeError: 1
```

Filed as [#90](https://github.com/thorstenhornung1/Wallos/issues/90).

### Account deletion — every row, verified twice

Before, for four throwaway accounts:

```
  categories       17 rows each        currencies       34 rows each
  household         1 row each         payment_methods  31 rows each
  settings          1 row each
  --- 336 rows total ---
```

Deleted through `endpoints/admin/deleteuser.php` with a real administrator
session and CSRF token. After:

```
  --- 0 rows for ids 3,4,5,6 ---
  --- orphans across ALL tables with user_id: 0 ---
```

The second query is the one that matters. The first only checks the four known
ids and would pass even if a deletion had left rows under a different id. The
orphan sweep looks for rows without a matching user at all — which is the shape
of the defect 5.8.0 fixed, where twelve tables were skipped while the endpoint
reported success.

Worth noting: on PostgreSQL the old defect could not have passed silently in the
first place. Deleting the user row before its children violates a foreign key,
so the database would have refused it.

### 7.4 / 7.5 — what could be checked without a browser

**Back-channel logout refuses malformed input, and says nothing about why.**

```
leerer Body            -> HTTP 400  {"error":"invalid_request"}
logout_token=garbage   -> HTTP 400  {"error":"invalid_request"}
logout_token=a.b.c     -> HTTP 400  {"error":"invalid_request"}
GET instead of POST    -> HTTP 400  {"error":"invalid_request"}
```

The reason appears only in the container log:

```
NOTICE: PHP message: Wallos OIDC back-channel logout rejected: malformed_token
```

That is the behaviour the plan describes: a bare `invalid_request` to the
caller, the detail kept server-side. The end-to-end half — a real signed token
from Authentik — is **not covered**, it needs the provider to end a session.

**The OIDC client secret does not reach the admin API.**

```
api/admin/get_oidc_settings.php   1108 bytes | hits for the secret: 0
  "client_secret_set": true
  "client_secret": "OIDC_CLIENT_SECRET_FILE"
```

The API reports the *origin* of the secret rather than its value, which is more
useful to an administrator than a boolean alone.

**Correction to an earlier entry in this report.** The first attempt queried
`endpoints/admin/getoidcsettings.php`. The path in the plan is
`api/admin/get_oidc_settings.php`, and it exists — the earlier zero was measured
against the wrong endpoint and proved nothing about the one the plan names. Both
are clean, but only the second is the test.

**Revocation reaches endpoints — confirmed in code, not at runtime.**
`includes/connect_endpoint.php:34-35` calls
`wallos_oidc_require_valid_session($db)` from the shared bootstrap, so all 112
endpoints pass through it rather than only the page-rendering path. The runtime
check in the plan needs an OIDC browser session and is **not covered** here.

### Observations about the test tooling itself

* **`dev/benchmark.sh` puts the password on the command line.** It is visible to
  anyone who can run `ps` inside the container:

  ```
  sh dev/benchmark.sh --base http://127.0.0.1 --user dummy --password <plaintext>
  ```

  Reading it from an environment variable, a file, or standard input would cost
  nothing and remove a credential from the process table.

* **The rates cron dominates the benchmark when the currency key is invalid.**
  The plan prescribes a deliberately invalid key so no test run spends real
  quota, then measures `updateexchange` five times. Each run waits for the
  provider to time out, so the section that takes ~230 ms in the plan's table
  took over nine minutes here. The measurement is not wrong, but it measures the
  timeout rather than the code, and the plan does not warn about it.

### 5.6 — blocked, not failed

The notification cron cannot deliver anything on PostgreSQL. The fatal is at
`sendnotifications.php:252`; the first `$mail->send()` is at line 367. Execution
never reaches it, so there is no partial delivery to measure.

This is a consequence of [#90](https://github.com/thorstenhornung1/Wallos/issues/90),
not a separate defect. Recording it as a failed test would suggest two problems
where there is one.

### 5.7 — the one that matters

With `WALLOS_SMTP_PASSWORD_FILE` pointed at a file that does not exist:

```
  Pfad: /run/secrets/does-not-exist | readable: no
  test -> {"success":false,"message":"Secret file is not readable: /run/secrets/does-not-exist"}
  Mailpit: 0 messages
```

Three properties hold at once, and each is missing from plenty of applications:
the send **refuses** rather than attempting and failing, the message **names the
path**, and there is **no silent fallback** to a value in the database.

Restoring the path made delivery work again in the same run — one message in
Mailpit, so the refusal was about the secret and not about the transport.

### 6 — not completed, and the reason is a defect in the tooling

The benchmark ran for 24 minutes and produced no output. Two independent causes,
both filed as [#91](https://github.com/thorstenhornung1/Wallos/issues/91):

**It writes to the wrong database.** `seed()` goes through the abstraction and
reached PostgreSQL; `set_own_subscriptions()`, `enable_notifications()` and
`cleanup_bench()` open `new SQLite3("/var/www/html/db/wallos.db")` directly. So
the entries column would have measured pages against data that was never
inserted into the database those pages read.

**The rates cron measures a timeout.** The plan prescribes a deliberately invalid
currency key, then measures `updateexchange` five times per tier. Each run waits
for the provider. One tier alone ran for over eleven minutes.

After the run was stopped, PostgreSQL still held 102 users and 1000
subscriptions — `cleanup_bench()` had deleted `seed-%` rows from SQLite, found
none, and the script would have printed `Seeded data removed.`

### Account deletion at scale — 100 accounts

Cleaning up after the benchmark turned into a second, larger run of the same
test. Every seeded account was removed through `wallos_delete_user()`:

```
  accounts to delete: 100
  deleted: 100   failed: 0   duration: 2.2 s
  --- remaining ---
  users:          2
  subscriptions:  0
  orphans total:  0
```

100 accounts, each with roughly 84 child rows, in 2.2 seconds with no failure
and no orphan. The transaction and the child-first ordering hold at scale, not
only on a single account.

## Gaps in the test plan itself

Recorded because the plan asks for it, and because each of these would let a
broken thing look tested.

* **Nothing exercises the PostgreSQL path of the dev tooling.** Sections 6 and
  the e2e script are written as if the backend does not matter. On PostgreSQL
  both write to SQLite and report success. The plan should either state that
  section 6 is SQLite-only, or the tooling should be fixed — but the current
  combination produces numbers that look valid and are not.
* **Section 5.6 has no precondition check.** It assumes the cron can run. A line
  telling the reader to run the job once and look for a fatal *before* seeding a
  subscription would have saved the whole setup.
* **The invalid currency key and the rates benchmark contradict each other.**
  Section 5 prescribes the key, section 6 measures the job that uses it. Each is
  reasonable alone.
* **Section 7.5's deletion test asks for a heavily configured account** (2FA,
  several notification channels, a colour theme, a subscription). The accounts
  deleted here had five tables between them, so tables like `login_tokens` and
  the 2FA tables were **not covered** — and `login_tokens` is precisely the one
  the changelog calls out. A throwaway account that has merely been created does
  not test what this section is for.
* **No test covers registration being closed.** `registrations_open` and
  `max_users` are settings with a security purpose and no coverage anywhere in
  the plan. [#89](https://github.com/thorstenhornung1/Wallos/issues/89) was found
  by accident, while chasing a missing redirect.
* **No test asserts the shape of a query result**, only values. Both PostgreSQL
  defects found here survive any test written as
  `$row['userCount'] ?? $row['usercount']`, or one that builds its own SQLite
  connection instead of the one the application builds.

## Recommendations for the developer

Ordered by what would prevent the most, not by effort.

**1. Make the abstraction the only way to reach a database, and enforce it.**
Both application defects (#89, #90) and the tooling defect (#91) are the same
mistake in three places: code written against SQLite's behaviour rather than
against `WallosDatabase`. The guard for this already exists in
`dev/semgrep/sqlite-boundary.yml` and is switched off, with an honest note
explaining why. It is worth switching on for `includes/`, `endpoints/` and `api/`
now that the boundary exists, even if `dev/` stays excluded for a while.

**2. Extend that guard to shell scripts.** The five violations in `dev/*.sh` are
invisible to Semgrep because they live inside `php -r '...'` in a `.sh` file and
the rule declares `languages: [php]`. A three-line grep gate over `dev/*.sh`
would have caught all of them.

**3. Test against the connection the application builds, not one the test
builds.** A single fixture that hands the real `wallos_database_connect()` to
each test would have failed immediately on `computeAmountNeededInPeriod()` — the
type hint rejects the object before the function body runs.

**4. Assert keys, not just values.** A regression test for #89 must check that
the returned column is `userCount`. Reading the value through a null-coalescing
fallback passes on both backends and hides the defect permanently.

**5. Quote every identifier that is not lower case.** Seven sites today; the
next mixed-case alias will be the eighth. `as "userCount"` works on both
backends and needs no change at the reading site.

**6. Give the cron jobs an exit code and somewhere to be seen.** The one job
that matters died with a fatal, and nothing anywhere would have noticed:
`sendnotifications` is unattended by nature. A non-zero exit and a line in the
container log at ERROR level would turn a silent failure into an operational
one.

**7. Do not let a fixture file be a delete target.** `cleanup_bench()` runs
`DELETE FROM subscriptions WHERE name LIKE "bench-%"` against a hardcoded path.
On this instance that path held the SQLite backup kept as the rollback route
after the move to PostgreSQL.

## Conclusions, kept separate from the observations above

* **A fresh PostgreSQL instance installs and runs.** Schema, migration chain,
  mail and account deletion all behave as on SQLite.
* **Two defect classes are specific to PostgreSQL**, and both share one root:
  code written against SQLite's behaviour rather than against the abstraction.
  Unquoted mixed-case aliases (#89) and a hard `SQLite3` type hint (#90).
* **Neither would be caught by a test that checks values rather than shapes.**
  A test reading `$row['userCount'] ?? $row['usercount']` passes on both
  backends; so does any test that builds its own SQLite connection instead of
  the one the application builds.

## Still to run

Sections 7.1 to 7.3 and the end-to-end half of 7.4. All of them need a browser
session against Authentik and a session cookie taken from developer tools, so
they are **not covered** rather than pending — the distinction matters, because
nothing about them was verified here.

Section 8.2 does not apply: this instance was built fresh rather than migrated.
Section 10 (teardown) is deliberately not run; the instance is left standing.
