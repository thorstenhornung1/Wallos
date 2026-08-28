# Test run 2026-08-28 (OIDC) — 5.8.7 on PostgreSQL, section 7 end to end

Section 7 was the last section of the plan never run against this instance —
"not covered" or "needs a browser session against Authentik" in every report
since 5.7.0. This run closes what a headless client can close and states
precisely what it cannot, and why.

**What a curl client against the family's *productive* Authentik can prove, it
proves; what needs an interactive provider login it does not fake.** Authentik
is the real SSO of the household. It was touched only as an ordinary login
client (the authorize redirect and one read-only flow-executor probe, no
credentials submitted), never as an administrator, and no user, group, provider
or blueprint was changed. There is no QA OIDC credential provisioned for
headless use, and the operator's own Authentik password is out of scope — so
every check that needs a *completed* OIDC sign-in is recorded as **not
coverable without operator action** rather than forced.

**Three results carry today's context and must be read with it:**

* **Single Logout is now deliberate.** Both Wallos providers were moved to
  `default-invalidation-flow` this morning
  (`swarm-stacks/stacks/apps/authentik/blueprint-provider-invalidation.yml`).
  An OIDC logout now ends the *whole* Authentik browser session, not just the
  app session. The plan's 7.3 was written before this and still describes the
  old "logged out of application only" behaviour; that gap is an intended
  configuration change, not a Wallos defect.
