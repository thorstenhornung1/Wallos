# Test run 2026-08-16 — Docker Swarm

Execution of [`docs/test-instance.md`](test-instance.md) against a live Swarm
cluster. Seven of eight tests passed; the eighth was not run because it needs
the admin password.

The point of this instance is that credentials arrive as **files** and never
pass through a form field. Every result below is evidence for or against that.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:latest` → **v5.5.0** |
| Platform | Docker Swarm, 3 managers, task placed on `docker-infra-3` |
| Ingress | Traefik v3.6, `test.hornung-bn.de` / `mail.test.hornung-bn.de` |
| Mail sink | Mailpit, reached over `traefik_public` |
| Accounts | `dummy` (admin, id 1), `dummy2` (id 2) |

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
| 5.2 secret never reaches the browser | *not run* | needs the admin password |
| 5.3 environment-managed fields | **pass** (DB half) | `admin.smtp_address` stays empty while mail is being sent |
| 5.4 a user inherits without being given anything | **pass** | `dummy2` receives via the instance sender |
| 5.5 a user can still run their own transport | **pass** | same recipient, different sender |
| 5.6 notifications actually go out | **pass** | `Difference: 1` → `Email Notifications sent` |
| 5.7 a broken secret file does not fall back | **pass** | send refused, path named, no mail |
| 5.8 cron jobs run clean | **pass** | five jobs, no warning, no fatal |
| 7 OIDC against authentik | **pass**, after five separate fixes | account auto-provisioned, `oidc_sub` set |
| — locale handling (v5.6.0) | **pass**, both paths | instance default *and* `locale` claim, verified separately |

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

### 5.8 — cron jobs

`sendnotifications`, `sendcancellationnotifications`, `updateexchange`,
`sendverificationemails` and `sendresetpasswordemails` all completed without a
PHP warning or fatal error.

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

### Not tested

[#36](https://github.com/thorstenhornung1/Wallos/issues/36) (logout without
`id_token_hint`) and [#37](https://github.com/thorstenhornung1/Wallos/issues/37)
(authentik logout leaves the Wallos session alive). Both are session-lifecycle
behaviour and were out of scope once the login itself worked.

#37 deserves attention beyond this test instance: on a production Wallos with
password login disabled, authentik is the only way in — and "sign out
everywhere" not ending the application session is then a different kind of
problem than it is here.

## Not run

**5.2 — the secret never reaches the browser.** Requires an authenticated
session, and the tester chose not to share the password. The plan documents the
check as three copy-paste `curl` lines; the expected result is `0` for both
`settings.php` and `admin.php`.

**Section 6 (load)** was out of scope for this run. Section 7 was executed —
see above.

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

## Reproducing

Everything in this report came from the plan as written; no step was adapted.
Deployment used `docker stack deploy` directly rather than the cluster's GitOps
pipeline, since the instance is deliberately throwaway and not meant to live in
the infrastructure repository.
