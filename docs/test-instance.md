# Test instance on test.hornung-bn.de

A throwaway Wallos configured the way a real multi-user installation would be:
shared SMTP, currency and AI credentials delivered as files, mail captured
instead of sent, and a second account to prove that inheritance and isolation
actually work.

Everything below is copy-paste. Values you choose are marked.

A run of this plan against a live Swarm cluster is documented in
[test-results-2026-08-16.md](test-results-2026-08-16.md).

Two deployment paths, one test plan:

* **Docker Swarm** — `docs/test-instance/wallos-test-stack.yml`
* **Kubernetes / k3s** — `docs/test-instance/wallos-test.yaml`

## What this lets you verify

| | |
| --- | --- |
| instance SMTP | one transport serves system mail and every user's notifications |
| per-user override | a user switches to their own SMTP and keeps working |
| secret files | credentials arrive through `*_FILE`, never through the page |
| failure semantics | an unreadable secret file invalidates instead of falling back |
| shared currency/AI | a second user inherits without being handed a key |
| managed fields | environment-managed admin fields are read-only and named |
| cron jobs | notifications, renewals and rate updates use the same configuration |

## 1. Prerequisites

DNS records pointing at your ingress:

```
test.hornung-bn.de
mail.test.hornung-bn.de
```

The image is public, so no pull credentials are needed.

## 2A. Deploy on Docker Swarm

Secrets first. Swarm mounts them under `/run/secrets/<target>`, which is exactly
what the `WALLOS_*_FILE` variables read — no credential is ever passed as an
environment value.

The currency and AI keys below are deliberately **invalid**, so no test run
spends a real quota. Replace them only to exercise a real provider call.

```sh
printf 'test-smtp-password'   | docker secret create wallos_test_smtp_password -
printf 'invalid-currency-key' | docker secret create wallos_test_currency_api_key -
printf 'invalid-ai-key'       | docker secret create wallos_test_ai_api_key -
```

`printf`, not `echo`: `echo` appends a newline, and the secret would be one byte
longer than the password actually is. Wallos strips trailing newlines when it
reads a secret file, so this particular setup would survive it — but a provider
that does not would fail authentication with a message pointing at a wrong
password rather than at one byte too many.

Verify what arrived:

```sh
$EXEC sh -c 'for f in /run/secrets/*; do printf "%s %s bytes\n" "$f" "$(wc -c < "$f")"; done'
```

The stack expects the `traefik_public` network and a node labelled `app=true`,
matching your other stacks:

```sh
docker network ls --filter name=traefik_public
docker node ls --format '{{.Hostname}}' | while read -r n; do
  printf '%s: %s\n' "$n" "$(docker node inspect "$n" --format '{{index .Spec.Labels "app"}}')"
done
```

Deploy:

```sh
docker stack deploy -c docs/test-instance/wallos-test-stack.yml wallos-test
docker service logs -f wallos-test_wallos 2>&1 | grep -i migration
```

`Migration migrations/000055.php completed successfully.` and `000056` confirm
the instance configuration and the subscription indexes are in place.

### Why one replica, pinned to a hostname

The volumes are node-local and SQLite has a single writer, so the service runs
one replica, updates with `order: stop-first`, and is pinned with

```yaml
- node.hostname == docker-infra-3
```

**Change that hostname to your node.** A hostname, not a label: `node.labels.app
== true` looks equivalent and is not, because the label can be on several nodes
and Swarm will eventually use that freedom.

This is worth stating plainly because of how the failure presents. A task
rescheduled to another node finds an empty volume, runs the migrations against
it, and comes up as a fresh installation — login page, no accounts, no
subscriptions. It looks precisely like total data loss. The data is untouched on
the original node, and nothing on screen suggests that. This happened during the
2026-08-16 test run.

Do not put the database on CephFS or NFS to make it float — SQLite locking is
not safe on a network filesystem, and that is the constraint the optional
PostgreSQL backend exists to remove.

### Which version is running

The image no longer ships a `VERSION` file, so read the tag instead:

```sh
docker inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' wallos-test_wallos
```