* **#123 is present in 5.8.7, as known red.** The remember-me logout path
  (session restored from the `wallos_login` cookie after a container restart)
  still sends `post_logout_redirect_uri` without `id_token_hint`, which
  Authentik answers `400`. Confirmed here at the code level against the shipped
  tag; the live reproduction is already recorded in
  [#123](https://github.com/thorstenhornung1/Wallos/issues/123). The fix lives
  on `fix/oidc-remember-logout` and is **not** in 5.8.7.
* **No new Wallos defect was found.** Everything that could be exercised
  behaved as the plan and the code say it should.

## Environment

Read from the running instance, per section 9 — every line below is command
output.

```sh
$ docker service inspect wallos-test_wallos \
    --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}'
ghcr.io/thorstenhornung1/wallos:5.8.7@sha256:5a582aa9bc1b744668e2271bea22b86ea06e9c2adec33e9f7298f8e17b2ebf59

$ docker exec $(docker ps -qf name=wallos-test_wallos) \
    php -r 'include "/var/www/html/includes/version.php"; echo "Wallos $version\n";'
Wallos v5.8.7

$ docker exec $(docker ps -qf name=wallos-test_wallos) env | grep '^WALLOS_DB_' | sort
WALLOS_DB_DRIVER=pgsql
WALLOS_DB_HOST=postgres
WALLOS_DB_NAME=wallos
WALLOS_DB_PASSWORD_FILE=/run/secrets/db_password
WALLOS_DB_PORT=5432
WALLOS_DB_SSLMODE=disable
WALLOS_DB_USER=wallos

$ docker exec $(docker ps -qf name=wallos-test_wallos) env | grep '^OIDC_' | grep -v SECRET | sort
OIDC_ADMIN_CLAIM=groups
OIDC_ADMIN_VALUE=admin
OIDC_AUTO_CREATE_USER=true
OIDC_CLIENT_ID=ZG8yV8m4UECN5PJM5RdbohFoyh4UqRTaktijjIZg
OIDC_ENABLED=true
OIDC_ISSUER=https://auth.hornung-bn.de/application/o/wallos-test/
OIDC_PROVIDER_NAME=Authentik
OIDC_REDIRECT_URL=https://test.hornung-bn.de/login.php

$ docker exec $(docker ps -qf name=wallos-test_wallos) php -r '
    require "/var/www/html/includes/database/connection.php";
    $d = wallos_database_connect();
    printf("%s | %s\n", $d->driver(), $d->scalar("SELECT version()"));
    printf("migrations: %d\n", (int) $d->scalar("SELECT COUNT(*) FROM migrations"));'
pgsql | PostgreSQL 18.6 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit
migrations: 66
```

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.7` |
| Digest | `sha256:5a582aa9bc1b744668e2271bea22b86ea06e9c2adec33e9f7298f8e17b2ebf59` |
| Version | `Wallos v5.8.7` |
| Driver, from the environment | `WALLOS_DB_DRIVER=pgsql` |
| Database, from the connection | PostgreSQL 18.6, dedicated, node-local volume |
| Schema | 42 tables, 66 migrations |
| Platform | Docker Swarm, pinned to `docker-infra-3`; container started 2026-08-28 04:56:47 UTC |
| Accounts | `qaadmin` (admin, id 1, local), `admin` (id 113, OIDC), `thorsten.hornung` (id 114, OIDC) |
| SSO | Authentik (productive), provider `Provider for wallos-test`, app slug `wallos-test`, invalidation flow `default-invalidation-flow` (since 2026-08-28) |

Local UI/endpoint tests ran as `qaadmin` through `https://test.hornung-bn.de`
with a curl cookie jar and the CSRF token from `settings.php`, driven from
`docker-infra-3`. The `qaadmin` password was read from
`/srv/data/wallos-qa/qa-admin.pw` into a shell variable and never printed. The
OIDC client secret was never read; its non-exposure was checked without it.
Database checks went through `docker exec` into the `postgres` container,
read-only except for the one throwaway account named under Fixtures.

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| 7.1 admin from group claim — current state + config + code | **pass** | 113 holds admin/`oidc`, 114 (OIDC) holds nothing, `qaadmin` holds admin/`local`; env-managed claim/value |
| 7.1 dynamic grant/revoke on a *fresh* login | **not coverable** | needs a completed Authentik sign-in; no QA OIDC credential |
| 7.2 the two historical limitations are still fixed | **pass** | locale fix (5.6.0) and back-channel logout both stand; section lists no live limitation |
| 7.3 Wallos initiates OIDC + builds end-session URL | **pass** | authorize URL correct; discovery yields `end_session_endpoint`; logout.php sends hint+redirect+state |
| 7.3 fresh-login logout, end to end (Single Logout) | **not coverable** | needs a completed OIDC session; behaviour change documented below |
| 7.3 remember-me logout → Authentik 400 | **known red (#123)** | present in 5.8.7 by code; live repro already filed |
| 7.4 back-channel logout — refusal path | **pass** | empty/malformed `logout_token` → `400 invalid_request`; GET → `405`; `Cache-Control: no-store` |
| 7.4 back-channel logout — end to end | **not coverable** | needs a provider-signed `logout_token` and a live OIDC session |
| 7.5 revocation reaches endpoints (401) | **not coverable** (code present) | guard wired into `connect_endpoint.php` in 5.8.7; runtime 200→401 needs an OIDC session |
| 7.5 OIDC client secret not in the browser | **pass** | admin.php field `value="" disabled`; API `client_secret_set:true`, value is the placeholder |
| 7.5 enrolment consumes a TOTP code | **pass** | replayed enrol-step code refused; next-window code signs in |
| 7.5 account deletion removes every row | **pass** | self-service delete → completeness check prints only `done`; `login_tokens` gone |

## Details

### 7.1 — administrators from a group claim

Configured through the environment (`OIDC_ADMIN_CLAIM=groups`,
`OIDC_ADMIN_VALUE=admin`), so the two fields are greyed out in the interface —
`admin.php` renders them `data-managed-by="OIDC_ADMIN_CLAIM"` /
`"OIDC_ADMIN_VALUE"`, matching the plan's "set it from the environment, which
then takes precedence." The live role state, three accounts, three outcomes:

```
 id  | username         | role  | source | created_at
   1 | qaadmin          | admin | local  | 2026-08-24 19:36:26   <- local grant, untouched by OIDC
 113 | admin            | admin | oidc   | 2026-08-25 04:41:07   <- group "admin" matched, role source oidc
 114 | thorsten.hornung | (none)| (none) |                       <- OIDC account, NOT handed the role
```

All three lines are needed, and they carry the same weight as the 2026-08-20
run: 113 proves the claim is read and matched; 114 proves it is not handed to
every OIDC account; `qaadmin` proves a locally granted role is never touched by
the provider — the way back in if the claim name is ever wrong.

`includes/oidc/admin_role_sync.php` (5.8.7) confirms the rest by reading rather
than by exercising: matching is exact and case-sensitive, the sync runs on
**every** login (so revocation takes effect at next sign-in), and only rows with
source `oidc` are ever written.

**What this is not.** These rows are the residue of a *prior* login (113 last
signed in 2026-08-25), not a sign-in performed in this run. The dynamic proof —
remove the group in Authentik, sign in, watch the role drop — needs a completed
OIDC login this client cannot perform (see *Not coverable*). It is recorded as
**not coverable**, not as passing.

### 7.2 — the section is still out of date in the right direction

Both limitations the section historically carried remain fixed. The locale of an
auto-created user (#34/#35/#40) was fixed in 5.6.0 and last verified 2026-08-16;
"logging out in Authentik leaves the Wallos session alive" (#37/#49) is what
back-channel logout closed. Neither could regress silently: the first is
verified by the `de`/`en` split on provisioning, the second by the endpoint
existing and refusing correctly (7.4 below). The section lists no live
limitation, which is itself the correct state — a section naming fixed defects
as current would be a finding.

### 7.3 — RP-initiated logout

Wallos initiates the flow correctly. The login page offers the Authentik button
and the authorize URL is well-formed:

```
https://auth.hornung-bn.de/application/o/authorize/?response_type=code
  &client_id=ZG8yV8m4UECN5PJM5RdbohFoyh4UqRTaktijjIZg
  &redirect_uri=https%3A%2F%2Ftest.hornung-bn.de%2Flogin.php
  &scope=openid+email+profile&state=<random>
```

Discovery (cached in `oidc_discovery_cache`, 1 row) yields the end-session
target the logout needs:

```
issuer                 https://auth.hornung-bn.de/application/o/wallos-test/
end_session_endpoint   https://auth.hornung-bn.de/application/o/wallos-test/end-session/
jwks_uri               https://auth.hornung-bn.de/application/o/wallos-test/jwks/
```

`logout.php` (5.8.7) destroys the local session first — token revoked, session
destroyed, cookie cleared — then, for an OIDC session, redirects to that
endpoint with `id_token_hint`, `post_logout_redirect_uri` and `state`. All of
that is verifiable by reading; the *effect* of the redirect is not, because it
needs a session established through a completed Authentik login.

**Single Logout — a deliberate change the plan predates.** Both Wallos
providers now use `default-invalidation-flow` (blueprint
`blueprint-provider-invalidation.yml`, 2026-08-28). An OIDC logout therefore
ends the entire Authentik browser session, and the next login of *any*
application asks for the password again. The plan's 7.3 line "Whether Authentik
ends only the application session or the whole SSO session depends on its
provider invalidation flow" is now settled to "the whole SSO session." Intended
configuration, recorded here so a future reader does not test against the old
expectation.

**The remember-me logout — known red, #123, confirmed in 5.8.7.** When a
session is restored from the `wallos_login` cookie (after the container restart
a deploy causes), `includes/remember_me.php` restores `from_oidc` but cannot
restore `oidc_id_token` — it only ever lived in the old PHP session, and is
never persisted:

```
$ git grep -n "_SESSION\['oidc_id_token'\]" v5.8.7
includes/oidc/oidc_login.php:35:    $_SESSION['oidc_id_token'] = $tokenData['id_token'];   <- set, session only
logout.php:19:                      $idToken = $_SESSION['oidc_id_token'] ?? null;           <- read, null after restore
```

`logout.php` then builds the end-session URL with `post_logout_redirect_uri` but
no `id_token_hint`, and Authentik answers `400`. This is the exact chain in
[#123](https://github.com/thorstenhornung1/Wallos/issues/123), whose live
reproduction was captured on this instance at 07:21 CEST today. **Known red,
not a new finding.** A *fresh* OIDC login (id_token in session) logs out cleanly
through the invalidation flow; that clean path needs a completed sign-in and is
not coverable here — the nightly log proof referenced in the task stands as the
end-to-end evidence.

### 7.4 — back-channel logout

The refusal path is fully exercised, and matches the plan's "bare
`invalid_request`, reason only in the log":

```
POST /backchannel-logout.php            (no logout_token)   -> 400 {"error":"invalid_request"}
POST /backchannel-logout.php  logout_token=not.a.jwt        -> 400 {"error":"invalid_request"}
GET  /backchannel-logout.php            (wrong method)      -> 405 {"error":"invalid_request"}
response header                                             -> Cache-Control: no-store
```

The prerequisites the endpoint needs to process a *real* token are in place: the
issuer is set and discovery publishes a `jwks_uri`, so a correctly signed token
would reach signature verification rather than being refused for want of keys.

**End to end is not coverable.** Accepting a session revocation requires a
`logout_token` signed by Authentik's private key and a live OIDC session to
revoke — neither of which a client without a completed provider login and
without provider signing keys can produce. Recorded as not coverable; the
runtime revocation was last demonstrated on 2026-08-20 (one signed token,
`1 session(s) revoked`).

### 7.5 — the three fixes from 5.8.0

**Revocation reaches endpoints — code present, runtime not coverable.** The
guard that closes the 30-day API window is wired into the shared endpoint
bootstrap in 5.8.7:

```
$ git show v5.8.7:includes/connect_endpoint.php | grep -n require_valid_session
34:    require_once __DIR__ . '/oidc/session_guard.php';
35:    wallos_oidc_require_valid_session($db);
```

So an endpoint hit by a revoked OIDC session refuses with `401` exactly as a
page does. Demonstrating the `200`→`401` transition at runtime needs an OIDC
session that is then revoked in Authentik — not coverable here; verified at
runtime on 2026-08-20.

**The OIDC client secret does not reach the browser — pass.** Checked on both
surfaces, without ever reading the secret. In `admin.php` the field is an empty,
disabled password input naming the env var, not the value:

```html
<input type="password" id="oidcClientSecret" autocomplete="new-password"
       value="" disabled data-managed-by="OIDC_CLIENT_SECRET_FILE" />
...
Configured (managed by OIDC_CLIENT_SECRET_FILE).
```

And the admin API answers with the placeholder rather than the secret:

```
GET /api/admin/get_oidc_settings.php?api_key=<qaadmin key, masked>   -> 200
  "client_secret_set": true
  "client_secret": "OIDC_CLIENT_SECRET_FILE"
```

The secret value appears on neither surface. This is precisely the leak 5.8.0
closed — a file-supplied secret rendered as an editable field.

**Enrolment consumes a TOTP code — pass.** On a throwaway account, 2FA was
enrolled with a code from time-step `59596599`; the enrolment recorded
`last_totp_used = 59596599`. Replaying that same code at the login step is
refused, and the next window's code succeeds:

```
enrol   step 59596599   verify -> {"success":true}   totp.last_totp_used = 59596599
replay  password step -> 302 (to totp.php)
        one-time-code (step 59596599) -> totp.php 200 (re-render)
        settings.php -> 302   (NOT logged in — the used code is refused)
next    password step -> 302 (to totp.php)
        one-time-code (step 59596600) -> totp.php 302 -> https://test.hornung-bn.de/
        settings.php -> 200   (logged in)
```

The enrolment code's time-step is struck off, so it cannot be replayed to sign
in; a code from the following window works.

**Account deletion removes every row — pass.** The throwaway account (id 115)
was populated across many `user_id` tables — `login_tokens` (a remember-me
token), `totp`, `email_notifications`, `notification_settings`, plus the seeded
`categories`, `payment_methods`, `currencies`, `household`, `settings` — then
deleted through the **self-service** path (`endpoints/settings/deleteaccount.php`),
the stricter of the two, which historically left `login_tokens` behind. The
plan's exact completeness check finds nothing:

```
$ php -r 'require ".../connection.php"; $db=wallos_database_connect(); $id=115;
          foreach ($db->tablesWithColumn("user_id") as $t) {
            $n=(int)$db->scalar("SELECT COUNT(*) FROM \"".$t."\" WHERE user_id=:id",[":id"=>$id]);
            if ($n>0) printf("%-32s %d row(s) left behind\n",$t,$n); }
          echo "done\n";'
user row left: 0
done
```

And the specific self-service bug is closed — the remember-me row is gone, and
the cookie that named it no longer restores anything:

```
login_tokens left for user 115                              -> 0
GET /endpoints/subscriptions/get.php  -b "wallos_login=...(deleted token)"  -> 401
```

## Skipped / not coverable, and why

| Prüfpunkt | Why |
| --- | --- |
| 7.1 dynamic grant/revoke on a fresh login | Needs a completed Authentik sign-in. The flow-executor probe returned `default-authentication-flow` with an identification stage requiring username + password (and offering passkey/captcha); no QA OIDC credential is provisioned, and the household SSO password / any provider-side change is out of scope. Current-state evidence recorded instead. |
| 7.3 fresh-login logout, end to end | Same: needs an OIDC session in the cookie jar. Local mechanics, discovery and URL construction verified by other means. |
| 7.3 remember-me logout live 400 | The 400 itself needs a restored OIDC session; confirmed present in 5.8.7 by code and already reproduced live in #123. |
| 7.4 back-channel logout, end to end | Needs a `logout_token` signed by Authentik and a live OIDC session to revoke. Refusal path and prerequisites (issuer, JWKS) verified. |
| 7.5 revocation reaches endpoints (401) | Needs an OIDC session that is then revoked. Guard wiring verified in 5.8.7 source; runtime last shown 2026-08-20. |

The common cause is one operator action: a completed sign-in to the productive
Authentik as a Wallos user, with the resulting `PHPSESSID` handed to the tester
— the method the 2026-08-20 run used for its end-to-end OIDC results. Nothing in
section 7 needs Authentik to be *reconfigured*; it needs a real session this
client is not allowed to manufacture.

## Fixtures and cleanup

| Fixture | Where | Purpose | Removed |
| --- | --- | --- | --- |
| `qa-oidc-2026` (user id 115) | Wallos DB | throwaway account for 7.5 TOTP-enrolment and deletion tests | **yes** — self-deleted; completeness check clean, 0 rows in every `user_id` table |
| remember-me token, TOTP secret, email/notification settings, budget of user 115 | Wallos DB | populate multiple tables before deletion | **yes** — removed by the account deletion |
| curl cookie jars, page dumps (`/tmp/qa-oidc-*.jar`, `/tmp/qa-*.html`, `/tmp/qa-flow.json`, `/tmp/oidc-api*.json`) | `docker-infra-3:/tmp` | curl session and evidence | **yes** — `rm`; 0 remaining |
| `qaadmin` curl session | Wallos | admin session for admin.php / API checks | **yes** — `logout.php` (200) |
| abandoned Authentik auth flow | Authentik | read-only flow-executor probe, no credentials submitted | left to Authentik's own GC; nothing was created or authenticated |

Not touched: the pre-existing `oidc_sessions` row for user 113
(`2026-08-28 05:21:57`, from before the current container start) — not a fixture
of this run and left as found. Account list after the run is `qaadmin`, `admin`,
`thorsten.hornung`, unchanged from the start.

Mailpit was empty before (`messages_count: 0`) and after
(`messages_count: 0`) — no notification or verification mail was triggered by
any of these tests.

## Findings

**No new Wallos defect.** Every check that could be run behaved as the plan and
the 5.8.7 source describe.

**Carried, not new:**

* **#123 (remember-me logout → 400)** is present in 5.8.7 and is the one OIDC
  logout path that fails. Known red; fix on `fix/oidc-remember-logout`, awaiting
  the security verdict before merge.
* **Single Logout** is now the configured behaviour of both Wallos providers
  (`default-invalidation-flow`, 2026-08-28). The plan's 7.3 text should be
  updated to say the SSO session ends, not just the app session — a plan edit,
  not a code change.

**Method note.** A first `add.php` attempt was refused on the `cycle` field
because the payload named `billing_period`/`frequency` where the endpoint reads
`cycle`; that is a wrong payload of the tester's, not a defect, and the
subscription was simply left out of the fixture set (the deletion check does not
need it). Recorded in the same spirit as the 2026-08-20 note: a specific refusal
is what tells a bad request apart from a broken endpoint.

## Addendum — #123 fix field test on the main digest (same day, later)

After this run finished, the merged #123 fix (architecture A, security verdict
at the issue) was field-tested on this instance under a **temporary main-digest
pin** approved by the operator. ⚠️ The image self-reports `v5.8.7` —
`version.php` is only bumped at release — so, deliberately, the environment
below is proven **by digest only**:

```sh
$ docker service inspect wallos-test_wallos --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}'
ghcr.io/thorstenhornung1/wallos@sha256:65aaf7ee1ec8bff1ce28d730e25b4460b6cb98c273b514bffd436c873a2a2163
```

Three layers, all verified:

1. **Schema and jobs.** Migration `000068` ran at start (migrations 66 → 67);
   `oidc_sessions` now carries the `id_token` column
   (`id,user_id,sid,session_id,login_token,created_at,id_token`); the
   `cleanupsessions` cron required by the security verdict is in the crontab.
2. **Persistence.** A fresh Authentik sign-in (operator, user 113, 06:35:20 UTC)
   stored its id_token server-side (`id_token IS NOT NULL`, length 1668). The
   pre-deploy row holds an empty token, as documented — its first logout would
   degrade once.
3. **End to end — the exact #123 scenario, now green.** Container restarted via
   `service update --force` (session gone, `wallos_login` cookie kept); the
   operator was signed back in by remember-me and clicked logout. Result:
   **clean logout with return** — Wallos 07:42 sends `logout.php → 302`,
   Authentik receives `end-session/?id_token_hint=…` (the hint now comes from
   `oidc_sessions`), answers `302` into the invalidation flow and records a
   real `action: logout` event; the browser lands back on
   `login.php?logged_out=1`, and the next login asks for the password. The
   morning's reproduction of the same scenario ended in Authentik's 400.
   The `oidc_sessions` row was deleted by the logout (0 rows left) — the
   revocation coupling the security verdict demanded.

The remember-me row of the results table above is therefore **fixed on main**
(unreleased); 7.3's fresh-login logout end-to-end is now ALSO covered by this
addendum's operator-driven run. The instance stays on the main digest until the
next tagged release replaces the pin (operator decision, recorded in the stack
file and docs/next-steps.md).
