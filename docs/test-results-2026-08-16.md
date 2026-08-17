# Test run 2026-08-16/17 — Docker Swarm

Execution of [`docs/test-instance.md`](test-instance.md) against a live Swarm
cluster. All eight tests of section 5 passed, as did sections 6 and 7.

The point of this instance is that credentials arrive as **files** and never
pass through a form field. Every result below is evidence for or against that.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:latest`, **v5.5.0 → v5.7.0** during the run |
| Platform | Docker Swarm, 3 managers, task pinned to `docker-infra-3` |
| Ingress | Traefik v3.6, `test.hornung-bn.de` / `mail.test.hornung-bn.de` |
| Mail sink | Mailpit, reached over `traefik_public` |
| Accounts | `dummy` (admin, id 1), `dummy2` (id 2), `thorsten.hornung` (OIDC) |

The pin to a single node is not a detail. Both volumes are node-local — that is
the whole point of the SQLite placement — so a task rescheduled to another node
comes up against an **empty database**. That happened once during the run and
looked exactly like data loss; the data was untouched on the original node. A
`node.hostname` constraint is therefore not optional for this stack, and the
plan's wording "constrained to one node" needs to say which mechanism enforces
it.

Migrations `000054`, `000055` and `000056` completed on first start, which is
what the plan names as confirmation that the instance configuration and the
subscription indexes are in place.

### Secrets arrive with the exact byte length

```
/run/secrets/ai_api_key         14 bytes  = "invalid-ai-key"
/run/secrets/currency_api_key   20 bytes  = "invalid-currency-key"
/run/secrets/smtp_password      18 bytes  = "test-smtp-password"
```

Worth stating explicitly: the plan prescribes `printf`, not `echo`. With
`echo` these would be 15, 20 and 19 bytes, and the trailing newline would make
SMTP authentication fail with a message that points at a wrong password rather
than at one byte too many.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| 5.1 instance SMTP with nothing configured | **pass** | mail from `wallos@test.hornung-bn.de`, `admin.smtp_address` empty |
| 5.2 secret never reaches the browser | **pass** (2 of 3 pages) | `settings.php` and `index.php` clean; `admin.php` unreachable for the account used |
| 5.3 environment-managed fields | **pass** (DB half) | `admin.smtp_address` stays empty while mail is being sent |
| 5.4 a user inherits without being given anything | **pass** | `dummy2` receives via the instance sender |
| 5.5 a user can still run their own transport | **pass** | same recipient, different sender |
| 5.6 notifications actually go out | **pass** | `Difference: 1` → `Email Notifications sent` |
| 5.7 a broken secret file does not fall back | **pass** | send refused, path named, no mail |
| 5.8 cron jobs run clean | **pass** | five jobs, no warning, no fatal |
| 6 load | **pass** | list scales linearly to 5000 entries; cron at baseline for 100 users |
| 7 OIDC against authentik | **pass**, after five separate fixes | account auto-provisioned, `oidc_sub` set |
| 7.x back-channel logout | **pass** | provider POSTs a signed token, `1 session(s) revoked` |
| 7.x post-logout redirect | **pass** in a clean session | fails against a stale provider session — cause found in authentik, not Wallos |
| — locale handling (v5.6.0) | **pass**, both paths | instance default *and* `locale` claim, verified separately |
| — admin role migration (v5.7.0) | **pass** | role moved to `user_roles`, `dummy` kept it |

## Details

### 5.1 / 5.3 — configuration lives outside the database

The admin sent a test mail without entering a server, a password or a sender.
While that mail was on its way, the database said:

```
smtp_address  smtp_port  from_email
------------  ---------  ----------
(empty)          587      (empty)
```

Mail delivered while no SMTP host is stored is the clearest possible evidence
that the effective configuration comes from the environment — and that it is
not quietly mirrored into the database on the way.

### 5.4 / 5.5 — inheritance and isolation, side by side

Per-user configuration after both tests:

```
user_id  enabled  smtp_address  smtp_port  from_email
   1        1       (empty)        587      (empty)
   2        1       mailpit       1025      user2@test.hornung-bn.de
