# Test instance on test.hornung-bn.de

A throwaway Wallos on your k3s cluster, configured the way a real multi-user
installation would be: shared SMTP, currency and AI credentials supplied as
files, mail captured instead of sent, and a second account to prove that
inheritance and isolation work.

Everything below is copy-paste. Where a value is yours to choose it is marked.

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

DNS records pointing at your Traefik ingress:

```
test.hornung-bn.de
mail.test.hornung-bn.de
```

The container image is public, so no pull secret is needed:

```sh
podman pull ghcr.io/thorstenhornung1/wallos:latest
```

## 2. Secrets

The currency and AI keys are deliberately **invalid** below: nothing reaches a
paid provider while you test. Replace them only when you want to exercise a
real provider call.

```sh
kubectl create namespace wallos-test

kubectl -n wallos-test create secret generic wallos-secrets \
  --from-literal=smtp_password='test-smtp-password' \
  --from-literal=currency_api_key='invalid-currency-key' \
  --from-literal=ai_api_key='invalid-ai-key'
```

If you prefer these to come from Infisical, create the same three keys there
and let your existing sync produce the `wallos-secrets` secret — the file names
under `/run/secrets` are what the deployment expects, nothing else.

## 3. Deploy

```sh
kubectl apply -f https://raw.githubusercontent.com/thorstenhornung1/Wallos/main/docs/test-instance/wallos-test.yaml
```

Or from a checkout:

```sh
kubectl apply -f docs/test-instance/wallos-test.yaml
```

Watch it come up:

```sh
kubectl -n wallos-test rollout status deployment/wallos
kubectl -n wallos-test logs deployment/wallos | grep -i migration
```

You should see `Migration migrations/000055.php completed successfully.` and
`000056` — the instance configuration and the subscription indexes.

## 4. First accounts

Open https://test.hornung-bn.de. The first account you register becomes the
admin.

```
admin / choose a password
```

Then create a second, ordinary user in **Admin → Users** — call it `user2`.
Most of the interesting behaviour only shows up with two accounts.

## 5. The actual tests

The browser steps come first; 5.2 onwards adds a shell session.

### 5.1 Instance SMTP serves a user who configured nothing

As `admin`, go to **Settings → Notifications → Email**. It should already say
**Use instance SMTP**, and show:

```
SMTP Address: mailpit.wallos-test.svc.cluster.local:1025
SMTP Password: Managed externally
```

Enable email notifications, save, press **Test**. Then open
https://mail.test.hornung-bn.de — the mail is there, sender `Wallos Test
<wallos@test.hornung-bn.de>`.

The point: you never entered a server, a password or a sender.

### 5.2 The secret never reaches the browser

First a session to work with — the rest of this section reuses it:

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

Expected: `0` for both. The password exists, is used to send mail, and is not
in the page.

### 5.3 Environment-managed fields cannot be edited

As admin, **Admin → SMTP Settings**: host, port, encryption and sender are
filled in, greyed out, and labelled with the variable that owns them. The page
also lists them under *Managed by environment variables*.

Try to overwrite one anyway, using the session from 5.2:

```sh
curl -s -X POST "$BASE/endpoints/admin/savesmtpsettings.php" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -b "$COOKIES" \
  -d '{"smtpaddress":"evil.example.com","smtpport":"25","encryption":"none"}'
```

It answers `success` — the request was valid — and nothing changed:

```sh
kubectl -n wallos-test exec deployment/wallos -- \
  php -r '$d = new SQLite3("/var/www/html/db/wallos.db");
          $r = $d->querySingle("SELECT smtp_address FROM admin", true);
          echo "stored host: [" . $r["smtp_address"] . "]\n";'
```

The stored host is still empty: managed fields are skipped, never written. The
effective host keeps coming from `WALLOS_SMTP_HOST`.

### 5.4 A user inherits without being given anything

Log in as `user2`. **Settings → Notifications → Email** shows *Use instance
SMTP* and the same status. Enable notifications, press **Test**, check Mailpit:
the mail arrives, and `user2` never saw a credential.

Same in **Settings → Fixer API Key** and **AI recommendations**: provider and
model are visible, the key says *Managed externally*.

### 5.5 A user can still run their own transport

As `user2`, switch to **Use custom SMTP**, enter:

```
Host: mailpit.wallos-test.svc.cluster.local
Port: 1025
Encryption: none
From: user2@test.hornung-bn.de
```

Save and test. The mail arrives with `user2`'s sender — their transport, not
the instance's.

### 5.6 Notifications actually go out

Create a subscription due tomorrow with notifications on, set the notification
lead time to 1 day, then:

```sh
kubectl -n wallos-test exec deployment/wallos -- \
  php /var/www/html/endpoints/cronjobs/sendnotifications.php
