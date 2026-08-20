# Test run 2026-08-20 — 5.8.2 on PostgreSQL

Execution of the 5.8.1 test assignment against the instance built for the
[2026-08-19 run](test-results-2026-08-19.md). All three defects that run filed
are fixed and verified here. No new defect was found.

**Deviation, stated first because everything else depends on it: the version
tested is 5.8.2, not 5.8.1.** 5.8.1 exists but its own changelog reports that its
test suite fails, and the reason is specific to this setup — the regenerated
migration chain and the checked-in PostgreSQL baseline had drifted apart, so *a
fresh PostgreSQL installation was created with a table nothing uses*. Testing a
release known to be broken in exactly the dimension under test would have
produced findings about a version nobody should run. 5.8.2 contains all six
points of the assignment plus that correction.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.2` |
| Digest | `sha256:9faaf8bd81bd9141210f24c197bfa59e3a9eb4e4acdd4124b3435207adf89ed2` |
| Version | `Wallos v5.8.2` |
| Database | PostgreSQL 18.6, dedicated, node-local volume |
| Platform | Docker Swarm, application and database pinned to `docker-infra-3` |
| Accounts | `dummy` (admin, id 1), `dummy2` (id 2), `offen1` (id 118, throwaway) |

```
$ docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' wallos-test_wallos
ghcr.io/thorstenhornung1/wallos:5.8.2
$ php -r 'include "includes/version.php"; echo $version;'
v5.8.2
```

Migration `000064` ran on first start of the new image.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| 1 cron jobs, `stats.php`, budget API | **pass** | five jobs `exit=0 fatal=0`; API returns JSON |
| 2 mixed-case aliases | **pass** | registration gated both ways; order 18/19/20; ids 218/219/220 |
| 3 back-channel revocation reaches endpoints | **partly covered** | guard in shared bootstrap (112 endpoints); malformed tokens refused |
| 3 the same, at runtime | **not covered** | needs an OIDC browser session |
| 4 2FA, backup codes, disable | **pass** | replay refused, fresh code accepted, both flags cleared |
| 5 password reset | **pass** | token consumed, old password dead, replay refused |
| 6 privilege separation | **pass** | `755 root:root`, write to code denied, write to `db/` allowed |
| 8.1 fresh PostgreSQL instance | **pass with a caveat** | 43 tables, not 42 — see below |

## Details

### 1 — the cron fatal is gone, and the exit codes now mean something

```
  sendnotifications              exit=0   fatal=0
  sendcancellationnotifications  exit=0   fatal=0
  updateexchange                 exit=0   fatal=0
  sendverificationemails         exit=0   fatal=0
  sendresetpasswordemails        exit=0   fatal=0

  stats.php                                65092 bytes  TypeError=0
  api/subscriptions/get_period_budget.php    471 bytes  TypeError=0
    {"success":true,"title":"period_budget","period_budget":0,...}
```

The budget API returns JSON where it previously returned 591 bytes of HTML fatal.

**Observation worth keeping.** These five zeros are only meaningful because of
5.8.1. Before it, every job exited 0 whatever happened — the crashing one
included. In the 2026-08-19 run the exit status would have been worthless as
evidence, and was not used as such.

`cron_runs` now records each job. Scheduled runs, not only the manual ones:

```
 job                           | status | started_at          | duration_ms | detail
 sendverificationemails        | ok     | 2026-08-20 03:36:01 |          31 | email verification is not required
 sendresetpasswordemails       | ok     | 2026-08-20 03:36:01 |          40 | nothing queued
 checkforupdates               | ok     | 2026-08-19 22:00:01 |         252 | latest release is v5.8.2
 sendnotifications             | ok     | 2026-08-20 02:38:58 |         117 |
```

**Limitation, not a defect.** The primary key is `job`, so the table holds the
*latest* run per job and no history. A job that fails intermittently and
succeeded most recently looks healthy. Useful as a current-state view; not
sufficient as the only signal if the question is "has this ever failed".

### 2 — the aliases, and the security consequence

All seven sites from
[#89](https://github.com/thorstenhornung1/Wallos/issues/89) now quote the alias:

```
registration.php:31                              as "userCount"
login.php:298                                    as \"userCount\"
endpoints/admin/saveopenregistrations.php:35     as \"userCount\"
api/categories/set_categories.php:92             as "maxOrder"
endpoints/categories/category.php:28             as "maxOrder"
api/payment_methods/set_payment_methods.php:301  as \"maxID\"
endpoints/payments/add.php:237                   as \"maxID\"
```

The only remaining unquoted mixed-case aliases are in
`tests/cases/portable_sql_test.php`, where they are the subject of a new
regression test — including the point that asserting the key rather than the
value is what makes the test real.

Runtime, all three directions:

```
closed registration   -> HTTP 302 to login.php,  account NOT created
open registration     -> HTTP 302,               account created
max_users=1, 3 users  -> HTTP 302,               account NOT created
```

The middle line matters as much as the other two: a test that only checks the
refusal would also pass against an application that had stopped accepting any
registration at all.

Order and id allocation:

```
categories       id=231 order=18 | id=232 order=19 | id=233 order=20   (existing max was 17)
payment_methods  id=218 | id=219 | id=220
```

Previously `max(null + 1, 32)` returned 32 every time, so the second custom
payment method collided on the primary key, and every category was written with
the same order — which silently nullified the 5.8.0 sorting fix.

**Not a defect:** `handleAddCategory()` hardcodes `$categoryName = "Category"`.
The `add` action creates a placeholder and `edit` renames it, so a `name` sent
to `add` is ignored by design.

### 3 — what could and could not be checked

Covered, in code: `includes/connect_endpoint.php:34-35` calls
`wallos_oidc_require_valid_session($db)` from the shared bootstrap, and 112
files under `endpoints/` and `api/` include that bootstrap. That is the 5.8.0
fix — the guard used to live only on the page-rendering path.

Covered, at runtime — the endpoint refuses anything malformed and says nothing
about why:

```
  empty body           -> HTTP 400
  logout_token=x       -> HTTP 400
  logout_token=a.b.c   -> HTTP 400
  log: Wallos OIDC back-channel logout rejected: malformed_token
