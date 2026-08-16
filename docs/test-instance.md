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

### Why one replica, pinned

The volumes are node-local and SQLite has a single writer. The service is
therefore constrained to one node and updates use `order: stop-first`, so the
old task is gone before the new one starts. Do not put the database on CephFS
or NFS to make it float — SQLite locking is not safe on a network filesystem,
and that is the constraint the optional PostgreSQL backend exists to remove.

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
for page in settings.php admin.php; do
  printf '%s: %s\n' "$page" \
    "$(curl -s -b "$COOKIES" "$BASE/$page" | grep -c test-smtp-password)"
done
```

Expected: `0` for both. The password exists, sends mail, and is not in the page.

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

```sh
$EXEC php /var/www/html/dev/seed.php 10 1000
```

10 users, 10,000 subscriptions, prefixed `seed-` and replaced on each run. The
subscription list stays responsive: rates come from one query instead of one per
row, and the list query uses an index.

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

Limitations you will hit — these are open issues, not misconfiguration:

* an auto-created user is always English and EUR, whatever the `locale` claim
  says ([#34](https://github.com/thorstenhornung1/Wallos/issues/34),
  [#35](https://github.com/thorstenhornung1/Wallos/issues/35),
  [#40](https://github.com/thorstenhornung1/Wallos/issues/40))
* logout redirects without `id_token_hint`, so Authentik may not end the session
  ([#36](https://github.com/thorstenhornung1/Wallos/issues/36))
* logging out in Authentik leaves the Wallos session alive
  ([#37](https://github.com/thorstenhornung1/Wallos/issues/37))

## 8. Reset or remove

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