```

Mailpit receives one mail per user with a due subscription, each through the
transport that user resolved to.

### 5.7 A broken secret file does not fall back

This is the behaviour that matters most in production. Point the password at a
file that is not there:

```sh
kubectl -n wallos-test set env deployment/wallos \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/does-not-exist
kubectl -n wallos-test rollout status deployment/wallos
```

Now the admin page reports the configuration as invalid and names the
unreadable path, and a test send refuses. It does **not** quietly use whatever
password happens to sit in the database — a rotation that half-failed must not
keep running on the old secret.

Put it back:

```sh
kubectl -n wallos-test set env deployment/wallos \
  WALLOS_SMTP_PASSWORD_FILE=/run/secrets/smtp_password
```

### 5.8 Cron jobs run clean

```sh
for job in sendnotifications sendcancellationnotifications updateexchange \
           sendverificationemails sendresetpasswordemails; do
  echo "--- $job"
  kubectl -n wallos-test exec deployment/wallos -- \
    php /var/www/html/endpoints/cronjobs/$job.php 2>&1 | tail -3
done
```

`updateexchange` will report that the provider could not be reached — expected,
the key is deliberately invalid. What matters is that no job produces a PHP
warning or fatal error.

## 6. Load, if you want to see the performance work

```sh
kubectl -n wallos-test exec deployment/wallos -- \
  php /var/www/html/dev/seed.php 10 1000
```

10 users, 10,000 subscriptions, prefixed `seed-` and removed on the next run.
The subscription list stays responsive: rates come from one query instead of
one per row, and the list query uses an index.

## 7. OIDC against your Authentik

Needed for the Part II work (locale-based provisioning, logout). In Authentik
create an OAuth2/OpenID provider and an application, with redirect URI:

```
https://test.hornung-bn.de/login.php
```

Then:

```sh
kubectl -n wallos-test create secret generic wallos-oidc \
  --from-literal=client_secret='<from Authentik>'

kubectl -n wallos-test set env deployment/wallos \
  OIDC_ENABLED=true \
  OIDC_PROVIDER_NAME=Authentik \
  OIDC_CLIENT_ID='<from Authentik>' \
  OIDC_CLIENT_SECRET_FILE=/run/secrets/oidc/client_secret \
  OIDC_ISSUER=https://auth.hornung-bn.de/application/o/wallos/ \
  OIDC_REDIRECT_URL=https://test.hornung-bn.de/login.php \
  OIDC_AUTO_CREATE_USER=true
```

Mount the second secret as well:

```sh
kubectl -n wallos-test patch deployment wallos --type=json -p='[
  {"op":"add","path":"/spec/template/spec/volumes/-",
   "value":{"name":"oidc","secret":{"secretName":"wallos-oidc","defaultMode":256}}},
  {"op":"add","path":"/spec/template/spec/containers/0/volumeMounts/-",
   "value":{"name":"oidc","mountPath":"/run/secrets/oidc","readOnly":true}}
]'
```

`auth.hornung-bn.de` is already in the SSRF allowlist in the manifest, which it
needs if it resolves to a private address.

Known limitations you will run into — these are the open issues, not
misconfiguration:

* an auto-created user is always English and EUR, regardless of the `locale`
  claim (#34, #35, #40)
* logout redirects without `id_token_hint`, so Authentik may not end the
  session (#36)
* logging out in Authentik leaves the Wallos session alive (#37)

## 8. Reset or remove

Start from an empty database without redeploying:

```sh
kubectl -n wallos-test exec deployment/wallos -- rm /var/www/html/db/wallos.db
kubectl -n wallos-test rollout restart deployment/wallos
```

Remove everything:

```sh
kubectl delete namespace wallos-test
```

The namespace takes the volumes with it — nothing survives, which is the point
of a test instance.

## Alternative: locally, without the cluster

Same configuration, on your machine:

```sh
git clone https://github.com/thorstenhornung1/Wallos.git
cd Wallos
dev/up.sh
```

http://localhost:8383 and http://localhost:8025. Details in
[dev/README.md](../dev/README.md).
