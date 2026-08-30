# Switching an existing Wallos installation to this fork

This fork publishes its own container image. Your subscriptions, users and
settings are unaffected by the switch: the database schema is a superset of
upstream's, and the new migration only adds columns and one table.

| | upstream | this fork |
| --- | --- | --- |
| image | `bellamy/wallos:latest` | `ghcr.io/thorstenhornung1/wallos:latest` |
| version | 5.4.4 | 5.5.0 |
| update check | compares against `ellite/Wallos` | compares against this fork |

Tags published by the build:

* `latest` — the newest release
* `5.5.0`, `5.5` — a specific version, pinned
* `main` — the current state of the main branch, ahead of the last release

Pin `5.5.0` if you want no surprises; use `latest` to follow releases.

## 1. Back up first

The switch is reversible only if you can go back to the database as it was.
The migration adds columns; downgrading to upstream afterwards works because
upstream ignores them, but a backup is still the safety net.

```sh
# stop the container, then copy the database and uploaded logos
cp /path/to/wallos/db/wallos.db  /path/to/backup/wallos.db.$(date +%F)
cp -R /path/to/wallos/logos      /path/to/backup/logos.$(date +%F)
```

The Admin page also has a backup button, which produces the same thing as a zip.

## 2. Change the image

### Docker Compose / Podman Compose

```yaml
services:
  wallos:
    # image: bellamy/wallos:latest
    image: ghcr.io/thorstenhornung1/wallos:latest
```

```sh
docker compose pull && docker compose up -d
```

### Plain docker/podman run

Replace the image name in your run command, then recreate the container. The
volumes stay as they are:

```sh
docker rm -f wallos
docker run -d --name wallos \
  -v /path/to/wallos/db:/var/www/html/db \
  -v /path/to/wallos/logos:/var/www/html/images/uploads/logos \
  -e TZ=Europe/Berlin \
  -p 8282:80 --restart unless-stopped \
  ghcr.io/thorstenhornung1/wallos:latest
```

### Kubernetes / k3s

```yaml
spec:
  containers:
    - name: wallos
      image: ghcr.io/thorstenhornung1/wallos:5.5.0
```

```sh
kubectl set image deployment/wallos wallos=ghcr.io/thorstenhornung1/wallos:5.5.0
kubectl rollout status deployment/wallos
```

### If the package is private

GitHub publishes packages from a fork as private by default. Either make it
public once (Repository → Packages → wallos → Package settings → Change
visibility), or give the runtime a pull secret:

```sh
# read-only token with the read:packages scope
docker login ghcr.io -u thorstenhornung1
```

```sh
kubectl create secret docker-registry ghcr \
  --docker-server=ghcr.io \
  --docker-username=thorstenhornung1 \
  --docker-password='<token with read:packages>'
```

## 3. Start it

Nothing else is required. On start the container runs the migration chain, adds
the new columns and the `integration_settings` table, and marks every user who
already had their own SMTP server, currency key or AI provider as `custom` — so
they keep working exactly as before.

Verify:

```sh
docker logs wallos | grep -i migration
```

`Migration migrations/000055.php completed successfully.` and `000056` confirm
the instance configuration and the subscription indexes are in place.

## 4. Optional: configure shared infrastructure

Nothing forces you to use the new features. If you want one SMTP server, one
exchange rate key and one AI provider for every user instead of per-account
copies, set them in **Admin → SMTP Settings** and **Admin → Instance
Integrations**, or declare them:

```yaml
environment:
  WALLOS_SMTP_HOST: smtp.example.com
  WALLOS_SMTP_PORT: "587"
  WALLOS_SMTP_ENCRYPTION: tls
  WALLOS_SMTP_USERNAME: wallos
  WALLOS_SMTP_PASSWORD_FILE: /run/secrets/smtp_password
  WALLOS_SMTP_FROM: wallos@example.com

  WALLOS_CURRENCY_PROVIDER: apilayer
  WALLOS_CURRENCY_API_KEY_FILE: /run/secrets/currency_api_key

  WALLOS_AI_PROVIDER: chatgpt
  WALLOS_AI_MODEL: gpt-4o-mini
  WALLOS_AI_API_KEY_FILE: /run/secrets/ai_api_key
```

Environment-managed fields appear read-only in the Admin UI with the variable
that owns them, and are never written to the database. Existing users keep
their own settings until they switch themselves; new users inherit the instance
configuration without being handed a credential.

The full variable list is in the README.

## 5. Going back

```yaml
image: bellamy/wallos:latest
```

Upstream ignores the added columns and the extra table, so an existing
installation keeps working. The instance configuration itself stops applying —
users who inherited it will need their own settings again, which is why the
migration never deletes what they had before.

## Running unprivileged, and read-only

Since #86 the container runs under `user:` and with a read-only root
filesystem; both are optional, and the default — root with `PUID`/`PGID` —
stays the recommended path for plain Docker.

**`PUID`/`PGID`** (root mode, the default): the container starts as root,
remaps `www-data` to the given ids, chowns the two data mounts and drops to
that identity for every request and cron job. This is what existing compose
files already do, and nothing about it changed.

**`user:` mode**: the container starts and stays unprivileged. Nothing inside
it can remap users or fix ownership, so the two mounts have to be writable by
the chosen identity up front. The image follows the gid-0 convention, so the
simplest form works against fresh volumes without any host-side preparation:

```yaml
services:
  wallos:
    image: ghcr.io/thorstenhornung1/wallos:latest
    user: "1000:0"        # any uid; gid 0 is what grants access
    ports:
      - "8282:80"         # port 80 works unprivileged (bind capability)
    volumes:
      - wallos-db:/var/www/html/db
      - wallos-logos:/var/www/html/images/uploads/logos
```

For an **arbitrary gid** (`user: 1000:1000`) or existing bind mounts, prepare
the directories on the host first — `chown -R 1000:1000 <db-dir> <logos-dir>`.
An unprepared mount does not fail silently: the startup preflight refuses to
start and prints exactly that command. Note that SQLite needs write access to
the directory, not just the database file, for its `-wal` and `-journal`
companions.

**Read-only root**: everything ephemeral lives under `/tmp`, so one tmpfs
covers it:

```yaml
    read_only: true
    tmpfs:
      - /tmp
```

**Dropped capabilities**: port 80 works unprivileged because the nginx binary
carries `cap_net_bind_service`. A deployment that runs
`cap_drop: [ALL]` — the Kubernetes `restricted` profile, for instance — loses
that, so move the listener up instead:

```yaml
    environment:
      WALLOS_HTTP_PORT: "8080"
```

The built-in healthcheck follows the same variable.