## 2B. Deploy on Kubernetes

```sh
kubectl create namespace wallos-test

kubectl -n wallos-test create secret generic wallos-secrets \
  --from-literal=smtp_password='test-smtp-password' \
  --from-literal=currency_api_key='invalid-currency-key' \
  --from-literal=ai_api_key='invalid-ai-key'

kubectl apply -f docs/test-instance/wallos-test.yaml
kubectl -n wallos-test rollout status deployment/wallos
kubectl -n wallos-test logs deployment/wallos | grep -i migration
```

## 3. A shell into the running instance

The test plan runs commands inside the container. Define this once and the rest
of the document works on either platform.

**Swarm** — find the node running the task, then exec on that node:

```sh
docker service ps wallos-test_wallos --filter desired-state=running \
  --format 'task runs on: {{.Node}}'

# on that node:
EXEC="docker exec $(docker ps -q -f name=wallos-test_wallos | head -1)"
```

**Kubernetes**:

```sh
EXEC="kubectl -n wallos-test exec deployment/wallos --"
```

Check it works:

```sh
$EXEC php -r 'include "/var/www/html/includes/version.php"; echo "Wallos $version\n";'
```

## 4. First accounts

Open https://test.hornung-bn.de. The first account you register becomes the
admin.

Then create a second, ordinary user in **Admin → Users** — call it `user2`.
Most of the interesting behaviour only appears with two accounts.

## 5. The tests

### 5.1 Instance SMTP serves a user who configured nothing

As admin: **Settings → Notifications → Email**. It should already say **Use
instance SMTP** and show:

```
SMTP Address: mailpit:1025
SMTP Password: Managed externally
```

Enable email notifications, save, press **Test**. Open
https://mail.test.hornung-bn.de — the mail is there, from `Wallos Test
<wallos@test.hornung-bn.de>`.

You never entered a server, a password or a sender.

### 5.2 The secret never reaches the browser

A session to work with; the following steps reuse it:

```sh
BASE=https://test.hornung-bn.de
COOKIES=$(mktemp)

curl -s -c "$COOKIES" "$BASE/login.php" -o /dev/null
curl -s -b "$COOKIES" -c "$COOKIES" -X POST "$BASE/login.php" \
  --data-urlencode "username=admin" \
  --data-urlencode "password=<the password you chose>" -o /dev/null

CSRF=$(curl -s -b "$COOKIES" "$BASE/settings.php" \
  | grep -o 'window.csrfToken = "[^"]*"' | sed 's/.*"\(.*\)"/\1/')
```

Now look for the password in the pages that use it:

```sh
for page in settings.php index.php; do
  printf '%s: %s\n' "$page" \
    "$(curl -s -b "$COOKIES" "$BASE/$page" | grep -c test-smtp-password)"
done
```

Expected: `0` for both. The password exists, sends mail, and is not in the page.

`settings.php` is the one that carries weight: the value *is* in scope there,
because that page renders the mail configuration form. A field pre-filled with
the current password — the obvious, convenient implementation — would put the
secret into the HTML of every page load, where it survives in the browser cache,
in a saved page, and in any screenshot of the settings screen.

**`admin.php` needs a separate run with an administrator session.** A
non-administrator gets a redirect, and `grep -c` on a redirect returns `0` for
the same reason an empty page does — which proves nothing at all. Repeat the
block above with an administrator's credentials to cover it:

```sh
ADMIN_COOKIES=$(mktemp)
curl -s -c "$ADMIN_COOKIES" "$BASE/login.php" -o /dev/null
curl -s -b "$ADMIN_COOKIES" -c "$ADMIN_COOKIES" -X POST "$BASE/login.php" \
  --data-urlencode "username=<an administrator>" \
  --data-urlencode "password=<their password>" -o /dev/null