```

**Not covered:** the assignment's actual test — `200` against an endpoint before
the provider ends the session, `401` after. It requires an OIDC session, and the
guard deliberately does not apply to password logins, so no local account can
stand in for one. A session cookie from a browser signed in through Authentik
would close this.

### 4 — 2FA

```
4a enrol            success=true, 10 backup codes, totp_enabled=1
4b login            redirects to /totp.php, settings.php -> 302
4c/4d replay        consumed code refused, settings.php -> 302
                    last_totp_used = 59573274
4g fresh code       totp.php -> 302 to /, settings.php -> 200
4e backup code      accepted
4f backup replay    refused
4h disable          totp_enabled=0 AND totp rows=0
4i login after      no code required, settings.php -> 200
```

Three things are worth separating here.

**The enrolment code cannot be reused for login.** 4c looked like a failure at
first: the code that had just been accepted by `verify` was refused a minute
later. It is the replay guard working — enrolment consumes the time-step, and
`last_totp_used` records it. Before 5.8.1 that column was never read: the
comparison always ran against 0, so an observed code stayed usable for about
seven and a half minutes.

**A guard that refuses everything would produce the same evidence.** 4g exists
for that reason: a fresh code in a new time window is accepted. Without it, 4c
and 4d prove only that something was refused.

**Disabling must clear both.** 4h checks `totp_enabled` and the `totp` row
together. The 5.8.1 defect left `totp_enabled = 1` with no enrolment row — a
state no credential can satisfy, reached immediately after telling the user 2FA
was switched off. Either value alone would have missed it.

### 5 — password reset

```
5a request           HTTP 200, 1 row in password_resets
5b delivery          "Password reset email sent to offen1@example.com", 1 message
5c token from mail   64 characters
5d set password      HTTP 200, rows in password_resets: 0
5e new password      login 302, settings.php 200
5f old password      login 200, settings.php 302
5g token reused      "Zweiter-Versuch-9" refused
                     first new password still valid  <- the second check
5h unknown address   HTTP 200, no token created
```

5g needs both lines. That the second attempt's password does not work shows only
that it set nothing; that the *first* new password still works is what rules out
the retry having left the account in a third, unusable state.

5h is the same fix seen from the privacy side: an unknown address gets the same
response as a known one and creates no token, so the page cannot be used to
enumerate accounts.

**Precondition the plan does not mention.** `passwordreset.php:41-44` redirects
away silently unless the instance SMTP config is valid **and**
`admin.server_url` is non-empty. It was empty on this instance, which is the
default state, so the whole feature was inert: every request answered 302, no
token, no mail, no message. Two full attempts were recorded as failures before
the cause was found in the source. This belongs in the plan, and arguably the
page should say why it is unavailable rather than redirect.

### 6 — privilege separation

```
/var/www/html                 755 root:root
/var/www/html/db              755 www-data:www-data
/var/www/html/images/uploads  755 www-data:www-data
/var/www/html/.tmp            755 www-data:www-data

www-data writing to endpoints/cronjobs/updatenextpayment.php:
  sh: can't create ...: Permission denied   exit=1
www-data writing to db/:
  exit=0
/etc/crontabs/root: present, 33 lines
```

Both directions on purpose. A container where `www-data` also cannot write `db/`
would satisfy the first check and be unusable.

### 8.1 — 43 tables, not 42

```
baseline (includes/database/pgsql/schema.sql):  42 tables, includes cron_runs, no "notifications"
this instance:                                  43 tables
difference:                                     notifications
```

The instance was created from the 5.8.0 baseline, which declared a standalone
`notifications` table that migration 000016 now drops on a fresh chain. 5.8.2
corrects the baseline for **new** installations; there is no migration that
removes the table from one that already exists. Harmless, but it means the
plan's expected value of 42 holds only for instances created from 5.8.2 onward.
An instance created between 5.8.0 and 5.8.2 will report 43 and be correct.

## Conclusions, kept separate from the observations above

* **All three defects from the previous run are fixed**, each verified by the
  behaviour that was broken rather than by the absence of an error message.
* **The 5.8.1 security fixes hold where they can be reached without a browser.**
  TOTP replay, backup-code single use, the 2FA disable half-state, password
  reset token consumption and account enumeration all behave as described.
* **One point remains genuinely open**, not skipped: back-channel revocation
  against a live endpoint. It cannot be reached from a local account by design.
* **Two preconditions cost more time than the tests themselves** — `server_url`
  for the password reset, and the fact that enrolment consumes a TOTP code.
  Both are correct behaviour and neither is documented where a tester looks.

## Notes for the plan

* Section 5's password-reset test needs a line about `admin.server_url`.
* The 2FA test should say that the enrolment code is consumed, so a login
  attempt with it is expected to fail.
* `dev/benchmark.sh` was not re-run: the tooling defect from
  [#91](https://github.com/thorstenhornung1/Wallos/issues/91) makes its figures
  meaningless on PostgreSQL, and nothing in this release addresses it.
