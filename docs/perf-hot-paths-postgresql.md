# Hot-path query counts on PostgreSQL

[Issue #18](https://github.com/thorstenhornung1/Wallos/issues/18) made the
subscription and statistics hot paths measurable and pinned their query counts
with regression tests. It shipped in 5.12.0 — but the counts were measured on
SQLite, and the counting harness (`WallosCountingDatabase` in
`tests/bootstrap.php`) extends `WallosSqliteDatabase` and is SQLite-only by
design.

That matters because the milestone this issue belongs to exists for one reason:
a query that costs nothing on SQLite — an in-process function call — is a
network round trip on PostgreSQL. An N+1 is therefore invisible on the backend
the code was written for. The notification cron was exactly that: flat on
SQLite, linear on PostgreSQL, six queries per account inside its loop
([#99](https://github.com/thorstenhornung1/Wallos/issues/99)). 5.12.0 moved
those six to bulk loads. This note is the PostgreSQL verification that the fix,
and the two read hot paths alongside it, are genuinely constant on the backend
where a regression would hide.

## Result

All three hot paths issue a **constant** number of statements on PostgreSQL,
independent of the number of rows and accounts, measured up to one million
subscriptions. No hidden N+1 was found. Nothing needed fixing; this is a
verification, and the deliverable is these numbers.

## Method

The SQLite counting wrapper cannot run on PostgreSQL, so the count was taken at
the server instead, with
[`pg_stat_statements`](https://www.postgresql.org/docs/14/pgstatstatements.html):
`sum(calls)` over the view is the number of statements that actually reached the
backend. It is unambiguous where a PHP-level counter is not — one row per
normalised statement, `calls` is executions, so a per-row query in a loop is
counted once per row rather than once. Utility statements (`SET`/`BEGIN`/
`COMMIT`) were excluded at the server (`pg_stat_statements.track_utility=off`);
the `reset()` and the read-back were excluded by text.

Because `pg_stat_statements` counts what hits the wire, it catches a class the
SQLite counter and any PHP-level counter both miss: a hidden per-row round trip
inside the driver layer (a server-side `PREPARE` per call, a `SELECT lastval()`
per insert, and so on). That is the only PG-specific way a count constant in PHP
could grow on PostgreSQL, and it is the class this measurement is for.

Each hot path was measured on its **own fresh connection**, because the currency
rate map and the effective-configuration results are cached per connection, and
a real deployment serves each page or job as a separate request with a cold
cache. The paths were driven by the application's own code — `getdbkeys.php`,
`currency_rates.php`, `stats_calculations.php`,
`stats_extra_calculations.php`, `notification_settings.php`,
`notification_due.php`, and the bulk-load block of
`endpoints/cronjobs/sendnotifications.php` — not by reimplementations of it.

### The counter was proven to detect the defect it rules out

A gate that never fails proves nothing. Before trusting a flat line, a positive
control issued one query per row over 500 rows and confirmed the counter
reported 500. So a per-row query, had one existed in a hot path, would have
shown as growth. The hot-path counts below did not grow while the row count grew
by four orders of magnitude — which is the evidence that no per-row query is
there.

### Seed sizes

`dev/seed.php <users> <subscriptions-per-user>` at the three sizes #18 used,
which scale both axes at once — the per-account subscription count (for the two
per-account read paths) and the account count (for the cron):

| Size | `seed.php` args | Accounts | Subs / account | Total subs |
| --- | --- | --- | --- | --- |
| A | `1 100` | 1 | 100 | 100 |
| B | `10 1000` | 10 | 1 000 | 10 000 |
| C | `100 10000` | 100 | 10 000 | 1 000 000 |

## The numbers

Statements per request, by hot path and size. **Flat across every column is the
whole point.**

| Hot path | A (100) | B (10 000) | C (1 000 000) | Grows with rows? |
| --- | --- | --- | --- | --- |
| Subscription list (one account) | 8 | 8 | 8 | no |
| Statistics page (one account) | 11 | 11 | 11 | no |
| Notification cron — load phase, seed as-is | 13 | 13 | 13 | no |
| Notification cron — load phase, every account due | 19 | 19 | 19 | no |

- **Subscription list — 8.** `getdbkeys.php` (currencies, household, payment
  methods, categories, cycles: five) + the main-currency lookup + the one list
  query + the currency rate map, which is loaded once and answered from memory
  for every rendered row. The account carried 100, then 1 000, then 10 000
  subscriptions; the count did not move.

- **Statistics page — 11.** The category / payment-method / household loads, the
  three filter-menu count queries, the one figures query over the account's
  subscriptions, the rate map (once), the recorded cost-trend query, and the
  currency-code lookup in the extra calculations. Every per-subscription
  computation — conversion, the period amount, the twelve-month projection —
  reads already-loaded data. Flat across the same range.

- **Notification cron, load phase — 13 as seeded, 19 with work.** `dev/seed.php`
  enables no notification providers, so as seeded the cron reads the user list,
  one query per provider table, the timing rows, and the email-enabled probe —
  and stops, because no account is a candidate: 13, flat. Forcing every account
  to have an enabled channel and a payment due today exercises the six bulk
  loads that replaced the per-account queries; each is a single `IN (...)`
  query, so the load phase is 19 whether one account has work or a hundred do.
  That constant is the fix from #99/#18 holding on PostgreSQL.

### The one thing that is linear, and why it is not the defect

The cron's per-recipient **send loop** resolves each account's effective SMTP
transport and looks up its recipient row: three statements per account that
actually has mail to send. That is linear in *recipients*, and measured so —
3, then 30, then 300 across sizes A/B/C once every account was given work.

This is not the N+1 the milestone was created for. That one was six loads per
account done *before knowing whether the account had any work at all*, so it
scaled with **total** accounts regardless of whether they notified. The
remaining per-recipient cost is bounded by the accounts that are actually
sending a message, each of which must have its transport and address resolved to
send at all — inherent per-recipient work, not avoidable round trips. It is also
identical on both backends and so is not PostgreSQL-specific.

(One memory note, separate from query counts: with every one of a hundred
accounts notifying and ten thousand active subscriptions each, the active-
subscription bulk load pulls a million rows into one PHP array and needs more
than the default 128 MB CLI limit. That is the deliberate round-trips-for-rows
trade the loader documents; it is a memory characteristic, not a query-count
one, and it does not arise as seeded.)

## Why the SQLite query-count guards remain the regression net

The shipped guards — `subscription_list_test.php` and the cron count assertions
in `performance_test.php` — run on SQLite. They stay the regression net for both
backends, on purpose:

- **Query count is decided in backend-agnostic PHP.** The hot paths call
  `prepare` / `query` the same number of times whichever driver answers. A
  per-row query added to a hot path is counted by the SQLite wrapper (it counts
  `prepare`), so it fails the SQLite guard immediately — it does not need a
  PostgreSQL run to be caught. The SQLite guard protects the PostgreSQL count
  because the two counts share their cause.

- **The one PostgreSQL-specific risk is hidden driver round trips**, which no
  PHP-level counter sees — only a wire-level one. That risk is a property of
  `includes/database/pgsql/` (a stable, reviewed boundary), not of the hot-path
  code, and it is what this measurement checked directly and found absent.

A PostgreSQL-runnable query-count guard in CI would need either
`pg_stat_statements` preloaded on the test server (the shared dev/test
PostgreSQL does not load it) or a counting `WallosPgsqlDatabase` subclass wired
into `wallos_test_open_counting_database()`. The first is an infrastructure
change to a shared container; the second is a harness change whose only new
coverage over the SQLite guard is the hidden-round-trip class — which is better
served by the boundary tests and by a re-run of this measurement when the
`pgsql` driver changes. So none was added here, and this note plus the SQLite
guards are the record.

## Reproducing

PHP runs only inside the container image, and the shared `wallos-dev-postgres`
must not be disturbed, so the measurement used its own throwaway PostgreSQL and
its own runner on a private network, torn down afterwards:

```sh
podman network create wallos-pgverify-net

# Throwaway PostgreSQL with pg_stat_statements and statement logging.
podman run -d --name wallos-pgverify-pg --network wallos-pgverify-net \
  -e POSTGRES_DB=wallos -e POSTGRES_USER=wallos -e POSTGRES_PASSWORD=wallos-dev \
  docker.io/library/postgres:14-alpine \
  -c shared_preload_libraries=pg_stat_statements \
  -c pg_stat_statements.track=top -c pg_stat_statements.track_utility=off \
  -c log_statement=all
podman exec wallos-pgverify-pg psql -U wallos -d wallos \
  -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"

# Runner from the dev image, this worktree mounted, pointed at the throwaway DB.
podman run -d --name wallos-pgverify-php --network wallos-pgverify-net \
  -v "$PWD":/var/www/html:Z \
  -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST=wallos-pgverify-pg \
  -e WALLOS_DB_PORT=5432 -e WALLOS_DB_NAME=wallos \
  -e WALLOS_DB_USER=wallos -e WALLOS_DB_PASSWORD=wallos-dev \
  -e WALLOS_DB_SSLMODE=disable -e TZ=UTC \
  --entrypoint sh docker.io/library/dev-wallos:latest -c "sleep infinity"

# Build the schema the way the app does, then seed a size.
podman exec wallos-pgverify-php php endpoints/cronjobs/createdatabase.php
podman exec wallos-pgverify-php php dev/seed.php 10 1000
```

Between sizes the schema was dropped and rebuilt from the baseline, because the
default currency rows the baseline installs belong to `user_id = 1` and
`dev/seed.php`'s first account reuses that id — so a second seed's cleanup would
delete them (a fixture artefact, not a code fault). Each hot path was then run on
a fresh connection with `SELECT pg_stat_statements_reset()` before and
`SELECT sum(calls) FROM pg_stat_statements` after. The harness scripts were
temporary instrumentation and were removed; this note is the durable record.

Teardown:

```sh
podman rm -f wallos-pgverify-php wallos-pgverify-pg
podman network rm wallos-pgverify-net
```