# Confirm the page actually rendered before trusting the count.
curl -s -b "$ADMIN_COOKIES" "$BASE/admin.php" | grep -c 'Instance Integrations'
curl -s -b "$ADMIN_COOKIES" "$BASE/admin.php" | grep -c test-smtp-password
```

Expected: a non-zero first count — proof the page rendered — and `0` for the
second.

### 5.3 Environment-managed fields cannot be edited

In **Admin → SMTP Settings** the host, port, encryption and sender are filled
in, greyed out and labelled with the variable that owns them.

Try to overwrite one anyway:

```sh
curl -s -X POST "$BASE/endpoints/admin/savesmtpsettings.php" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -b "$COOKIES" \
  -d '{"smtpaddress":"evil.example.com","smtpport":"25","encryption":"none"}'
```

It answers `success` — the request was valid — and nothing changed:

```sh
$EXEC php -r '$d = new SQLite3("/var/www/html/db/wallos.db");
  $r = $d->querySingle("SELECT smtp_address FROM admin", true);
  echo "stored host: [" . $r["smtp_address"] . "]\n";'
```

Still empty. Managed fields are skipped, never written; the effective host keeps
coming from `WALLOS_SMTP_HOST`.

### 5.4 A user inherits without being given anything

Log in as `user2`. **Settings → Notifications → Email** shows *Use instance
SMTP* and the same status. Enable notifications, press **Test**, check Mailpit.
`user2` never saw a credential.

Same under **Fixer API Key** and **AI recommendations**: provider and model are
visible, the key says *Managed externally*.

### 5.5 A user can still run their own transport

As `user2`, switch to **Use custom SMTP**:

```
Host: mailpit
Port: 1025
Encryption: none
From: user2@test.hornung-bn.de
```

Save and test. The mail arrives with `user2`'s sender — their transport, not the
instance's.

### 5.6 Notifications actually go out

Two things have to be true before the scheduled job sends anything, and a green
Test button in 5.1 proves neither:

1. **Email notifications enabled and saved.** The test button resolves the
   transport directly and sends; the cron job only sends for users whose
   `email_notifications` row says enabled. Pressing Test without saving leaves
   no row at all. Since v5.5.1 the test reply says so explicitly.
2. **A subscription that is actually due today.** Each subscription has its own
   lead time; `-1` means "use my notification setting" from **Settings →
   Notifications**, which defaults to one day.

So: enable email notifications and **save**, create a subscription due tomorrow
with notifications on, then:

```sh
$EXEC php /var/www/html/endpoints/cronjobs/sendnotifications.php
```

The job explains its decision, which is what makes it debuggable:

```
Subscription: Test
Next payment date: 2026-08-17
Current date: 2026-08-16
Difference: 1

Email Notifications sent
```

If nothing arrives, this output separates "the job never considered this
subscription" from "it tried and the transport failed". Mailpit receives one
mail per user with a due subscription, each through the transport that user
resolves to.

### 5.7 A broken secret file does not fall back

The behaviour that matters most in production. Point the password at a file
that is not there:

```sh
# Swarm
docker service update --env-add \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/does-not-exist wallos-test_wallos

# Kubernetes
kubectl -n wallos-test set env deployment/wallos \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/does-not-exist
```

The admin page now reports the configuration as invalid and names the unreadable
path, and a test send refuses. It does **not** quietly fall back to a password
sitting in the database — a half-failed rotation must not keep running on the
old secret.

Put it back:

```sh
# Swarm
docker service update --env-add \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/smtp_password wallos-test_wallos

# Kubernetes
kubectl -n wallos-test set env deployment/wallos \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/smtp_password
```

### 5.8 Cron jobs run clean

```sh
for job in sendnotifications sendcancellationnotifications updateexchange \
           sendverificationemails sendresetpasswordemails; do
  echo "--- $job"
  $EXEC php /var/www/html/endpoints/cronjobs/$job.php 2>&1 | tail -3
done
```

`updateexchange` reports that the provider could not be reached — expected, the
key is intentionally invalid. What matters is that no job produces a PHP warning
or fatal error.

## 6. Load, to see the performance work

`dev/benchmark.sh` seeds data, measures, and cleans up after itself. It takes the
median of five runs per figure and touches only rows prefixed `bench-` or `seed-`:

```sh
dev/benchmark.sh --base https://test.hornung-bn.de \
                 --user youruser --password 'yourpassword' \
                 --exec "$EXEC"