```

Mailbox:

```
wallos@test.hornung-bn.de  ->  dummy2@example.com     (5.4, inherited)
user2@test.hornung-bn.de   ->  dummy2@example.com     (5.5, own transport)
```

The same recipient, two different senders. `dummy2` first received a working
delivery **without ever seeing a credential**, then switched to a transport of
their own — and the instance configuration neither prevented that nor
overwrote it afterwards.

### 5.6 — the job explains its decision

```
Subscription: Test
Next payment date: 2026-08-17
Current date: 2026-08-16
Difference: 1

Email Notifications sent
```

Printing the computed difference matters more than it looks: when a
notification does not arrive, this output alone distinguishes "the job never
considered this subscription" from "it tried and the transport failed".

### 5.7 — the one that matters

With `WALLOS_SMTP_PASSWORD_FILE` pointed at a file that does not exist:

```
Email notifications not sent: Secret file is not readable: /run/secrets/does-not-exist
```

Mailpit received nothing. Three properties hold at once, and each of them is
missing from plenty of applications:

1. the send **refuses** instead of attempting and failing
2. the message **names the path**, so the unreadable file is identified rather
   than reported as a generic SMTP error
3. **no silent fallback** to a value in the database

The third is the reason this test exists. The dangerous case is a half-finished
rotation: a new secret is deployed, the path is wrong, and the application
keeps running on the old one. Everything looks healthy until the old secret is
revoked, and the outage then arrives weeks later with no visible connection to
the change that caused it.

Restoring the correct path made delivery work again in the same run.

### 5.2 — the secret never reaches the browser

Run with a session for `dummy2`:

```
Login-Status: 302
settings.php     0 Treffer
index.php        0 Treffer
```

Zero occurrences of the SMTP password in either rendered page. The check is
worth more than it looks: the value *is* in scope on `settings.php`, because
that page renders the mail configuration form. A field pre-filled with the
current password — the obvious, convenient implementation — would put the
secret into the HTML of every page load, where it survives in browser cache,
in a saved page, and in any screenshot of the settings screen.

`admin.php` could not be covered: `dummy2` is not an administrator, so the page
returns a redirect rather than content, and a `0` from it would prove nothing.
Closing that gap needs an admin session, which is a different test than this
one — the plan should say so instead of listing three pages as if they were
interchangeable.

### 5.8 — cron jobs

`sendnotifications`, `sendcancellationnotifications`, `updateexchange`,
`sendverificationemails` and `sendresetpasswordemails` all completed without a
PHP warning or fatal error.

## Section 6 — load

Measured with `dev/benchmark.sh` from the image, inside the running container,
median of five runs per cell. This is a homelab VM on shared hardware, so the
absolute numbers describe *this* deployment; the shape of the curve is the part
that transfers.

```
Subscription list, one user
  entries            list      stats   calendar
  100                 36ms        10ms         9ms
  1000               282ms        53ms        38ms
  5000              1418ms       263ms       177ms

Notification cron, all users
  users            notify      rates
  baseline             0ms          -
  1                    0ms        0ms
  10                   0ms        0ms
  100                  0ms        0ms
