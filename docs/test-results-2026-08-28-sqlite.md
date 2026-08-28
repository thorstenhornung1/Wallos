# Test run 2026-08-28 — 5.8.6 on SQLite, sections 4 and 5

Sections 4, 5 and 7 of `docs/test-instance.md` had not been driven by a human
on SQLite since 5.7.0 — `docs/next-steps.md` called that the larger gap than
another PostgreSQL confirmation. This run closes sections 4 and 5. Section 7
(OIDC) stays open: it needs an Authentik to talk to.

Companion run: the PostgreSQL 18 release check of the same day is
`docs/test-results-2026-08-28.md`, driven on test.hornung-bn.de. This run is
local on purpose — the shared instance's currency key is live (#104, #117);
the local one is deliberately invalid, which is what made 5.8 here safe.

**Result: every section passes. Section 4 found one defect on the way —
[#119](https://github.com/thorstenhornung1/Wallos/issues/119), one missing
comma that has kept every admin-page button dead since 5.8.1.**

## Environment

Local development stack, `dev/compose.yaml`: the working copy mounted into the
container, SQLite by default, Mailpit receiving everything at
http://localhost:8025. Not the shared test instance.

```
$ git rev-parse --short HEAD
d4e3436                      # v5.8.6 release commit plus docs

$ podman ps --format '{{.Names}}'
wallos-dev  wallos-dev-postgres  wallos-dev-mailpit
```

Read from the running application rather than from memory — the admin page's
Database panel (#102), which this backend answers honestly:

```
Backend:  SQLite 3.53.2   (not configured — this is the default)
Data:     /var/www/html/db/wallos.db
```

The update check on the same page reported "latest release is v5.8.6".

Fresh state: the stack was stopped, `db/wallos.db` and `db/setup_token.db`
deleted, and the stack restarted, so that section 4's "the first account you
register becomes the admin" meant something. Fixture credentials, throwaway by
design: `admin` / `qa-admin-2026!`, `user2` / `qa-user2-neu-2026!` (the
password the 5.9 reset left behind).

## 4. First accounts — pass, with a defect found

Registration offered the first account without a setup token (the token gates
"Restore Database" only), accepted `admin`, and answered "Registration
successful". Login works; the first account is the admin.

**Creating `user2` through Admin → Users does nothing.** The form fills, the
click dies silently. The console says why:

```
SyntaxError: Unexpected identifier 'default_language'   admin.js?v5.8.6:223
ReferenceError: addUserButton is not defined            admin.php:306
```

A missing comma in an object literal — introduced by `7e5cbe4` on 2026-08-16,
shipped in every release 5.8.1 through 5.8.6 — is a parse error, and a parse
error discards the whole file: every admin-page button whose handler lives in
`admin.js` is dead. Filed as
[#119](https://github.com/thorstenhornung1/Wallos/issues/119), together with
the gate it asks for (`node --check` over `scripts/*.js` in CI).

The endpoint behind the button is fine, which is exactly why no server-side
test ever noticed. Workaround used to continue the run:

```sh
curl -s -X POST "$BASE/endpoints/admin/adduser.php" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -b "$COOKIES" \
  -d '{"username":"user2","email":"user2@example.com","password":"…"}'
→ {"success":true,"message":"Success"}
```

Incidental confirmation: `user2`, created without a language choice, came up
in German — `WALLOS_DEFAULT_LANGUAGE: de` from the compose file, applied
exactly as the feature describes.

## 5.1 Instance SMTP serves a user who configured nothing — pass

As admin, Settings → Notifications → Email already said **Use instance SMTP**
with `SMTP Address: mailpit:1025` and `SMTP Password: Managed externally`.
Enabled, saved ("Notification settings saved successfully"), pressed Test:

```
wallos@example.com -> admin@example.com   Wallos Notification
```

No server, password or sender was ever entered.

## 5.2 The secret never reaches the browser — pass

Session and CSRF token built exactly as the plan prescribes; the grep target
is the real local secret from `dev/secrets/smtp_password` (22 characters).

```
settings.php: 0
index.php:    0
admin.php     rendered: 1 ('Instance Integrations')   secret: 0
```

The admin.php count is trusted only because the render proof is non-zero — a
grep on a redirect proves nothing, as the plan itself warns.

## 5.3 Environment-managed fields cannot be edited — pass

```
POST endpoints/admin/savesmtpsettings.php {"smtpaddress":"evil.example.com",…}
→ {"success":true,"message":"Success"}

stored host: []
```

The request is valid, the answer says so, and the managed field was skipped,
never written. The effective host keeps coming from `WALLOS_SMTP_HOST`.

## 5.4 A user inherits without being given anything — pass

As `user2`: Email shows *SMTP der Instanz verwenden*, `mailpit:1025`, *Extern
verwaltet*. Test delivered (`wallos@example.com -> user2@example.com`, subject
localized: "Wallos Benachrichtigung"). Fixer shows *Anbieter: apilayer.com*,
*API Key: Extern verwaltet*; AI shows *chatgpt*, *gpt-4o-mini*, *Extern
verwaltet*. `user2` never saw a credential.

## 5.5 A user can still run their own transport — pass

`user2` switched to custom SMTP (`mailpit`, 1025, none, sender
`user2-custom@example.com`), saved, tested:

```
user2-custom@example.com -> user2@example.com
```

Their transport, not the instance's.

## 5.6 Notifications actually go out — pass

Notifications enabled **and saved** (5.4/5.5), subscription "Melde-Test"
created due tomorrow (relative to the run, as the plan insists), then:

```
$ podman exec wallos-dev php /var/www/html/endpoints/cronjobs/sendnotifications.php
2026-08-28 06:14:47
Subscription: Melde-Test
Next payment date: 2026-08-29
Current date: 2026-08-28
Difference: 1

Email Notifications sent
```

One mail, through the transport the user resolves to —
`user2-custom@example.com -> user2@example.com`. The admin account, with
notifications enabled but nothing due, produced no output and no mail: since
#99 step 3 it is skipped before its rows are even loaded. In CLI mode that
skip is silent; the explanation lines above are the job's whole decision, and
they match the documented shape unchanged.

## 5.7 A broken secret file does not fall back — pass

`dev/secrets/smtp_password` moved aside on the host; the read-only mount
reflects into the container immediately, no restart needed (the configuration
is resolved per request):

```
POST testemailnotifications {"smtpmode":"instance"}
→ {"success":false,"message":"Secret file is not readable: /run/secrets/smtp_password"}

admin.php: " Secret file is not readable: /run/secrets/smtp_password"
```

It refuses and names the path — no quiet fallback to a database password.
File restored, counter-check: `{"success":true,"message":"Notification sent
successfully"}`, mail delivered.

## 5.8 Cron jobs run clean — pass, with the #101 delta

All five jobs, no PHP warning or fatal anywhere. The plan (written before
 #101) expects `updateexchange` to say the provider "could not be reached";
since 5.8.6 it says which failure actually happened:

```
For user: admin
Exchange rates update failed. The currency provider rejected the API key (HTTP 401).
For user: user2
Exchange rates update failed. The currency provider rejected the API key (HTTP 401).
[Wallos cron] ERROR job=updateexchange duration=514ms …
```

Expected improvement, not a failure — the local key is invalid on purpose.
Worth noting for #117: the job contacts the provider **once per user** (two
users, two rejections in one run).

## 5.9 Password reset, and the setting that makes it inert — pass, all four

`admin.server_url` set first, as the plan warns — without it every request
answers 302 with no token and no message.

* Request for `user2@example.com`: "Reset email sent. Please check your
  email.", one row in `password_resets`, mail delivered by the
  `sendresetpasswordemails` cron, token 64 hex chars.
* New password set ("Password reset successful"), token row consumed (0
  left). **New password logs in (302), old one is refused.**
* **Replay refused**: same token again answers with the error box and no
  success; the password sent with the replay does not log in.
* **The first new password still works after the refused replay** — the retry
  left no third state.
* **An unknown address answers byte-identically** to a registered one ("Reset
  email sent. Please check your email.", same success box) **and creates no
  token** (count stays 0). The form cannot be used to enumerate accounts.

One lesson for the next runner: the set-password POST needs `password` *and*
`confirm_password`. A request with only `password` matches no branch at all —
the page re-renders, nothing happens, the token stays valid. The first
attempt of this run did exactly that and briefly read like a defect; it was
the request, not the application.

## Open

* **Section 7 (OIDC)** — still nobody has driven it on SQLite since 5.7.0.
  Needs an Authentik; a throwaway one in `dev/compose.yaml` or the real one.
* **The progress bar on the subscriptions list** — on test.hornung-bn.de the
  list shows no bars since the 5.8.6 update while the details modal does.
  Under investigation as a possible regression of `74fe954` at the time of
  writing; not counted as a pass or fail here.
* **#119** — the fix is one comma; the gate (`node --check` in CI) is the
  part worth doing properly.
