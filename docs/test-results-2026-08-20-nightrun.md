# Night run 2026-08-20 — 5.8.3 on PostgreSQL

Second run of the day, against 5.8.3. The [earlier one](test-results-2026-08-20.md)
covered 5.8.2. Separate file because it is a separate run, on a separate
release, and merging them would hide which result belongs to which.

Everything 5.8.3 changes is verified. One new defect found
([#98](https://github.com/thorstenhornung1/Wallos/issues/98)), and three
corrections to my own earlier work — two of them to the 5.8.2 report.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.3` |
| Digest | `sha256:fbc015f6eb86ed8c844d84a5f7e17eeb9db45ae93be3d4ba7c528b69b5b3905f` |
| Version | `Wallos v5.8.3` |
| Database | PostgreSQL 18.6, dedicated, node-local volume |
| Platform | Docker Swarm, pinned to `docker-infra-3` |

Migrations `000065` and `000066` ran on first start.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| #95 inline handler collisions | **pass** | zero remaining; new gate `tests/cases/inline_handler_test.php` |
| #93 deleting a referenced payment method | **pass** | refused by name, counterproof deletes cleanly |
| #87 deleting a row that matches nothing | **pass** | no false success |
| migration 000066 — failure recording | **pass** | forced failure: count 0 → 1, reason stored |
| cron exit status | **pass** | `strict` off by design, crontab sets it on 12 of 33 lines |
| section 6 — load on PostgreSQL | **pass**, first figures ever | see below |
| benchmark cleanup | **fail** | 11 orphans left → [#98](https://github.com/thorstenhornung1/Wallos/issues/98) |
| PostgreSQL minimum version | **no minimum in 12–18** | 42 tables, 65 migrations on every version |

## Details

### #95 — the collisions are gone, and there is a gate now

```
grep over admin.php and settings.php for id == the function its onClick calls
  -> no output
tests/cases/inline_handler_test.php  -> exists
```

The release found **18** collisions where my issue named seven. I had grepped
two files; the fix compared the whole set of ids against the whole set of names
inline handlers call. Worth recording as a lesson about the shape of the search
rather than the count: a list assembled by hand answers "which of these" and not
"how many are there".

### #93 / #87 — payment method deletion

```
referenced (id=1)
  -> {"success":false,"message":"Zahlungsmethode wird in Abonnements verwendet
      und kann nicht gelöscht werden"}
  -> still present: yes        subscriptions with a dangling reference: 0

id=99999 (matches nothing)
  -> {"success":false}         no false success

unreferenced (id=220)          <- the counterproof
  -> {"success":true,"message":"Zahlungsmethode gelöscht"}
  -> actually removed
```

The third line is what makes the first two mean anything: an endpoint that
refused every deletion would pass both of them and be broken.

The specific message matters too. My first attempt sent `{"paymentId": …}`
where the endpoint reads `id`, and got the generic `Fehler` from input
validation — not the reference check. Had both refusals shared one message I
would have recorded a passing test that never ran.

### Migration 000066 — a failure that a later success does not erase

Forced by running the job with an unreadable secret path, without touching the
service:

```
before:  status=ok      failure_count=0
after:   status=failed  failure_count=1
         last_failure_at   2026-08-20 21:20:04
         detail            the mail transport of user 1 is unusable, …

after a subsequent successful run:
         status=ok      failure_count=2   last_failure_at retained
```

That is the point of the counter. `status` alone is overwritten by the next
good run, so a job dying every third night shows green every morning after it
worked.

It also explains an anomaly from earlier: `sendnotifications` showed `failed`
with `failure_count = 0` and no reason. That row predates the migration and
carries the new columns' defaults. Not a defect.

### Cron exit status — opt-in, and correctly so

```
default:  failure -> exit=0     success -> exit=0
strict:   failure -> exit=1     success -> exit=0
/etc/crontabs/root: 12 of 33 lines set WALLOS_CRON_STRICT=1
```

I nearly filed the first line as a defect against the 5.8.1 promise of "a
non-zero exit status". The reason it is conditional is in the source, next to
the code: `startup.sh` runs under `set -euo pipefail` and invokes four of these
jobs before `wait`, so an unconditional non-zero exit would stop a container
from starting because its currency provider refused a key. The unattended path
— cron — asks for the status. The log line and the recorded row are
unconditional either way.

### Section 6 — load, measured on PostgreSQL for the first time

```
database  pgsql wallos@postgres:5432/wallos, schema public

Subscription list, one user
  entries      list     stats   calendar
  100          78ms      45ms       42ms
  1000        270ms      85ms       70ms
  5000       1300ms     423ms      411ms

Notification cron, all users
  baseline     42ms
  1           587ms     rates skipped
  10          873ms     rates skipped
  100        1576ms     rates skipped

  rates not measured (refused): The currency provider could not be reached.
```

**Observation.** The list is sub-linear: 50× the data for 17× the time. The
cron is not flat — 587 ms for one user, 1576 ms for a hundred, over a 42 ms
baseline.

**Conclusion, kept separate.** The plan's reference table states *"The cron is
flat. 1 user and 100 users cost the same."* That holds for its SQLite figures
and does not hold here. The absolute numbers are not comparable — different
hardware — but the *shape* is: flat against growing. Read as roughly 545 ms of
fixed cost per run plus about 10 ms per user, which is comfortable at this size
and worth re-measuring if an installation grows an order of magnitude.

Three of the tooling problems from
[#91](https://github.com/thorstenhornung1/Wallos/issues/91) are visibly fixed
in the output itself: the run names the database it measured, warns that
`--password` is visible in `ps`, refuses to time the rates cron against a
provider that cannot be reached rather than timing the timeout, and counts the
rows it removes.

### The cleanup still leaves something — #98

```
after the run:  users 5, seed- rows 0, orphans 11
                all 11 in email_notifications, user_id 121…128
```

Three tiers seed 1 + 10 + 100 = 111 users; the cleanup removes 100. The
notification rows of the two smaller tiers stay behind.

Nothing catches it because foreign key coverage on `user_id` is uneven — of the
13 foreign keys in the schema, seven constrain `user_id`, and
`email_notifications` is not among them. Filed as
[#98](https://github.com/thorstenhornung1/Wallos/issues/98). Removed by hand
afterwards; orphans back to 0.

### PostgreSQL minimum version

Run in throwaway containers, isolated from this instance.

| Version | Schema | Tables | Migrations |
| --- | --- | --- | --- |
| 12-alpine … 18-alpine | installs | 42 | 65 |

**There is no minimum in the tested range.** Verified independently rather than
taken on trust: the baseline `includes/database/pgsql/schema.sql` is 679 lines
using `SERIAL` 27 times, with zero occurrences of `gen_random_uuid()`,
`GENERATED ALWAYS AS IDENTITY`, `jsonb`, `ON CONFLICT`, `NULLS NOT DISTINCT` or
generated columns. Nothing in it postdates PostgreSQL 12.

A runtime pass at both ends (12 and 18) — registration, login, and eight pages
— produced no `SQLSTATE`, no `PDOException` and no server-side `ERROR`.

**Not covered:** PostgreSQL 11 and older, and the *upgrade* path where the 65
migrations actually execute against an existing older schema. A fresh install
records them as applied and installs the baseline instead, so this run says
nothing about them.

## Corrections to earlier work

Three, all mine, recorded because the plan asks for it and because each was
caught by a check rather than by noticing.

**Section 6 was never blocked.** The 2026-08-20 report closes on it being
blocked by [#91](https://github.com/thorstenhornung1/Wallos/issues/91). That
issue was already fixed in 5.8.2 — the release that run was testing. The error
was procedural: I took the issue's state from my own earlier note instead of
looking it up.

**The instance silently reverted to 5.8.0 mid-run.** Adding the `OIDC_ADMIN_*`
variables meant `docker stack deploy` from a file still pinning `5.8.0`, which
undid an earlier `docker service update --image 5.8.2` without warning. By
timestamp, everything except the `OIDC_ADMIN_*` verification ran on 5.8.2 as
reported; that one check ran on 5.8.0. The stack file now carries the version
and a comment saying it is the only place to change it.

**The claim about foreign keys was too broad.** The 2026-08-19 report says the
old user-deletion defect "could not have passed silently on PostgreSQL, because
deleting the user row before its children violates a foreign key". Only seven
tables constrain `user_id` — `login_tokens` and `totp` among them, so those
would indeed have failed. `subscriptions`, `categories`, `settings`,
`email_notifications` and the rest have no such constraint.

## Notes on method

Four times tonight a wrong payload of mine produced a response that looked like
an application defect: `paymentId` instead of `id`, snake_case instead of
camelCase, `getenv()` for a variable never exported, and a `>` redirect that
ran on the host instead of in the container. Two would have become issues.

What separated them from real findings each time was a **specific** refusal.
`Fehler` and "payment method is in use" are both rejections; only the second
tells a tester which check they reached. For anything meant to be tested from
the outside, a distinguishable refusal is worth as much as the refusal itself.
