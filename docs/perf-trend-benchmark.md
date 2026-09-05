# Hot-path growth curves

`docs/perf-hot-paths-postgresql.md` pinned how many **statements** each hot path
issues, and proved that number does not grow with the data. A query count is not
the whole story: it misses the two scaling faults found the week this was written,
because neither adds a statement.

- `formatPrice()` was `O(rows × currencies)` — it re-scanned the currency list on
  every price it rendered. One function call, zero queries, invisible to a count.
- The notification cron's bulk load pulls every active subscription into one PHP
  array. That is a documented round-trips-for-rows trade, but at a million rows it
  needs more than the default 128 MB. Again: no extra query, a memory curve.

Both are shapes a query count and a code read do not show, and both are now fixed.
`dev/benchmark.sh` is the instrument that would have shown them bending — a periodic
measurement of **time and peak memory against a growing N**, read as a curve.

## It is a trend instrument, never a CI gate

This records and displays. It does not assert, and nothing here belongs in
`dev/test.sh`. Gating on a wall-clock time or an absolute memory figure would be
gating on the machine: the same code and the same number of round trips took 2.5 ms
per account over loopback and 10 ms over an overlay network — a 4× spread — and peak
memory drifts with the PHP build, the opcache state and the allocator. A threshold
trips over that constant; a curve read the right way does not.

The right way is the **normalized growth factor**

```
F/R = (how many times the figure grew) / (how many times the size grew)
```

between two adjacent points of a geometric ×10 ladder. The environment's overhead
is one multiplicative constant `k`; dividing two adjacent figures cancels `k`, so an
F/R measured on one machine still means something on another. That single ratio is
the whole detector. Everything else in the script — median of five, the seed through
the database under test, the noop baseline, the bounded runs — is there to make the
two figures F/R divides honest.

## How to read the tables

`dev/benchmark.sh` prints, under each block of medians, an F/R row per ×10 step.

- **F/R ≈ 0** — flat. The figure barely moved; fixed overhead dominates at this size.
- **F/R ≈ 1** — honest linear. For a path that must touch N things — a list that
  draws N rows, a load that holds N rows — this is the **healthy** reading, not a
  fault.
- **F/R clearly above 1, and rising from one step to the next** — super-linear.
  This is the shape to go and look at. An `O(N²)` path reads as F/R ≈ the size
  ratio (≈ 10 per step) and climbing.

**Read the absolute size as well as the shape.** F/R catches the `O(N²)`; it does
*not* catch a fat constant that stays linear. `formatPrice` was `O(rows × currencies)`,
but the currency list is fixed for a request, so on the row axis it was linear — a
steeper line, F/R still ≈ 1, not a bend. The steeper *line* is only visible in the
absolute numbers. So: shape catches the `N²`, absolute size catches the fat constant.
Watching only F/R would have missed `formatPrice`; watching only the absolute number
would call every honest `O(N)` list a regression.

**On a bend, investigate — do not gate.** Find the line that grew, and ask one
question: does the per-row cost recompute something whose answer is the same for the
whole request? If yes, hoist it (that is exactly what the `formatPrice` and
date-formatter fixes did). If no, it is inherent work and it stays — the cron's
per-recipient send loop is linear in recipients because each message must have its
transport and address resolved, and that is not a fault to remove.

## What is measured where, and why

The measurement mode is matched to production, because a figure taken any other way
measures the wrong thing.

- **The read paths (subscription list, statistics) are timed over HTTP**, as the
  rendered pages they are. Re-building them in a CLI would measure a reimplementation,
  not the page; the autoload and render are fixed costs that F/R cancels anyway.
- **The notification cron is timed in-process**, because in production it *is* a CLI
  process. It is the load/build phase only — the bulk-load block up to the send loop.
  It stops before the send loop deliberately: that is where the effective SMTP
  transport is resolved and mail is dispatched, so the load phase opens no SMTP
  session and calls no provider. It is **network-free by construction** — the
  structural version of the guard `--rates` gives the currency column, and the reason
  the notify figure can never quietly grow with a mail server instead of with the
  data. (If you ever measure the *send*, its seed accounts must point at a local mail
  sink, never a reachable MX, for the same reason the currency key is deliberately
  invalid.)
- **Peak memory is reported for the in-process cron only.** `memory_get_peak_usage(true)`
  is what the process took from the OS. The HTTP-timed pages run inside the web
  server, out of the script's reach, so they stay time-only. That asymmetry is
  honest and stated in the script.

Two or three geometric ×10 points, five runs each. Three points, not two, because
two points fit any line and cannot show a bend; three give two consecutive F/R, and a
second larger than the first is the curve bending. The household-sized default is
fast — subscription list `100 / 1000 / 10000`, cron `1 / 10 / 100` users. The largest,
slow step of each ladder is behind `--big`: the 100 000-row list and the cron over a
million active subscriptions (100 users × 10 000), which is the >128 MB case. That
one is the stress test you run on purpose, not on every oil change.

## Read the judgment on PostgreSQL

