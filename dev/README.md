# Local development and test environment

Everything runs in containers; no local PHP, Composer or database is required.
`podman` is used in the examples, `docker` works the same way
(`CONTAINER_ENGINE=docker dev/test.sh`).

## Test suite

```sh
dev/test.sh                # every case
dev/test.sh currency       # only cases matching "currency"
```

The suite is a zero-dependency harness in `tests/`, matching the way Wallos
vendors its libraries. It builds the real schema by running
`endpoints/cronjobs/createdatabase.php` and the migration chain inside a
throwaway copy of the source tree, then hands each case its own database file.

The same cases run against PostgreSQL, which needs a server to connect to and
therefore the development container rather than the throwaway one:

```sh
podman exec -e WALLOS_TEST_DRIVER=pgsql wallos-dev php tests/run.php
```

Each case gets its own schema there, and cases that test SQLite itself say so
and stand aside instead of asserting something nobody claims.

Which PostgreSQL versions that covers is decided by CI, not by this file: the
`postgres-versions` job in `.github/workflows/build-release.yaml` reads the
range the PostgreSQL project still supports from endoflife.date on every run
(with a recorded fallback so a third-party outage cannot redden the build),
runs the full suite — fresh install, every case, the SQLite migration with its
sequences verified — against the oldest and the newest of that range, and
prints "tested against PostgreSQL X through Y" in the run's job summary. The
tested range is whatever the latest summary on `main` says; a pair of numbers
written here would be a promise nothing keeps.

