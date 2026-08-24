# Test run 2026-08-24 — 5.8.5 on PostgreSQL, the three sections left unfinished

The QA round that stopped on 2026-08-22 left three sections open
(`docs/next-steps.md`, "QA left unfinished"): backup and restore on PostgreSQL
(8.4), migration 000067 against deliberate orphans, and section 6 against the
rebuilt notification cron. All three were run. All three pass.

The run began with a finding rather than a test. **The instance was not on
PostgreSQL.** It had been running on SQLite since 2026-08-22 06:02, against a
database file left over from before the PostgreSQL switch — while the three
preceding reports were written up as PostgreSQL runs. The evidence is preserved
below, because emptying the volume for this run would have destroyed the only
way left to establish what those reports actually measured.

## Environment

Read from the running instance rather than from memory. The table that used to
be filled in by hand is what went wrong here — nothing in the application says
which database it is on ([#102](https://github.com/thorstenhornung1/Wallos/issues/102)),
so a hand-written "PostgreSQL 18.6" was unfalsifiable for three runs.

```sh
$ docker service inspect wallos-test_wallos \
    --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}'
ghcr.io/thorstenhornung1/wallos:5.8.5@sha256:24d79f9e9f4ed6ac4e52a6fbdb5427c5c9bafef93b1fa7e9aa0c4a443cf6b37d

$ docker exec $(docker ps -qf name=wallos-test_wallos) env | grep '^WALLOS_DB_'
WALLOS_DB_DRIVER=pgsql
WALLOS_DB_HOST=postgres
WALLOS_DB_NAME=wallos
WALLOS_DB_PORT=5432
WALLOS_DB_SSLMODE=disable

$ $EXEC php -r 'require "/var/www/html/includes/database/connection.php";
                $d = wallos_database_connect();
                printf("%s | %s\n", $d->driver(), $d->scalar("SELECT version()"));'
pgsql | PostgreSQL 18.6 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit

$ $EXEC php -r 'include "/var/www/html/includes/version.php"; echo "Wallos $version\n";'
Wallos v5.8.5

$ psql -At -c "SELECT 'migrations='||COUNT(*)||' highest='||MAX(migration) FROM migrations"
migrations=66 highest=migrations/000067.php
```

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.5` |
| Digest | `sha256:24d79f9e9f4ed6ac4e52a6fbdb5427c5c9bafef93b1fa7e9aa0c4a443cf6b37d` |
| Version | `Wallos v5.8.5` |
| Driver, from the environment | `WALLOS_DB_DRIVER=pgsql` |
| Database, from the connection | PostgreSQL 18.6, dedicated, node-local volume |
| Schema | 42 tables, 66 migrations, highest `migrations/000067.php` |
| Platform | Docker Swarm, pinned to `docker-infra-3` |
| Starting state | Rebuilt: database dropped and recreated, baseline installed on first start |

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| The instance was on SQLite, not PostgreSQL | **finding**, [#102](https://github.com/thorstenhornung1/Wallos/issues/102) | both databases dated to the minute; see below |
| 8.4 archive shape and manifest | **pass** | 79 entries, 42 `data/*.json`, 36 uploads, `"driver": "pgsql"` |
| 8.4 check 1 — post-backup change is gone | **pass** | `after-backup rows now = 0`, `before-backup rows now = 1` |
| 8.4 check 2 — row counts match the manifest | **pass** | 42 tables, 197 rows, 0 disagree |
| 8.4 check 3 — writing works afterwards | **pass** | sequence moved to 18, next insert got id 19 |
| 8.4 archive names its own secrets | **pass** | `contains_secrets: true` plus a sentence saying which |
| 000067 case 1 — orphans removed | **pass** | `categories: 1, household: 1`, living account untouched |
| 000067 case 2 — `user_id` 0 / NULL left alone | **pass** | shared row survives, migration says so |
| 000067 case 3 — **empty `user` table** | **pass** | migration declines; 34/17/31/1 rows unchanged |
| 000067 case 4 — every table, not a list | **pass** | 13 tables planted, 13 cleared, one statement |
| 6 — notification cron vs the night run | **pass**, twice as fast | 587/873/1576 ms → 222/283/738 ms |
| 6 — cron shape after the #99 rebuild | **observation** | still linear: ~10 ms/user → ~5.2 ms/user |
| 6 — subscription list vs the night run | **observation** | 2–3× slower; not attributable from one run |
| #98 benchmark cleanup leaves orphans | **pass** (fix confirmed) | 0 orphans across 32 tables after cleanup |
| Section 6 spends real provider quota | **finding**, [#104](https://github.com/thorstenhornung1/Wallos/issues/104) | the key is no longer the invalid one the plan documents |
| SQLite sections 4, 5 and 7 | **not covered** | see "What this run did not check" |

## The instance was not on PostgreSQL

This is what the run found before it could start, and it decides how much the
three preceding reports are worth.

**The state as found.** `WALLOS_DB_DRIVER=sqlite`, with every other
`WALLOS_DB_*` value set and read by nobody. `db/` held four SQLite files:

```
pre-5.7.1.db            1777664  Aug 17 06:51
pre-pgsql-20260819.db   1777664  Aug 19 07:21
wallos.db               1777664  Aug 24 21:16   <- live, written every minute
wallos.empty.db           57344  Aug 16 12:24
setup_token.db               64  Aug 16 18:29
```

**Both databases are fully migrated.** This is the part that had to be measured
rather than assumed, and it rules out a failed migration as the cause:

```
PostgreSQL   66 rows, highest migrations/000067.php, applied 2026-08-22 05:44:52
SQLite       66 rows, highest migrations/000067.php, applied 2026-08-22 06:02:27
             failure rows: 0
             chain 1..67 complete (000049 does not exist in any release —
             the repository ships 66 migration files)
```

**Which dates the switch to eighteen minutes.** 5.8.5 ran its migrations against
PostgreSQL at 05:44:52 and against SQLite at 06:02:27 on the same morning. The
last row PostgreSQL ever received is a cron run at 2026-08-22 06:02:01; SQLite
has been written continuously since.

**The two databases served different populations,** which is what makes this
more than a label problem. The last `updateexchange` failure recorded in each:

```
PostgreSQL 2026-08-22 05:45   users 1, 2, 232, 118, 119, 120
SQLite     2026-08-24 00:00   users 1, 2, 4, 15, 16
```

**Observation, kept separate from the conclusion.** Observed: the driver was
`sqlite`; a three-day-old file was reopened and written to; nobody noticed for
three days. Concluded: the application did not fall back — `WALLOS_DB_DRIVER`
said `sqlite` and Wallos did exactly that. Creating `wallos.db` under that
driver is correct behaviour, not a defect. What is missing is any way to see it
from the outside, which is [#102](https://github.com/thorstenhornung1/Wallos/issues/102).

**Two corrections to diagnoses made during the run**, recorded because both
looked convincing:

* *"The active SQLite database stands at 000062."* It stands at **000067**. The
  file that stands at 000057 is a different one: a stray
  `wallos-test_wallos_db` volume on **docker-infra-2** (196 KB, 2026-08-16,
  never used), created when a task briefly started on the wrong node. The test
  plan's own warning in section 2A describes exactly that artefact. Source for
  the 000067 figure: `docker-infra-3`, volume `wallos-test_wallos_db`, file
  `wallos.db`, read through `sqlite3` in a throwaway container.
* *"`wallos.db` was created today, so the application invented it."* SQLite
  updates mtime on every write and an instance with a per-minute cron always
  has a file dated today. The file carried migrations applied on 2026-08-17 and
  5002 subscription rows; it long predates this run.

**Why the incident was possible at all.** The deployed stack had **no source
file anywhere** — not in this repository, not on any node, not in Portainer's
compose store. `docs/test-instance/wallos-test-stack.yml` describes a
SQLite-only instance without OIDC; the running one had both. A redeploy from an
older hand-edited file was enough to drop one line, and nothing could be
diffed against. The configuration is now written down as
`docs/test-instance/wallos-test-stack-pgsql.yml`, and this run was deployed
from that file.

## The rebuild, and why not a migration

Section 8.2 (moving the SQLite instance across) was **not** taken. The choice
matters for reading everything below, so here is the reasoning:

* The SQLite database held **5002 subscriptions, 5000 of them named `bench-…`**
  — the residue of a benchmark run that ended before its cleanup. A starting
  state that is mostly abandoned test data makes 8.4's row-count check
  unreadable.
* PostgreSQL held a database of unknown vintage from the 19th–22nd (6 accounts,
  105 categories, 1 subscription) whose provenance nobody can reconstruct.
* The SQLite→PostgreSQL migrator was already exercised on 2026-08-19 — that is
  what `pre-pgsql-20260819.db` is. Running it again would re-test old ground and
  carry the benchmark residue into the new baseline.
* Section 8.1 gives a fresh instance an exact, checkable fingerprint. A
  reproducible starting state is worth more to a test run than preserved test
  data of unknown origin.

Everything was archived first — both PostgreSQL dumps and all five SQLite files,
with checksums, under `/srv/data/wallos-qa/` on `docker-infra-3`.

The baseline installed itself as section 8.1 says it does:

```
PostgreSQL database is empty. Applying the baseline schema...
Baseline schema applied.
```

```
driver:     pgsql
tables:     42
migrations: 66
users:      0
```

42 tables as the plan's table predicts. 66 migrations rather than 65 because
5.8.4 added `000066` and 5.8.5 added `000067`; the recorded count equals the
number of migration files the release ships.

## 8.4 — backup and restore on PostgreSQL

Driven through the interface: log in, take the CSRF token from the rendered
page, `POST /endpoints/db/backup.php`, `POST /endpoints/db/restore.php` with the
archive as a file upload.

### The archive

```
backup status=200 type=application/zip bytes=115766
/tmp/84/backup.zip: Zip archive data, at least v2.0 to extract, compression method=deflate

entries: 79   (42 data/*.json + 36 uploads/* + manifest.json)
```

```json
{
    "format": 1,
    "created_at": "2026-08-24 19:36:34",
    "wallos_version": "v5.8.5",
    "driver": "pgsql",
    "contains_secrets": true,
    "note": "Rows are stored as data, so this archive restores into either backend. It contains SMTP passwords, API keys and OIDC client secrets in clear text.",
    "tables": { "admin": 1, "categories": 18, "currencies": 34, ... }
}
```

One `data/…json` per table, `"driver": "pgsql"`, uploads present, and the
manifest states the credential exposure the plan says it should.

### Check 1 — something changed after the backup is gone again

A category `before-backup` was created, the backup taken, then a second category
`after-backup` created.

```
=== 5. change something AFTER the backup ===
category "after-backup" created with id 19
after-backup rows = 1

=== 6. restore the archive ===
{"success":true,"message":"The backup was restored.","tables":42,"rows":197}
restore status=200

=== CHECK 1 ===
after-backup rows now = 0
before-backup rows now = 1
```

The restore did something, and it did the right thing: the later row is gone,
the earlier one survived.

### Check 2 — the row counts match the manifest

Every table in the manifest compared against `COUNT(*)` in the live database
after the restore:

```
manifest: 42 tables, 197 rows in total

  admin                          manifest=1      live=1      ok
  categories                     manifest=18     live=18     ok
  currencies                     manifest=34     live=34     ok
  frequencies                    manifest=31     live=31     ok
  migrations                     manifest=66     live=66     ok
  payment_methods                manifest=31     live=31     ok
  user                           manifest=1      live=1      ok
  user_roles                     manifest=1      live=1      ok
  … 34 more, all ok

0 table(s) disagree with the manifest
```

The endpoint's own answer agrees: `"tables":42,"rows":197`.

### Check 3 — writing still works afterwards

This is the check the plan singles out, and the one a naive implementation
fails. It passes.

```
--- sequence state after the restore ---
categories_id_seq last_value = 18, is_called = true
max(categories.id) = 18
--- the write the plan asks for ---
{"success":true,"categoryId":19}
HTTP=200
the insert after the restore got id 19
```

The restore inserted explicit ids up to 18 and then moved the sequence to 18, so
the next insert took 19 rather than colliding with a row the restore had just
written. Every sequence in the schema was checked, not only this one:

```
 sequencename              | last_value
---------------------------+-----------
 admin_id_seq              |          1
 categories_id_seq         |         19
 currencies_id_seq         |         34
 cycles_id_seq             |          5
 frequencies_id_seq        |         31
 migrations_id_seq         |         66
 payment_methods_id_seq    |         31
 user_id_seq               |          1
 user_roles_id_seq         |          1
 … sequences for empty tables carry no value
```

Each matches the highest id its table holds. `wallos_archive_reset_sequences()`
does what its comment claims.

**Observation kept separate from conclusion.** Observed: three checks pass on
this data. Concluded: the sequence hazard is handled *for tables whose ids the
archive actually carries*. A table that was empty at backup time has an unset
sequence here, so this run says nothing about the case where a restore has to
move a sequence **down** — no archive in this run had fewer rows than the
database it replaced.

### The restore path and #103

[#103](https://github.com/thorstenhornung1/Wallos/issues/103) reports that
`endpoints/db/import.php:164` discards the migration runner's outcome and
answers `"success": true` unconditionally. That path was **not** exercised here:
`restore.php` takes the archive route on PostgreSQL and returns before
`import.php` is reached, and the container log during the restore shows

```
No migrations to run.
127.0.0.1 - 24/Aug/2026:21:36:35 +0200 "POST /endpoints/db/restore.php" 200
```

— an archive from the same release has nothing to migrate. Reproducing #103
needs an archive from an older schema, which this instance does not have. Not
covered rather than passed.

## Migration 000067 against deliberate orphans

The instance has no orphans of its own, so they were planted. Every case runs
inside a transaction that is rolled back, and the roll-back is itself asserted —
the instance holds the same rows afterwards as before.

Worth stating first, because it decides whether the test is possible at all:
**`categories.user_id` and `household.user_id` carry no foreign key on
PostgreSQL.** Of the 13 foreign keys in the schema, seven constrain `user_id`,
and the tables the repair is about are not among them. Orphans can therefore
exist on PostgreSQL, and no constraint had to be dropped to plant them.

```
driver: pgsql
tables with user_id: ai_recommendations, ai_settings, categories, currencies,
custom_colors, custom_css_style, discord_notifications, email_notifications,
email_verification, fixer, google_search, gotify_notifications, household,
last_exchange_update, login_tokens, mattermost_notifications,
notification_settings, ntfy_notifications, oidc_sessions, password_resets,
payment_methods, pushover_notifications, pushplus_notifications,
serverchan_notifications, settings, subscriptions, telegram_notifications,
total_yearly_cost, totp, uploaded_avatars, user_roles, webhook_notifications
```

### Case 1 — rows of an account that no longer exists

```
output: Wallos migration 000067: removed rows belonging to accounts that no longer
        exist — categories: 1, household: 1. These would have been inherited by the
        next account created with a reused id (issue #92).
  [PASS] the vanished account's category is gone (expected 0, got 0)
  [PASS] and its household member (expected 0, got 0)
  [PASS] the living account's rows are untouched (expected 1, got 1)
  [PASS] the migration names the tables it touched
  [PASS] and why
  [PASS] the migration did not report failure
  [PASS] rollback restored the instance (no user 8101)
```

### Case 2 — rows belonging to the instance

```
output: Wallos migration 000067: 1 row(s) carry user_id 0 or NULL and were left
        alone — those belong to the instance rather than to an account.
  [PASS] the shared row is still there (expected 1, got 1)
  [PASS] and the migration says it left them alone
```

### Case 3 — the empty `user` table

The case `next-steps.md` calls the one that hurts: on a fresh database every
seeded row names user 1 before user 1 exists, and a repair that trusted that
reading would empty the installation before its first account arrived.

```
  before: {"currencies":34,"categories":17,"payment_methods":31,"household":1}
  [PASS] the user table is empty (expected 0, got 0)
  [PASS] the fixture has seeded rows to lose (expected true, got true)
  output: (nothing — the migration declined to run)
  after:  {"currencies":34,"categories":17,"payment_methods":31,"household":1}
  [PASS] the seeded currencies are still there (expected 34, got 34)
  [PASS] the seeded categories are still there (expected 17, got 17)
  [PASS] the seeded payment_methods are still there (expected 31, got 31)
  [PASS] the seeded household are still there (expected 1, got 1)
  [PASS] rollback restored the accounts (expected 1, got 1)
```

The guard holds on PostgreSQL. Emptying `"user"` first required deleting the
seven tables that reference it, or the foreign keys would have refused the setup
before the migration's own guard was ever reached — which is itself worth
knowing: on PostgreSQL this state is only reachable on a genuinely fresh
installation.

### Case 4 — every table with a `user_id`, not a hand-written list

Orphans planted wherever a bare `(user_id)` insert is accepted, each attempt
wrapped in a savepoint so one refusal does not abort the transaction:

```
  orphans planted in: discord_notifications, email_notifications,
    email_verification, google_search, gotify_notifications,
    mattermost_notifications, notification_settings, password_resets,
    pushover_notifications, pushplus_notifications, settings,
    telegram_notifications, webhook_notifications
  planted rows: 13
  output: Wallos migration 000067: removed rows belonging to accounts that no longer
          exist — discord_notifications: 1, email_notifications: 1,
          email_verification: 1, google_search: 1, gotify_notifications: 1,
          mattermost_notifications: 1, notification_settings: 1, password_resets: 1,
          pushover_notifications: 1, pushplus_notifications: 1, settings: 1,
          telegram_notifications: 1, webhook_notifications: 1. …
  [PASS] every planted orphan is gone (expected 0, got 0)
  [PASS] the migration did not report failure (expected false, got false)

ALL PASSED - 0 check(s) failed
```

Thirteen tables reached in one pass, none of them named in the migration's
source. The schema-driven shape works on PostgreSQL.

The instance was unchanged afterwards — `users=1, categories=17,
payment_methods=31, currencies=34, household=1`, identical to before.

## Section 6 — load, against the rebuilt cron

```
Wallos benchmark — https://test.hornung-bn.de, median of 5 runs
database        pgsql wallos@postgres:5432/wallos, schema public

Subscription list, one user
  entries             list       stats    calendar
  100                284ms       159ms       155ms
  1000               805ms       238ms       225ms
  5000              3134ms       302ms       268ms

Notification cron, all users
  users             notify       rates
  baseline            61ms           -
  1                  222ms       707ms
  10                 283ms      1072ms
  100                738ms      2541ms
```

### The cron, against the night run

| users | 2026-08-20 night run | this run | change |
| --- | --- | --- | --- |
| baseline | 42 ms | 61 ms | +19 ms |
| 1 | 587 ms | 222 ms | −62 % |
| 10 | 873 ms | 283 ms | −68 % |
| 100 | **1576 ms** | **738 ms** | **−53 %** |

**Observation.** Every tier is faster, and the gap widens with the number of
accounts. The per-user slope falls from about 10.0 ms to about 5.2 ms
((1576−587)/99 against (738−222)/99).

**Conclusion, kept separate.** The #99 rebuild — six queries for all accounts
instead of six per account — is worth roughly half the job's cost at a hundred
accounts, which is a real improvement and larger than measurement noise across
three tiers moving together. It has **not** made the cron flat. The remaining
5.2 ms per account is the per-account work still inside the loop, and that
matches what `next-steps.md` already says is left: step 3 of #99, inverting the
loop so only accounts with something due are processed, is not done. A reader
of the plan's "the cron is flat" line should still read it as SQLite-only.

### The subscription list, against the night run

| entries | night run (list / stats / calendar) | this run |
| --- | --- | --- |
| 100 | 78 / 45 / 42 ms | 284 / 159 / 155 ms |
| 1000 | 270 / 85 / 70 ms | 805 / 238 / 225 ms |
| 5000 | 1300 / 423 / 411 ms | 3134 / 302 / 268 ms |

**Observation.** Stats and calendar are a near-constant 113–155 ms slower at
every tier except 5000, where they are *faster* than the night run (302 against
423, 268 against 411). The list is slower everywhere, and by a growing amount:
+206 ms, +535 ms, +1834 ms.

**Conclusion, kept separate — and it is mostly a refusal to conclude.** A
uniform additive offset on two of the three columns looks like per-request
overhead rather than anything in the application, and this run was driven from
the node rather than from wherever the night run was driven; that alone can
account for a fixed difference in either direction. The list column does not fit
that explanation, but one run against one earlier run, on shared hardware whose
other tenants are not controlled for, is not enough to call it a regression.
What can be said: 100 → 5000 is 50× the data for 11× the time here against 17×
in the night run, so the list is still sub-linear, and 3134 ms at 5000 entries
is well past the "past comfortable" mark the plan already sets at 875 ms. No
issue filed; a controlled re-run from the same place, twice, is what would
settle it.

### Two things the run confirmed on the way past

**#98 is fixed.** The night run found 11 orphaned `email_notifications` rows
after the cleanup. This run:

```
Removing seeded data
  email_notifications    100 row(s) removed
  last_exchange_update   100 row(s) removed
  subscriptions          6000 row(s) removed
  household              100 row(s) removed
  categories             100 row(s) removed
  user                   100 row(s) removed
  currencies             100 row(s) removed
```

```
users=1  seed_users=0  subscriptions=0  bench_subs=0
total orphans across all 32 tables carrying user_id: 0
```

**A benchmark that stops before its cleanup leaves everything behind, quietly.**
The SQLite database found at the start of this run held 5000 `bench-` rows,
dormant since 2026-08-19. The cleanup itself works — the block above proves it —
but nothing notices rows that were never handed to it. Worth knowing before
reading any list timing taken on an instance somebody else used.

## Section 6 now spends real currency-provider quota

`docs/test-instance/wallos-test-stack.yml` says the currency key is
"deliberately invalid so no test run spends a real quota", and the night run's
output agrees: `rates not measured (refused): The currency provider could not be
reached.` This run instead printed

```
  rates measured against a live provider — each run spends provider quota.
```

and the rates column carries real figures. The provider is answering: the
instance now uses `WALLOS_CURRENCY_PROVIDER=fixer` with the secret
`wallos_test_currency_api_key_v2`, and live rates arrived —

```
 code |    rate
------+------------
 EUR  | 1
 USD  | 1.166208
 JPY  | 185.632965
 GBP  | 0.855706
```

Three tiers × five runs against 1, 10 and 100 accounts is on the order of six
hundred provider calls. **This run spent that quota**, and any future run of
section 6 on this instance will spend it again. Filed as
[#104](https://github.com/thorstenhornung1/Wallos/issues/104), because the fix
is a secret only the operator can rotate; the plan has been corrected in both
places so it no longer promises that a run is free.

### And it produced a concrete case of #101

During the hundred-account tier the provider stopped answering part-way:

```
job=updateexchange status=failed duration_ms=2381 failure_count=14
detail: updated=99; exchange rates for user 26 were not updated:
        The currency provider could not be reached.;
        exchange rates for user 27 were not updated: …
```

Ninety-nine accounts updated and the rest did not, in a single run, against a
provider that had just answered. A rate limit and an outage are the two obvious
readings and the message cannot tell them apart — which is exactly
[#101](https://github.com/thorstenhornung1/Wallos/issues/101). Added to that
issue as a reproduction rather than filed again.

## What this run did not check

Stated plainly, because a report that only lists what passed is not usable.

* **SQLite sections 4, 5 and 7.** `next-steps.md` calls this the larger gap and
  it is still open. This run moved the instance the other way, onto PostgreSQL,
  so it did not touch it. Automated coverage on SQLite is complete; no human has
  driven those sections since 5.7.0.
* **Sections 4, 5 and 7 on this rebuilt instance.** The rebuild emptied the
  database, so the two accounts, the mail fixtures and the OIDC state those
  sections need are gone. They were re-verified against 5.8.4 on 2026-08-21 and
  the code did not change underneath them; they were not re-run here.
* **#103 through the restore path.** As above: `restore.php` returns before
  `import.php` on PostgreSQL, and an archive from the same release has no
  migration to fail. Needs an archive built from an older schema.
* **A restore that has to move a sequence down.** Every sequence in this run
  moved up or stayed. An archive holding *fewer* rows than the database it
  replaces is the untested direction.
* **Whether the list timings are a regression.** One run against one earlier
  run, from a different place, on shared hardware. Not enough.
* **The currency provider's actual HTTP status.** Confirming a rate limit rather
  than an outage means reading the API key, and secrets are not read here. The
  reading above is a hypothesis and is labelled as one.

## Corrections to the plan, made in this pull request

Following section 9's own rule that a plan found wrong gets fixed in the same
change:

* **Section 9 now requires the environment table to be command output.** It
  asked for the version to be read from the image tag and left the rest to be
  filled in by hand — and the driver row, filled in by hand, was wrong in three
  consecutive reports. Four commands are now named, including the one that reads
  `WALLOS_DB_DRIVER` out of the running container.
* **The stack the instance actually runs is now a file** —
  `docs/test-instance/wallos-test-stack-pgsql.yml` — and section 2A points at
  it. Its absence is what allowed a redeploy to drop `WALLOS_DB_DRIVER` with
  nothing to diff against.
* **Section 6 warns that the rates column costs provider quota** on an instance
  whose key works, and section 2A no longer claims the deployed key is invalid.