```

**The list scales linearly.** 100 → 1000 entries costs a factor of 7.8, and
1000 → 5000 a factor of 5.0 for five times the data — roughly 0.28 ms per entry
across the whole range. A query per row would bend upward instead, and at 5000
entries would land in the seconds, not at 1.4.

**`stats` is the stronger evidence.** It converts every subscription into the
main currency, which is exactly where a per-row query tends to appear. At 5000
entries it costs 263 ms — a fifth of the list, and growing *more slowly* than
it. A column that gets relatively cheaper as the data grows is not doing
per-row work.

**The cron stays at baseline.** The baseline row is an empty script: interpreter
start-up alone, included in every figure above it. At 100 users the notification
job is indistinguishable from it. Whatever the per-user cost is, it disappears
against process start-up — which also means this benchmark cannot resolve it,
and a future run wanting that number needs a different instrument.

Index use was confirmed separately rather than inferred from timing:

```
SEARCH subscriptions USING INDEX idx_subscriptions_user_inactive_next_payment (user_id=?)
```

No temporary b-tree for sorting — the index carries the ordering. Timing alone
would not have distinguished this from a fast sequential scan at these sizes.

The benchmark removes its seeded data when it finishes, which it did: the three
real accounts were the only rows left afterwards.

## Section 7 — OIDC against authentik

Working, but it took five separate causes to get there. Two of them produced
issues in this repository.

Environment: authentik 2026.5.6, provider and application both named
`wallos-test`, image upgraded to **v5.5.2** mid-run (see below).

### What went wrong, in order

| # | Cause | What the user saw |
| --- | --- | --- |
| 1 | Redirect URI `…/login.php` — that file did not consume the callback | login page, no message |
| 2 | Redirect URI mismatch after fixing 1 (`…` vs `…/login.php`) | authentik error page |
| 3 | Application had lost its provider assignment | "Permission denied" |
| 4 | Provider still on the default `email` scope mapping (`email_verified: False`) | would have produced `oidc_email_not_verified` |
| 5 | Two providers with near-identical names — edits landed on the wrong one | nothing at all |

Causes 2–5 were on the authentik side. Cause 5 is worth naming precisely,
because it cost two rounds: creating the application generated a second
provider called `Provider for wallos-test`, sitting directly beneath the
manually created `wallos-test`. Only the generated one was linked to the
application, so every change to the other had no effect and produced no
warning.

Both were only diagnosable by querying authentik's database. That observation
became [#43](https://github.com/thorstenhornung1/Wallos/issues/43).

### #42 — found and fixed during this run

Following section 7 as written, the callback arrived at `login.php` and was
discarded: `login.php` never included `checksession.php`, the only file
reading `$_GET['code']`. Filed as
[#42](https://github.com/thorstenhornung1/Wallos/issues/42), fixed in **v5.5.2**
by moving the handler into `includes/oidc/consume_oidc_callback.php` and
including it from both entry points.

Verified against the running instance afterwards:

```
login.php?code=…&state=…   ->  302  Location: login.php?error=oidc_invalid_state
index.php?code=…&state=…   ->  302  Location: login.php?error=oidc_invalid_state
login.php                  ->  200  (unchanged)
```

The invalid state is expected — the probe had no session. What matters is that
a response exists at all where there was silence before, and that the ordinary
page render is untouched.

### Auto-provisioning

Login succeeded and created the account:

```
id  username          email                   language  main_currency  oidc
 1  dummy             dummy@example.com       de        1              0
 2  dummy2            dummy2@example.com      de        35             0
 3  thorsten.hornung  thorsten@hornung-bn.de  en        69             1
```

`language=en` while manually created users have `de` — the known limitation
from [#34](https://github.com/thorstenhornung1/Wallos/issues/34) /
[#35](https://github.com/thorstenhornung1/Wallos/issues/35) /
[#40](https://github.com/thorstenhornung1/Wallos/issues/40), reproduced.

**Fixed in v5.6.0 and verified here — both paths, separately.**

The two paths produce the same visible outcome, so they were separated by
making them disagree: the instance default was set to a language nobody
involved uses.

*Instance default.* With `WALLOS_DEFAULT_LANGUAGE=de` and the stock `profile`
scope mapping, a freshly provisioned account got `de` instead of `en`. That
this proves the *default* and not the claim is only certain because
authentik's stock mapping was checked first — it emits `name`, `given_name`,
`preferred_username`, `nickname` and `groups`, and **no `locale`**. There was
no claim to act on.

*Claim.* A custom `profile` scope mapping was then assigned to the test
provider, extending the stock expression by one line:

```python
"locale": request.user.attributes.get("settings", {}).get("locale"),
```

authentik keeps the value as `settings.locale` — its own UI preference, not
something the stock mapping exposes. With `WALLOS_DEFAULT_LANGUAGE=ja` and
`settings.locale = "de-DE"` on the authentik account, the provisioned user
came out as:

```
id  username          language  oidc
 5  thorsten.hornung  de        1