**The fixture hands over the connection the application builds.**
`wallos_test_open_database()` points `WALLOS_DB_PATH` or the `WALLOS_DB_*`
variables at a throwaway database and then calls `wallos_database_connect()`
with no arguments — the same call `index.php` makes. A fixture that constructs
its own object can construct one the application never has, and then a
signature like `computeAmountNeededInPeriod(..., SQLite3 $database)` goes on
rejecting the real PostgreSQL connection in production while the suite stays
green (issue #90). On PostgreSQL the environment also carries `PGOPTIONS`, so a
connection the code under test opens for itself lands in the same isolated
schema rather than in `public`.

Cases registered with `wallos_test_pending()` describe behaviour the
specification requires but the code does not implement yet. They are reported
as `open` and do not fail the run; when one starts passing, the runner says so
and the case can be promoted.

## SQLite boundary audit

```sh
dev/db-audit.sh              # the gate, exactly as CI runs it
dev/db-audit.sh --report     # inventory: every file, worst first
dev/db-audit.sh --update     # record the current tree as the new baseline
```

Needs neither a container nor PHP; it uses `rg` when installed and `grep -E`
otherwise. SQLite-specific APIs must stay inside the database adapter boundary,
but that boundary is still being built (issue #20), so the audit ratchets
against `dev/db-audit-baseline.txt`: a file's count may fall, never rise, and a
file that is not in the baseline may not start matching. Improvements pass and
ask you to commit the smaller baseline.

`docs/sqlite-boundary.md` explains the design, and says which of the three
gates from issue #41 are active.

## Full application

```sh
dev/up.sh
```

On first run this copies `dev/secrets.example` to `dev/secrets`, which is
git-ignored, and then starts the stack.

| Service | URL | Purpose |
| --- | --- | --- |
| Wallos | http://localhost:8383 | the application, with the working copy mounted |
| Mailpit | http://localhost:8025 | catches every mail Wallos sends |

The working copy is mounted into the container, so a PHP change is live on the
next request. Restart only when the `Dockerfile`, cron definitions or nginx
configuration change.

The environment is configured the way a real self-hosted instance would be:
shared SMTP, currency and AI credentials, with the secrets supplied through
`*_FILE` variables from `dev/secrets/`. Those files contain obvious dummy
values — the currency and AI keys are intentionally invalid so nothing reaches
a paid provider. Edit `dev/secrets/*` to try real credentials; the files are
git-ignored.

To exercise the database-managed path instead of the environment-managed one,
comment the `WALLOS_*` variables out and configure the same values in
**Admin → SMTP Settings** and **Admin → Instance Integrations**.

### End-to-end checks

```sh
dev/e2e.sh
```

Registers an account, renders the settings and admin pages, asserts that no
instance secret reaches the HTML, sends a mail through the instance transport
and checks it arrived in Mailpit, then runs every mail and currency cron job
and fails on PHP errors.

### Representative data

```sh
podman exec wallos-dev php /var/www/html/dev/seed.php 10 100
```

Seeds users and subscriptions for query-count and page-timing work. Seeded rows
are prefixed `seed-` and are replaced on each run; real accounts are untouched.
Useful sizes from the specification: `1 100`, `10 1000`, `100 10000`.

### Performance measurement

```sh
dev/benchmark.sh                                     the local dev instance
WALLOS_PASSWORD=… dev/benchmark.sh \
    --base https://test.example.de --user admin \
    --exec 'docker exec wallos-test_wallos.1.abc'    a remote instance
```

Two tables: one account's subscription list at 100, 1000 and 5000 entries, and
the notification cron at 1, 10 and 100 accounts. Every figure is the median of
five runs, and the header names the database the figures were measured against.

Everything that writes goes through `dev/bench.php`, which connects with
`wallos_database_connect()` and has no way to name a database. That is the fix
for issue #91: on a PostgreSQL instance the seeding used to reach PostgreSQL
while the sizing and the cleanup went to `db/wallos.db`, so the entries column
timed pages against rows that were never inserted where those pages read, and
the run finished by printing "Seeded data removed." having removed nothing. The
cleanup now reports the rows it actually removed, counted before and after, and
the script refuses to measure at all if the account it signs in as over HTTP is
absent from the database it just opened.

The password comes from `WALLOS_PASSWORD`, `--password-file` or
`--password-stdin`. `--password` still works and warns: an argument is visible
to every `ps` on the machine for the length of the run.

The rates column is decided before any tier runs: with no provider configured,
one that refuses, or one that does not answer within `--rates-timeout` seconds
(20 by default), it reads `skipped` and the reason is printed under the table.
If a tier that is measured passes `--cron-timeout` instead, the cell reads
`timeout`, the figures already taken stay, and the later tiers are not attempted
— they have more accounts and would buy the same answer at the same price.
Both the development environment and `docs/test-instance.md` start from an
invalid provider key so that no run spends real quota — verify that is still
true of the instance you are measuring, because a rotated secret does not
announce itself (#104). With an invalid key the figure that column would
otherwise print is a network timeout — one tier
alone ran for eleven minutes that way. Every cron run is bounded, so a hanging
job costs one bound rather than the whole benchmark.

### Snapshots of a real database

```sh
dev/snapshot.sh                          take one, named by timestamp
dev/snapshot.sh --name before-79         take one under a name
dev/snapshot.sh --list                   what is stored
dev/snapshot.sh --show before-79         its manifest
dev/snapshot.sh --rehearse before-79     migrate it into a scratch schema
```

A migration has to survive real data, and real data is not what a generator
produces. SQLite declares foreign keys and enforces them only when asked, so an
installation that has been used accumulates rows PostgreSQL will refuse — a
subscription pointing at a payment method somebody deleted, notification rows
belonging to an account that is gone. Those rows are the interesting part of a
migration and no fixture has them, because a fixture has no history.

The copy is taken with `VACUUM INTO` from a read-only connection, falling back
to the backup API; both are transactionally consistent on a database that is
being written to, which `cp` is not — a plain copy can catch a page halfway
through a write and produce a file that opens and is wrong. The copy is then
checked, streamed out of the container, and compared byte for byte, because a
truncated database file opens perfectly well.

Each snapshot gets a manifest beside it: row counts per table, and how many rows
violate which foreign key that PostgreSQL will enforce. The constraint list is
read from `includes/database/pgsql/schema.sql`, because the question is not
which references SQLite declares but which ones the target will enforce when the
data arrives.

Snapshots hold real data, so `dev/snapshots/` is git-ignored. The script refuses
to snapshot an instance that is configured for PostgreSQL: a `db/wallos.db` on
such an instance is a leftover, and on the instance in issue #91 it was the
backup kept as the rollback route.

`--rehearse` creates a scratch schema, runs `dev/migrate-to-pgsql.php` against
the snapshot, and drops the schema again — `--keep` leaves it, and `--dry-run`,
`--allow-non-empty` and `--skip-orphans` are passed through. A schema that was
already there is never dropped, and `public` is never dropped at all.

### Moving an existing installation to PostgreSQL

```sh
podman exec \
    -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST=postgres \
    -e WALLOS_DB_NAME=wallos -e WALLOS_DB_USER=wallos \
    -e WALLOS_DB_PASSWORD=wallos-dev \
    wallos-dev php /var/www/html/dev/migrate-to-pgsql.php --dry-run
```

Copies an existing SQLite database into PostgreSQL (issue #79). The environment
names the target, the same variables the running application reads; `--source`
names the SQLite file, which is opened read-only and never written to. Drop
`--dry-run` to do it for real, and stop the application first.

It refuses rather than guesses. A target that already holds more than the
baseline seeds needs `--allow-non-empty`, which deletes what is there. Source
rows that violate a foreign key PostgreSQL enforces and SQLite never has need
`--skip-orphans`, which leaves them behind and counts them in the verification;
without it the migration stops and names the constraint. A source whose applied
migrations differ from the baseline's is refused outright, because the copy
would silently leave the newer columns at their defaults.

Everything happens in one transaction, every sequence is set past the highest
id copied — that last part is what stops the first insert after the import
colliding with a row it just wrote — and the run ends with a row count per
table rather than a claim that it worked.

To check the contents rather than the counts:

```sh
podman exec wallos-dev php /var/www/html/dev/stress-seed.php 12 25
podman exec wallos-dev php /var/www/html/dev/stress-verify.php > before.txt
# migrate, then with the pgsql variables set:
podman exec wallos-dev php /var/www/html/dev/stress-verify.php > after.txt
diff before.txt after.txt   # only the driver name on the first line may differ
```

### The shadow migration

```sh
dev/shadow-migrate.sh /path/to/copy-of-production.db
dev/shadow-migrate.sh --pg-image docker.io/library/postgres:18-alpine copy.db
```

The whole upgrade path in one command, against a copy of a database that
actually grew: the fork's migration chain, then `dev/migrate-to-pgsql.php` into
a PostgreSQL container the script starts and removes itself, then a row count
taken on both sides and compared, then a boot of the real image against the
migrated database. One line per step at the end, non-zero exit on any failure,
and nothing left running afterwards — it is built to be a nightly job.

It exists because the upgrade path is recorded as untested in three independent
places, always for the same reason: a fresh installation records every migration
as applied in the moment it creates the schema, and a fresh PostgreSQL
installation installs a baseline that records them without running them. So no
CI run on either backend says anything about what the chain does to a schema
that grew. Migration 000016 is what that costs — it recorded itself as applied
on every installation ever made without doing its work, and 000065 removed the
leftover table nine years later.

The file it is given is never touched: everything happens on a copy in a scratch
directory, and the last step compares the original's checksum with the one taken
before the run. Give it a copy anyway, because a database that is being written
to cannot be copied safely at all — `dev/snapshot.sh` is the tool for taking one.

Nothing in it is a warning. A migration that reports failure, a table that
arrives with fewer rows than it left with, a container that does not answer on
`health.php`: each ends the run with a non-zero status and a line naming the
cause. `--skip-orphans` is deliberately not passed through — rows a real
migration would leave behind have to be somebody's decision, not a silent
subtraction. `dev/snapshot.sh --rehearse` is the exploratory tool that does pass
it through.

### Tearing down

```sh
podman compose -f dev/compose.yaml down
```

The development database lives in `db/wallos.db` of the working copy and
survives a restart. Delete that file to start from a fresh installation.
