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
| 5.6 notifications actually go out | *pending* | |
| 5.7 a broken secret file does not fall back | *pending* | |
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

5.6 (notifications actually go out), 5.7 (a broken secret file does not fall
back), section 6 (load), section 7 (OIDC).