```

Two axes, because they stress different things: one account's list grows to 100,
1000 and 5000 entries, then the notification cron runs against 1, 10 and 100
users.

Measured on the local dev container (Podman, 5.6.3, SQLite on a tmpfs-free
bind mount), so treat these as a shape rather than as absolute numbers for your
hardware:

| entries | list | stats | calendar |
|---|---|---|---|
| 100 | 30 ms | 11 ms | 9 ms |
| 1000 | 179 ms | 35 ms | 29 ms |
| 5000 | 875 ms | 147 ms | 116 ms |

| users | notify cron | rates cron |
|---|---|---|
| baseline (empty script) | 12 ms | — |
| 1 | 25 ms | 243 ms |
| 10 | 27 ms | 220 ms |
| 100 | 25 ms | 229 ms |

What the numbers say:

- **The cron is flat.** 1 user and 100 users cost the same, about 13 ms above
  the bare interpreter start. Notification settings are read once per run rather
  than once per user, so adding users adds nothing measurable.

  The cron timings are taken from inside a PHP process. An earlier version of
  the script used `date +%s%N` from the shell, which the Alpine-based image does
  not support — BusyBox ignores `%N` and returns whole seconds, so every figure
  including the baseline came out as `0 ms`. A table of zeros reads like "too
  fast to measure" when it means "not measured", so if you see one, the script
  is older than 5.7.2.
- **The list is linear in the number of entries, not quadratic.** 100 → 5000 is
  50× the data for 29× the time. Exchange rates are fetched in one query, so a
  row no longer costs a round trip. What remains is rendering: 5000 entries
  produce a large HTML document, and that is the honest cost of the page.
- **5000 entries in one account is past comfortable.** 875 ms is a page you feel.
  If that becomes a real shape rather than a benchmark, the answer is pagination
  in the list, not more query tuning — the query is already one indexed scan.

Re-run this after any change to the subscription list, the rate handling, or the
cron jobs, and compare against your own previous run rather than against this
table.

## 7. OIDC against your Authentik

Needed for the Part II work. In Authentik create an OAuth2/OpenID provider and
an application with redirect URI:

```
https://test.hornung-bn.de/login.php
```

Both `https://test.hornung-bn.de/login.php` and `https://test.hornung-bn.de/`
work as redirect targets, and `OIDC_REDIRECT_URL` must name the same one you
configured in Authentik.