```

`de`, not `ja`. Three things at once: the claim arrives, it is evaluated, and
`de-DE` is normalised to `de` — authentik sends the regional form, Wallos
ships its translations as `de`. The precedence is right as well: the claim
wins over the instance default, which is what a default should be.

Worth recording for anyone wiring this up against authentik: **the stock
`profile` mapping carries no locale.** Without a custom mapping the claim path
cannot work, no matter how the application behaves. Noted in
[#43](https://github.com/thorstenhornung1/Wallos/issues/43).

The differing `main_currency` values are **not** a finding: all three resolve
to EUR. Wallos stores currencies per user, so each account gets its own row.

### Logout — both halves, tested against v5.7.0

[#36](https://github.com/thorstenhornung1/Wallos/issues/36),
[#48](https://github.com/thorstenhornung1/Wallos/issues/48) and
[#49](https://github.com/thorstenhornung1/Wallos/issues/49) are covered here.
Provider configuration: post-logout redirect URI
`https://test.hornung-bn.de/login.php?logged_out=1` in **strict** mode, logout
URI `https://test.hornung-bn.de/backchannel-logout.php`, method **back-channel**.

**Back-channel logout works.** Container log across four attempts:

```
06:36:00  GET  /logout.php              302
06:36:02  POST /backchannel-logout.php  200   -> 0 session(s) revoked.
06:36:33  POST /backchannel-logout.php  200   -> 1 session(s) revoked.
06:37:11  POST /backchannel-logout.php  200
```

Two of them report `0 session(s) revoked`, which is the expected outcome and
not a miss: `/logout.php` had already ended the local session before the token
arrived. Nothing left to revoke is a correct answer.