Run both backends; read the verdict on the **PostgreSQL cron-over-users curve**. A
per-user query is a function call on SQLite — flat, invisible — and a network round
trip on PostgreSQL — linear, visible. That is the whole lesson of the notification
N+1 (#99): flat on the backend it was written for, linear on the backend it runs on.
SQLite stays the cheap counter-check and the fast default; the headline comes from
PostgreSQL.

## The instrument was shown to bend

A detector seen only in its flat state is not one. On PostgreSQL, with the load phase
reproducing three shapes over `1 / 10 / 100 / 1000` accounts (50 subscriptions each),
the F/R told them apart cleanly (median of five, milliseconds):

| load phase | 10 | 100 | 1000 | F/R 10→100 | F/R 100→1000 |
| --- | --- | --- | --- | --- | --- |
| fixed (bulk loads, current `main`) | 16 | 27 | 154 | 0.17 | 0.57 |
| N+1 reintroduced (one query per account, the pre-#99 shape) | 17 | 58 | 480 | 0.34 | **0.83** |
| a per-account scan of all rows (`O(N²)`) | 14 | 54 | 15777 | 0.39 | **29.2** |

The fixed path stays flat (F/R well under 1: the bulk-load fix holds). The N+1 climbs
toward 1 — linear in accounts — and would cross it on any network slower than
loopback, which is where this job actually runs. The `O(N²)` bends to F/R ≈ 29,
unmistakably above the ×10 size ratio. That is the break-and-observe the test gates
get, applied to a curve.

Peak memory over the same load, as active rows grow, is the other half — the figure
the query counts cannot show:

| active rows held | 5 000 | 50 000 | 500 000 |
| --- | --- | --- | --- |
| peak `memory_get_peak_usage(true)` | 10 MiB | 78 MiB | **714 MiB** |

Linear in rows (F/R ≈ 1), which is healthy for a load that holds N rows — but the
absolute figure is the point: half a million active subscriptions is already past the
128 MB default, and a million is past a gigabyte. The trade the loader documents,
made visible.

## A dated record, appended over time

The durable artefact is this table, dated, extended by hand — the same shape
`docs/perf-hot-paths-postgresql.md` keeps for query counts. One measurement shows the
curve's shape today; only an appended series shows **drift** — the cron that creeps
from 2 s to 6 s across three releases without any single threshold ever breaking.

Couple it to work already happening: run it before you touch a hot path, and once per
release. And when a row here stops informing a decision, stop taking it — a
measurement nobody reads is not a check.

### 2026-09-05 — 5.13.0-dev, `main` at the trend-benchmark branch point

Throwaway containers (recipe below), household default. Time in ms, median of five;
peak in MiB.

**Subscription list / statistics (HTTP, time):**

| backend | size | list | stats | F/R list | F/R stats |
| --- | --- | --- | --- | --- | --- |
| SQLite | 100 | 22 | 8 | — | — |
| SQLite | 1 000 | 185 | 33 | 0.84 | 0.41 |
| SQLite | 10 000 | 1710 | 287 | 0.92 | 0.87 |
| PostgreSQL | 100 | 41 | 23 | — | — |
| PostgreSQL | 1 000 | 191 | 46 | 0.47 | 0.20 |
| PostgreSQL | 10 000 | 1733 | 291 | 0.91 | 0.63 |

Every F/R under 1: both read paths are honest `O(N)` and overhead-dominated at the
small end. No bend.

**Notification cron load phase (in-process, time + peak), 50 subscriptions/account:**

| backend | users | load ms | peak MiB | F/R load | F/R peak |
| --- | --- | --- | --- | --- | --- |
| SQLite | 1 | 2 | 2.0 | — | — |
| SQLite | 10 | 3 | 2.0 | 0.15 | 0.10 |
| SQLite | 100 | 15 | 8.0 | 0.50 | 0.40 |
| PostgreSQL | 1 | 15 | 2.0 | — | — |
| PostgreSQL | 10 | 13 | 2.0 | 0.09 | 0.10 |
| PostgreSQL | 100 | 24 | 10.0 | 0.18 | 0.50 |

Flat on both backends: the #99/#18 bulk-load fix holds, and on PostgreSQL — where an
N+1 would show — it stays flat. This is the line a future regression would bend.

## Reproducing

PHP runs only inside the container image, and the shared `wallos-dev` /
`wallos-dev-postgres` must not be disturbed, so use throwaway containers on a private
network, torn down afterwards. This is the same pattern as
`docs/perf-hot-paths-postgresql.md`.

```sh
# Read paths need a web server; the cron curve needs only a CLI. One web instance
# per backend serves both. SQLite:
podman run -d --name wallos-trend-web-sqlite -p 8390:80 \
  -v "$PWD":/var/www/html:Z -e TZ=UTC -e WALLOS_DB_PATH=/tmp/wallos.db \
  docker.io/library/dev-wallos:latest sh /var/www/html/startup.sh

# PostgreSQL (its own throwaway server on a private network):
podman network create wallos-trend-net
podman run -d --name wallos-trend-pg --network wallos-trend-net \
  -e POSTGRES_DB=wallos -e POSTGRES_USER=wallos -e POSTGRES_PASSWORD=wallos-dev \
  docker.io/library/postgres:14-alpine
podman run -d --name wallos-trend-web-pg --network wallos-trend-net -p 8391:80 \
  -v "$PWD":/var/www/html:Z -e TZ=UTC \
  -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST=wallos-trend-pg -e WALLOS_DB_PORT=5432 \
  -e WALLOS_DB_NAME=wallos -e WALLOS_DB_USER=wallos -e WALLOS_DB_PASSWORD=wallos-dev \
  -e WALLOS_DB_SSLMODE=disable docker.io/library/dev-wallos:latest sh /var/www/html/startup.sh
```

Each instance needs one account (`e2e` / `E2ePass123!`) to sign in as; register it
through the running instance, or create it directly against a freshly initialised
schema. Then:

```sh
# household default; add --big for the 100 000-row list and the million-row cron.
WALLOS_PASSWORD=E2ePass123! dev/benchmark.sh \
  --base http://localhost:8390 --user e2e \
  --exec 'podman exec wallos-trend-web-sqlite'

WALLOS_PASSWORD=E2ePass123! dev/benchmark.sh \
  --base http://localhost:8391 --user e2e \
  --exec 'podman exec wallos-trend-web-pg'
```

Teardown removes every `wallos-trend-*` container and the network. The seed and its
prefixed rows are removed by the run itself; a real account is never touched.