This is the one value in the whole setup that cannot be derived from anything
else, and until v5.5.2 only the document root actually consumed the response:
a callback arriving at `login.php` was discarded without a trace — Authentik
logged a successful authorization, Wallos rendered the login form again, and
neither side reported a problem
([#42](https://github.com/thorstenhornung1/Wallos/issues/42)). If you see that
symptom, the instance is older than 5.5.2.

**Swarm:**

```sh
printf '<client secret from Authentik>' \
  | docker secret create wallos_test_oidc_client_secret -
```

Add to the `wallos` service in the stack file, then redeploy:

```yaml
    environment:
      OIDC_ENABLED: "true"
      OIDC_PROVIDER_NAME: Authentik
      OIDC_CLIENT_ID: <from Authentik>
      OIDC_CLIENT_SECRET_FILE: /run/secrets/oidc_client_secret
      OIDC_ISSUER: https://auth.hornung-bn.de/application/o/wallos/
      OIDC_REDIRECT_URL: https://test.hornung-bn.de/login.php
      OIDC_AUTO_CREATE_USER: "true"
    secrets:
      - source: wallos_test_oidc_client_secret
        target: oidc_client_secret
```

```yaml
secrets:
  wallos_test_oidc_client_secret:
    external: true
```

**Kubernetes:**

```sh
kubectl -n wallos-test create secret generic wallos-oidc \
  --from-literal=client_secret='<from Authentik>'

kubectl -n wallos-test set env deployment/wallos \
  OIDC_ENABLED=true OIDC_PROVIDER_NAME=Authentik \
  OIDC_CLIENT_ID='<from Authentik>' \
  OIDC_CLIENT_SECRET_FILE=/run/secrets/oidc/client_secret \
  OIDC_ISSUER=https://auth.hornung-bn.de/application/o/wallos/ \
  OIDC_REDIRECT_URL=https://test.hornung-bn.de/login.php \
  OIDC_AUTO_CREATE_USER=true
```

`auth.hornung-bn.de` is already in the SSRF allowlist of both manifests, which
it needs if it resolves to a private address.

### 7.1 Administrators from a group claim

Optional, and off until both halves are filled in. Configure it in
**Admin → OIDC settings**, in the two fields below "Require verified email":

```
Admin claim         groups
Admin claim value   Wallos Admins
```

Or set it from the environment, which then takes precedence and greys the
fields out in the interface, exactly like the other OIDC settings:

```yaml
      OIDC_ADMIN_CLAIM: groups
      OIDC_ADMIN_VALUE: Wallos Admins
```

Authentik sends `groups` when the `profile` scope includes a groups mapping; if
you use entitlements instead, name that claim. Nothing here is
Authentik-specific — you name the claim, Wallos reads it.

Matching is exact, including case. `Admin` does not match `admin`, because a
provider where those are two different groups with two different memberships is
not one to guess about.

The claim is re-read on **every** OIDC login, which is what makes revocation
work: remove the group in Authentik, and the role goes at the user's next sign
in. It does not end a session that is already running — for that you need
back-channel logout ([#49](https://github.com/thorstenhornung1/Wallos/issues/49)).

Only the OIDC-derived role is touched. An administrator who was granted the role
locally keeps it even if the provider never sends the claim — that account is
the way back in when the claim name turns out to be wrong, so it must not be
possible to lose it by misconfiguring the provider.

Both halves are required. Setting only one is reported in the configuration
check rather than silently ignored, so you do not end up believing rights are
being synchronised when nothing is happening.

### 7.2 Known limitations

Open issues, not misconfiguration:

* an auto-created user is always English and EUR, whatever the `locale` claim
  says ([#34](https://github.com/thorstenhornung1/Wallos/issues/34),
  [#35](https://github.com/thorstenhornung1/Wallos/issues/35),
  [#40](https://github.com/thorstenhornung1/Wallos/issues/40))
* logging out in Authentik leaves the Wallos session alive
  ([#37](https://github.com/thorstenhornung1/Wallos/issues/37),
  [#49](https://github.com/thorstenhornung1/Wallos/issues/49))

### 7.3 Logout

Logging out of Wallos now ends the provider session too, using the standard
end-session request with `id_token_hint`, `post_logout_redirect_uri` and
`state`.

The end-session URL comes from discovery when you use `OIDC_ISSUER`, so there is
usually nothing to configure. An explicit "Logout URL" in the admin interface
takes precedence if your provider needs one.

**One thing to set in Authentik:** register the return URI, or Authentik will
ignore it and leave the user on its own page. In the provider's redirect URI
list add:

```
https://test.hornung-bn.de/login.php?logged_out=1
```

The default is derived from your redirect URL; override it with the
"Post-logout redirect URL" field or `OIDC_POST_LOGOUT_REDIRECT_URL`.

Local logout always completes first — token deleted, session destroyed, cookie
cleared — before the redirect is issued. A provider that is unreachable or
misconfigured cannot leave you signed in to Wallos.

Whether Authentik ends only the application session or the whole SSO session
depends on its provider invalidation flow. That is a provider-side setting;
Wallos sends the standard request either way.

**One authentik trap, found in the 2026-08-17 run.** If you log out of authentik
first and *then* ask Wallos to log out, you land on a page containing nothing but
`Logout successful`, stopped at the end-session URL. Every parameter Wallos sent
is correct and the registered URI matches byte for byte.

The cause is in authentik's `providers/oauth2/views/end_session.py`: `dispatch()`
returns that hard-coded HTML when a flow plan is left in the session, before any
logout logic runs. The guard is meant for iframe-based single logout, and an
abandoned plan looks the same to it. Since `dispatch()` precedes `get()`, such a
request also sends **no** back-channel token — one cause, two symptoms.

Nothing to change in Wallos or in the provider configuration. Test the logout in
a fresh private window, and log out of Wallos *before* logging out of authentik.
It belongs here because the failure is silent, the page says "successful", and
everything looks right while it happens.

### 7.4 Back-channel logout

The other direction: Authentik telling Wallos that a session is over, without
the user's browser being involved. This is what makes an account you disable in
Authentik stop working in Wallos immediately, instead of when the session
happens to expire.

In the Authentik provider, set the backchannel logout URL to:

```
https://test.hornung-bn.de/backchannel-logout.php
```

Nothing else to configure. The endpoint takes the provider's signing keys from
the JWKS published in discovery, so an issuer must be set — either the
**Issuer URL** field in the OIDC settings or `OIDC_ISSUER`. Without one there is
no discovery document, no signing keys, and every logout token is refused.

What it accepts: a POSTed `logout_token` whose signature verifies against those
keys, whose issuer and audience match this installation, that is recent, that
carries the back-channel logout event, and that carries no nonce. Anything else
is refused with a bare `invalid_request` — the reason goes to the container log,
not to the caller.

What it does: ends the matching session, deletes its remember-me token, and
makes the running PHP session stop working on its very next request. When the
provider sends a `sid` it ends exactly that session; with only a `sub` it ends
every session of that person.

What it never does: delete a Wallos account or any of its data. Subscriptions,
history, categories and settings are local application data. An identity
disappearing at the provider is not permission to destroy financial records —
if you want the account gone, delete it in Wallos.

To check it end to end: sign in through Authentik, then terminate that session
in Authentik's admin interface, then reload any Wallos page. You should land on
the login screen.

### 7.5 The three fixes from 5.8.0, checked deliberately

These were security defects in code that already had passing tests. Each one is
worth confirming on your own instance rather than taking on trust.

**Revocation reaches endpoints, not just pages.** Until 5.8.0 the check lived
only on the path that renders HTML, so after Authentik ended a session the API
stayed open for up to thirty days — including user administration and database
backup. Loading a page is therefore *not* a sufficient test:

```sh
# Sign in through Authentik in a browser, then take the session cookie from
# developer tools and use it directly against an endpoint.
curl -s -o /dev/null -w '%{http_code}\n' \
  -b "PHPSESSID=<the session cookie>" \
  https://test.hornung-bn.de/endpoints/subscriptions/get.php
```

Before ending the session in Authentik: `200`. After: **`401`**. If you get a
`200` after revocation, the running version is older than 5.8.0.

Two things that will make this test lie to you:

* **Send only `PHPSESSID`, not `wallos_login`.** The remember-me cookie rebuilds
  a destroyed session, so including it produces a `200` that says nothing about
  revocation. Copy one cookie, not the whole header.
* **It has to be an OIDC session.** The guard only applies to sessions that came
  from the provider — a password login has no provider to have ended it, and
  correctly stays valid. Signing in with a local account and expecting a `401`
  tests nothing.

**The OIDC client secret does not reach the browser.** Section 5.2 checks this
for the SMTP, currency and AI secrets. OIDC was not in that list, and that is
exactly where the leak was — a secret supplied through
`OIDC_CLIENT_SECRET_FILE` was returned by the admin API and rendered as an
editable text field.

```sh
# With an administrator session:
curl -s -b "$ADMIN_COOKIES" "$BASE/admin.php" | grep -c '<the secret value>'
curl -s "$BASE/api/admin/get_oidc_settings.php?api_key=<admin key>" | grep -c '<the secret value>'
```

Expected: `0` from both. The API answers `client_secret_set: true` instead of
the value. The field on the page is an empty password input — saving it empty
keeps what is stored, so confirm that too: save the OIDC form without touching
the secret, then sign in through Authentik again.

**Account deletion removes every row.** Twelve tables were missing from both
deletion paths, and two more from the self-service one — including
`login_tokens`, so a self-deleted account left a working remember-me token
behind.

Create a throwaway account, configure as much as you can (2FA, several
notification channels, a custom colour theme, a subscription), delete it, then:

```sh
$EXEC php -r '
require "/var/www/html/includes/database/connection.php";
$db = wallos_database_connect();
$id = <the deleted account id>;
foreach ($db->tablesWithColumn("user_id") as $table) {
    $n = (int) $db->scalar("SELECT COUNT(*) FROM \"" . $table . "\" WHERE user_id = :id", [":id" => $id]);
    if ($n > 0) { printf("%-32s %d row(s) left behind\n", $table, $n); }
}
echo "done\n";'
```

Expected: nothing but `done`. Note the account id before deleting it — after
deletion there is nothing left to look it up by.

## 8. PostgreSQL instead of SQLite

Optional, and new in 5.8.0. SQLite remains the default and nothing below is
needed to run the rest of this plan.

Worth testing separately rather than as a variant of everything above: the
failure modes are different, and a PostgreSQL instance enforces foreign keys
that SQLite has never enforced, so it rejects writes SQLite accepted.

### 8.1 A fresh PostgreSQL instance

Add a database to the stack and point Wallos at it:

```yaml
  postgres:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: wallos
      POSTGRES_USER: wallos
      POSTGRES_PASSWORD_FILE: /run/secrets/db_password
    volumes:
      - wallos_pgdata:/var/lib/postgresql/data
    secrets:
      - source: wallos_test_db_password
        target: db_password
    deploy:
      placement:
        constraints:
          - node.hostname == docker-infra-3
```

and on the `wallos` service:

```yaml
      WALLOS_DB_DRIVER: pgsql
      WALLOS_DB_HOST: postgres
      WALLOS_DB_NAME: wallos
      WALLOS_DB_USER: wallos
      WALLOS_DB_PASSWORD_FILE: /run/secrets/db_password
      WALLOS_DB_SSLMODE: prefer
```

There is no migration chain to wait for. A generated baseline carries the
current schema with every historical migration already recorded, and it installs
itself into an empty database on first start:

```
PostgreSQL database is empty. Applying the baseline schema...
Baseline schema applied.
```

Confirm:

```sh
$EXEC php -r '
require "/var/www/html/includes/database/connection.php";
$db = wallos_database_connect();
printf("driver:     %s\n", $db->driver());
printf("tables:     %d\n", (int) $db->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()"));
printf("migrations: %d\n", (int) $db->scalar("SELECT COUNT(*) FROM migrations"));'
```

Expected: `pgsql`, 42 tables, 62 migrations. Then run sections 4 through 7
unchanged — the whole plan applies, and any difference is a finding.

### 8.2 Moving an existing instance across

**Take a backup first, and keep the SQLite file.** The migration reads it
read-only and never writes to it, but the point of a backup is that you do not
have to trust that.

```sh
# Fingerprint the SQLite instance
$EXEC php /var/www/html/dev/stress-verify.php > before.txt

# Dry run: what would move, and what is in the way
$EXEC php /var/www/html/dev/migrate-to-pgsql.php --dry-run
```

The dry run is where the interesting answer comes from. **It refuses by default
if the source holds rows that violate a foreign key** — PostgreSQL enforces
thirteen constraints that SQLite has never enforced, so a database that has been
running for a while usually has some. The development database in this
repository has 82 such rows across 7 constraints.

It names the constraint, the count and sample rows. Decide per case: fix the
data in SQLite first, or run with `--skip-orphans`, which counts what it leaves
behind rather than reporting a clean run.

```sh
$EXEC php /var/www/html/dev/migrate-to-pgsql.php
```

Then switch `WALLOS_DB_DRIVER` to `pgsql`, redeploy, and compare:

```sh
$EXEC php /var/www/html/dev/stress-verify.php > after.txt
diff <(tail -n +2 before.txt) <(tail -n +2 after.txt)
```

Line 1 names the backend and cannot match; everything else must be identical —
row counts and a content hash over every column, with NULL and empty string
rendered differently on purpose. **Any other difference is data loss and belongs
in the report.**

Finally the check the migration exists for: create a subscription, a category
and a payment method through the interface. Copying rows with explicit ids
leaves PostgreSQL's sequences at 1, so the first insert afterwards collides with
a row that already exists — and the error names a constraint, not the import.
The migrator sets every sequence and says so; this confirms it on your data.

### 8.3 What to watch for

* **Writes that used to succeed may now fail.** Foreign keys are enforced.
  A subscription referencing a deleted category, a payment method id of 0 — both
  were accepted by SQLite and are rejected here. That is the integrity
  improvement working, but it will surface as an error message.
* **Backup and restore do not work on PostgreSQL.** Sections `endpoints/db/`
  operate on the SQLite file. They will report success and do nothing. Use
  `pg_dump`.
* **Prices come back as strings** from the API rather than as JSON numbers.
  Harmless in PHP, visible to any client doing arithmetic on the response.

## 9. Writing up what you find

The point of this plan is the report, not the run. A test that passed is worth
recording; a test that passed for the wrong reason is worth more, and only shows
up if the write-up is specific.

**Where.** One file per run, `docs/test-results-YYYY-MM-DD.md`, committed to
this repository. The previous one is
[test-results-2026-08-16.md](test-results-2026-08-16.md) — same shape.

**What each entry needs**, in order of how often it is missing:

1. **The evidence, pasted.** The command and its actual output, not a summary of
   it. `curl` status codes, log lines, `EXPLAIN` output, the row counts. A claim
   without its output cannot be re-checked by anyone including you.
2. **The version.** Read it from the image tag, not from a file — the image no
   longer ships `VERSION`:
   `docker inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' wallos-test_wallos`
3. **Pass, fail, or not covered.** "Not covered" is a real answer and belongs in
   the table. Section 5.2 was reported as passing for three pages when one of
   them had returned a redirect, which proves nothing — that correction came
   from the run's own author and is exactly the kind of honesty that makes a
   report useful.
4. **What you concluded, separately from what you observed.** Keep them apart.
   The 5.7.2 benchmark run concluded that per-user cron cost "disappears against
   process start-up"; the measurement had in fact returned zeros because BusyBox
   does not support `date +%s%N`. The observation was fine, the conclusion was
   not, and only the separation makes that visible afterwards.

**A finding that belongs to Wallos becomes an issue.** Link it from the report,
so the report stays a record of what happened and the issue carries the work:

```sh
gh issue create --repo thorstenhornung1/Wallos --title "..." --body "..."
```

**A finding that belongs somewhere else stays in the report,** with the cause.
The `Logout successful` page in section 7.3 is authentik's, not Wallos's — the
report names the file and the branch in authentik's source, which is what makes
it useful to the next person rather than a dead end.

**If the plan itself was wrong, fix the plan in the same pull request.** Three
corrections in the 2026-08-16 run came from the run: the placement constraint
that was a label rather than a hostname, section 5.2 needing an administrator
session, and the benchmark's broken cron timing. All three are now in this
document because they were written down while they were still fresh.

## 10. Reset or remove

Start from an empty database without redeploying:

```sh
$EXEC rm /var/www/html/db/wallos.db
docker service update --force wallos-test_wallos     # Swarm
kubectl -n wallos-test rollout restart deployment/wallos   # Kubernetes
```

Remove everything:

```sh
# Swarm — volumes survive the stack, remove them on the node that held them
docker stack rm wallos-test
docker volume rm wallos-test_wallos_db wallos-test_wallos_logos
docker secret rm wallos_test_smtp_password wallos_test_currency_api_key wallos_test_ai_api_key

# Kubernetes — the namespace takes the volumes with it
kubectl delete namespace wallos-test
```

## Alternative: locally, without a cluster

Same configuration on your machine, with Podman or Docker:

```sh
git clone https://github.com/thorstenhornung1/Wallos.git
cd Wallos
dev/up.sh
```

http://localhost:8383 and http://localhost:8025. Details in
[dev/README.md](../dev/README.md).