The successful revocations carry a second result with them. Wallos only accepts
a `logout_token` whose signature it can verify against the JWKS from the
provider's discovery document. `1 session(s) revoked` therefore proves the
whole chain — discovery fetched, `jwks_uri` resolved, signature validated.
That is precisely the chain
[#78](https://github.com/thorstenhornung1/Wallos/issues/78) reports as missing
when OIDC is configured through the interface instead of the environment. This
instance sets `OIDC_ISSUER` as a variable, so it exercises the working path —
and in doing so shows what the other path would lose.

**The post-logout redirect works, but not against a stale provider session.**
The first attempts ended on a page showing nothing but `Logout successful`,
with the browser stopped at the end-session URL. The redirect parameters were
all present and correct, and the registered URI matched the transmitted one
byte for byte.

The cause is in authentik, in `providers/oauth2/views/end_session.py`:

```python
def dispatch(self, request, *args, **kwargs):
    # Check if we're already in an active logout flow
    # (being called from an iframe during single logout)
    if SESSION_KEY_PLAN in request.session:
        return HttpResponse(
            "<html><body>Logout successful</body></html>", ...
        )
```

Hard-coded HTML, returned before any of the logout logic runs. The guard exists
for iframe-based single logout; an abandoned flow plan left in the session looks
the same to it. Since `dispatch()` precedes `get()`, the request that hits this
branch sends **no** back-channel token either — the two symptoms have one cause.

Confirmed from both directions. The same end-session URL requested without a
session cookie produced a clean `302` to the authentication flow, never the
hard-coded page — so nothing is wrong server-side. And repeating the logout in
a fresh private window redirected correctly. What triggers it is logging out of
authentik *first* and then asking Wallos to log out: `EndSessionView` extends
`PolicyAccessView` and requires an authenticated session, so that order inverts
its precondition.

Nothing to change in Wallos, and nothing in the provider configuration. It does
belong in the test plan, because the failure is silent, the page says
"successful", and every parameter looks right while it happens.

### Admin role migration (v5.7.0)

The role moved from `user.id == 1` to a stored role in `user_roles`. On upgrade
`dummy` (id 1) kept administrator rights, which is what the release notes
promise. Relevant to this instance specifically: with OIDC auto-provisioning,
the id-1 rule handed administrator to whoever authenticated first — here that
was a manually created account by luck, not by design.

### Discovery caching (v5.7.1)

Released mid-run in response to
[#78](https://github.com/thorstenhornung1/Wallos/issues/78). The visible change
for this instance is not the issuer field — `OIDC_ISSUER` is set here, so that
path was already working — but a second problem found alongside it: the
discovery document was fetched during configuration resolution, and `login.php`
resolves on every page render.

Measured on the same container, ten requests to `login.php`, immediately before
and after the update:

```
v5.7.0   min 450 ms | median 473 ms | max 496 ms
v5.7.1   min   3 ms | median   3 ms | max 516 ms
```

The single 516 ms is the point, not a blemish: it is the first request, the one
that fills the cache, and every request after it costs 3 ms. A uniformly
improved figure could have come from a provider having a better minute; one
expensive request followed by nine cheap ones is the signature of a cache and
of nothing else.

The median falls by a factor of 157. What it removes is a **synchronous
dependency of the login page on the identity provider** — invisible in normal
operation and worst exactly when the provider is already struggling, since a
sick provider costs the full 10-second timeout on a page that has not yet
authenticated anybody.

Cache state after the upgrade: table `oidc_discovery_cache`
(`issuer`, `document`, `fetched_at`), one row.

Migration `000063` completed, and the data survived it: `dummy` kept `admin`
with source `local`, all four accounts kept `language = de`.

## Not run

**5.2 against `admin.php`.** Covered for `settings.php` and `index.php`; see
above for why the third page needs an administrator session and a `0` from a
redirect proves nothing.

**Section 8 (teardown).** The instance is still running, deliberately.

## Observations

Not defects — details a future run should expect:

* **`notify_days_before` was `-1`** on the test subscription, not the `1` the
  plan describes. Notifications still went out; the value appears to mean "use
  the default" rather than "one day". Worth pinning down if a future test wants
  to exercise the lead time itself.
* **`notification_settings` stayed empty** for both users throughout, and
  nothing depended on it.
* **The Test button works before saving.** In 5.1 a mail was delivered while
  `email_notifications` still had no row at all — the button evidently resolves
  the instance configuration directly. The scheduled job does not: it checks
  `!empty($emailConfig['values']['enabled'])`
  (`endpoints/cronjobs/sendnotifications.php:118`), so 5.6 produced nothing
  until notifications were enabled **and saved**. A green Test button is
  therefore not evidence that scheduled mail will be delivered.
* **The mailbox briefly showed zero messages** between two checks, without
  Mailpit having restarted (task uptime was continuous). Unexplained; it did
  not affect any result, because every test was verified against the message
  list rather than a count.
* **A rescheduled task looks exactly like data loss.** Both volumes are
  node-local, so when Swarm moved the task to a different node the instance came
  up with an empty database and every account appeared to be gone. Nothing was
  lost. Any test plan that places SQLite on node-local storage has to pin the
  task, and should say so where it describes the volumes rather than in passing.
* **`VERSION` is no longer in the image.** Checking the running version now
  means reading the image tag from `docker inspect`. Worth knowing before
  writing a check that greps a file which stopped existing.

## Reproducing

Everything in this report came from the plan as written; no step was adapted.
Deployment used `docker stack deploy` directly rather than the cluster's GitOps
pipeline, since the instance is deliberately throwaway and not meant to live in
the infrastructure repository.
