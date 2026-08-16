# Local development and test environment

Everything runs in containers; no local PHP, Composer or database is required.
`podman` is used in the examples, `docker` works the same way
(`CONTAINER_ENGINE=docker dev/test.sh`).

## Test suite

```sh
dev/test.sh                # every case
dev/test.sh currency       # only cases matching "currency"
```

The suite is a zero-dependency harness in `tests/`, matching the way Wallos
vendors its libraries. It builds the real schema by running
`endpoints/cronjobs/createdatabase.php` and the migration chain inside a
throwaway copy of the source tree, then hands each case its own database file.

Cases registered with `wallos_test_pending()` describe behaviour the
specification requires but the code does not implement yet. They are reported
as `open` and do not fail the run; when one starts passing, the runner says so
and the case can be promoted.

## Full application

```sh
dev/up.sh
```

On first run this copies `dev/secrets.example` to `dev/secrets`, which is
git-ignored, and then starts the stack.

| Service | URL | Purpose |
| --- | --- | --- |
| Wallos | http://localhost:8383 | the application, with the working copy mounted |
| Mailpit | http://localhost:8025 | catches every mail Wallos sends |

The working copy is mounted into the container, so a PHP change is live on the
next request. Restart only when the `Dockerfile`, cron definitions or nginx
configuration change.

The environment is configured the way a real self-hosted instance would be:
shared SMTP, currency and AI credentials, with the secrets supplied through
`*_FILE` variables from `dev/secrets/`. Those files contain obvious dummy
values — the currency and AI keys are intentionally invalid so nothing reaches
a paid provider. Edit `dev/secrets/*` to try real credentials; the files are
git-ignored.

To exercise the database-managed path instead of the environment-managed one,
comment the `WALLOS_*` variables out and configure the same values in
**Admin → SMTP Settings** and **Admin → Instance Integrations**.

### End-to-end checks

```sh
dev/e2e.sh
```

Registers an account, renders the settings and admin pages, asserts that no
instance secret reaches the HTML, sends a mail through the instance transport
and checks it arrived in Mailpit, then runs every mail and currency cron job
and fails on PHP errors.

### Representative data

```sh
podman exec wallos-dev php /var/www/html/dev/seed.php 10 100
```

Seeds users and subscriptions for query-count and page-timing work. Seeded rows
are prefixed `seed-` and are replaced on each run; real accounts are untouched.
Useful sizes from the specification: `1 100`, `10 1000`, `100 10000`.

### Tearing down

```sh
podman compose -f dev/compose.yaml down
```

The development database lives in `db/wallos.db` of the working copy and
survives a restart. Delete that file to start from a fresh installation.
