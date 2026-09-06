<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./images/siteicons/walloswhite.png">
    <source media="(prefers-color-scheme: light)" srcset="./images/siteicons/wallos.png">
    <img alt="Wallos" src="./images/siteicons/wallos.png">
  </picture>

  <p>Wallos: Open-Source Personal Subscription Tracker</p>
  <p><em>A personal fork of <a href="https://github.com/ellite/Wallos">ellite/Wallos</a></em></p>

  [![Stars](https://img.shields.io/github/stars/ellite/Wallos?style=flat-square)](https://github.com/ellite/Wallos)
  [![Docker](https://img.shields.io/docker/pulls/bellamy/wallos?style=flat-square)](https://hub.docker.com/r/bellamy/wallos)
  [![GitHub contributors](https://img.shields.io/github/contributors/ellite/Wallos?style=flat-square)](https://github.com/ellite/Wallos/graphs/contributors)
  [![GitHub Sponsors](https://img.shields.io/github/sponsors/ellite?style=flat-square)](https://github.com/sponsors/ellite)
  [![Discord](https://img.shields.io/discord/1237073478910214235?logo=discord&style=flat-square)](https://discord.gg/anex9GUrPW)
</div>

> [!WARNING]
> **This is a personal fork of Wallos, under active development.**
> It carries **no guarantee of stability** and may break at any time.
> **It is not intended for production use.** If you want a stable Wallos, use
> [upstream `ellite/Wallos`](https://github.com/ellite/Wallos) instead.

## Table of Contents

- [About this fork](#about-this-fork)
- [What this fork adds](#what-this-fork-adds)
  - [PostgreSQL support](#postgresql-support)
  - [Rootless, hardened container](#rootless-hardened-container)
  - [OIDC and SSO](#oidc-and-sso)
  - [Notifications](#notifications)
  - [Currency](#currency)
  - [Internationalization](#internationalization)
  - [Instance-wide configuration](#instance-wide-configuration)
  - [Engineering and correctness](#engineering-and-correctness)
- [Introduction](#introduction)
- [Features](#features)
- [Demo](#demo)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
- [Usage](#usage)
- [Screenshots](#screenshots)
- [OIDC configuration](#oidc-configuration)
- [Shared instance integrations](#shared-instance-integrations)
- [API Documentation](#api-documentation)
- [Contributing](#contributing)
- [License](#license)
- [Links](#links)

## About this fork

Wallos is created and maintained by Henrique Dias (**[ellite](https://github.com/ellite)**)
at **[ellite/Wallos](https://github.com/ellite/Wallos)** — all credit for Wallos
itself belongs there, and this fork would not exist without it.

This repository, **[thorstenhornung1/Wallos](https://github.com/thorstenhornung1/Wallos)**,
is a personal fork that tracks upstream and adds a PostgreSQL backend, deeper
OIDC/SSO, a rootless and hardened container, instance-wide/declarative
configuration, and further notification and currency options — together with a
test suite and a set of static dev gates that upstream does not carry and that
keep those additions honest. See [What this fork adds](#what-this-fork-adds) for
the detail.

The fork's container image is published to the GitHub Container Registry:

```sh
docker pull ghcr.io/thorstenhornung1/wallos:latest
```

Switching an existing installation over is described in
[docs/switching-to-this-fork.md](docs/switching-to-this-fork.md), and
[docs/test-instance.md](docs/test-instance.md) sets up a throwaway instance for
trying it before touching anything real. Your data is unaffected: the schema
only gains columns, and every user who configured their own SMTP server,
currency key or AI provider keeps it. Backend-independent fixes made here are
also offered back to upstream as individual pull requests.

## What this fork adds

Everything below is on top of upstream Wallos. Upstream is SQLite-only and ships
no `tests/` or `dev/` tooling; the fork adds both, plus the features described
here.

### PostgreSQL support

Wallos can run on **PostgreSQL** as a full second backend beside the original
SQLite, selected by environment variable (`WALLOS_DB_DRIVER=pgsql`). A database
boundary under `includes/database/` isolates the driver-specific code, a static
db-boundary audit keeps SQLite-only APIs from leaking outside it, and the entire
test suite runs against **both** backends. A one-way migrator
(`dev/migrate-to-pgsql.php`) copies an existing SQLite database into PostgreSQL
in a single transaction — resetting every sequence past the highest id copied —
and a shadow-migration harness rehearses the full upgrade path against a copy of
real data. CI runs the suite against the oldest and newest PostgreSQL versions
the project still supports.

### Rootless, hardened container

The container runs **unprivileged**. Application code is owned by `root` and only
the data directories are writable by the web user, so a PHP-level flaw cannot
rewrite the scripts that cron runs. nginx binds port 80 through a file
capability (`cap_net_bind_service`) rather than running as root; a group-0
ownership convention lets `user: <uid>:0` write fresh volumes with no host-side
`chown`; and the image supports a **read-only root filesystem** and
`cap_drop: ALL` (moving the listener above 1024 via `WALLOS_HTTP_PORT`). The
build drops the compiler toolchain and headers from the final image (about 25%
smaller) while keeping every runtime library, and php-fpm idles down to the
master process on a quiet instance. Four container privilege modes are booted
end-to-end by `dev/container-modes.sh`.

### OIDC and SSO

Building on upstream's basic OIDC, the fork implements a deeper integration:

- **Authorization-code flow with PKCE** (RFC 7636, S256), binding the code to
  the browser that began the login independently of the client secret.
- **Userinfo-based sign-in** with discovery, an enforced `issuer` match, and
  **https-only** provider endpoints (loopback excepted for local development).
- **Back-channel logout** and **RP-initiated logout** with `id_token_hint`,
  including the remember-me path.
- **Refresh and session-lifetime handling**: the refresh token is stored per
  session and the access token is refreshed at half its life, so the provider
  governs the session for its whole lifetime rather than only the few minutes an
  access token lives.
- **OIDC Session Authority v2**: the identity provider is authoritative for the
  session's lifetime. A token refresh alone can no longer keep an ended session
  alive; past the back-channel coverage boundary an idle session must silently
  re-prove itself with a `prompt=none` + `id_token_hint` revalidation
  (`/oidc/revalidate`, bound to the same `(iss, sub)`), and a provider that has
  ended the session refuses to re-admit it.
- **Avatar import** from the provider's `picture` claim (verified by magic bytes,
  size-bounded, stored content-addressed; a bad or absent picture is ignored).
- **Profile and admin-role sync**: name, email, language and admin-role
  membership are refreshed from the provider on each sign-in and enforced
  server-side, with the managed fields shown read-only in the profile.
- **SSRF hardening**: JWKS, discovery and endpoint fetches route through the
  security allowlist, and a malformed logout token is rejected before any
  outbound request.

It can be configured declaratively via `OIDC_*` environment variables — see
[OIDC configuration](#oidc-configuration).

### Notifications

- A **Web Push** channel (RFC 8291, implemented on OpenSSL with no new runtime
  dependency) delivers browser/PWA push, including iOS Home-Screen web apps,
  alongside the existing channels. It uses an instance VAPID keypair (generated
  on first use, env-overridable), stores per-user device subscriptions, and
  drops a subscription on a 404/410; outbound push is SSRF-guarded.
- An **instance-configuration resolver** covers **Telegram, Pushover, ntfy and
  Gotify**: the administrator (or an environment secret) sets the shared
  credential once for the whole instance — bot token, application token, ntfy
  server and auth headers, Gotify host — and each user supplies only their own
  identifier (chat id, user key, topic, per-user token). One user's identifier
  never reaches another's delivery, and existing per-user configurations keep
  working untouched.

### Currency

- **Frankfurter** is available as a **keyless** exchange-rate provider — no
  account, no API key, no request quota; selecting it is the whole
  configuration. Fixer stays first-class and nobody is migrated, and a currency
  Frankfurter cannot price (for example a cryptocurrency) keeps its previous
  rate and is named in the cron report rather than moving silently to nothing.
- **Currency names and symbols come from Unicode CLDR**, pinned at a specific
  release (48.2.1) and shipped **offline** for every supported language. The
  runtime resolves through a locale &rarr; English &rarr; provider &rarr;
  ISO-code fallback that never touches the network, ICU or Symfony.

### Internationalization

- **Seed-time localization of default names**: default currency and
  payment-method names are localized into the account's language at account
  creation — the way categories already were — instead of being stored as
  English literals, wired through every provisioning path. The code stays the
  canonical identity; the localized name becomes user-owned data.
- A fresh install's **first-admin account is localized from the start** (to the
  instance default language), and an **opt-in Settings action** can localize an
  existing account's still-default names with a per-row preview. The localizer
  is data-driven (a row must still equal a known default), touches only the
  cosmetic `name` column, and never changes a value the user renamed.

### Instance-wide configuration

In a multi-user installation, SMTP, the exchange-rate provider and the AI
provider are usually infrastructure that belongs to the installation, not to
each user. The fork lets them be configured **once for the whole instance** —
in the Admin UI or declaratively through `WALLOS_*` environment variables (each
with a `*_FILE` companion that reads the value from a Docker/Kubernetes/Podman
secret) — and every user inherits them unless they opt into their own. Existing
per-user credentials are migrated to a `custom` mode and keep working untouched.
The full variable tables are under
[Shared instance integrations](#shared-instance-integrations).

### Engineering and correctness

- A **dual-backend test suite** (`tests/`, zero external dependencies) builds the
  real schema and runs every case against both SQLite and PostgreSQL, with CI
  covering the supported PostgreSQL version range.
- **Static dev audit gates** that ratchet against a recorded baseline:
  a **db-boundary** audit (SQLite-only APIs must stay behind the database
  boundary), a **write-audit** (a discarded result from a statement that writes,
  detected path-sensitively), a **bind-audit** (a prepared statement whose binds
  and placeholders disagree — the "SQLite tolerates, PostgreSQL rejects" class),
  and **container-mode** checks that boot the image in each privilege mode.
- An off-CI **growth-curve trend benchmark** (`dev/benchmark.sh`) that records
  how hot paths scale with data size across releases, rather than gating on an
  absolute figure.
- A number of **correctness and security fixes specific to this fork** —
  running on a second backend and inside a hardened container. Backend-
  independent fixes are additionally contributed upstream.

## Introduction

Wallos is a powerful, open-source, and self-hostable web application designed to empower you in managing your finances with ease. Say goodbye to complicated spreadsheets and expensive financial software – Wallos simplifies the process of tracking expenses and helps you gain better control over your financial life.

## Features

- Subscription Management: Keep track of your recurring subscriptions and payments, ensuring you never miss a due date.
- Category Management: Organize your expenses into customizable categories, enabling you to gain insights into your spending habits.
- Multi-Currency support: Wallos supports multiple currencies, allowing you to manage your finances in the currency of your choice.
- Currency Conversion: Integrates with the Fixer API so you can get exchange rates and see all your subscriptions on your main currency.
- Data Privacy: As a self-hosted application, Wallos ensures that your financial data remains private and secure on your own server.
- Customization: Tailor Wallos to your needs with customizable categories, currencies, themes and other display options.
- Sorting Options: Allowing you to view your subscriptions from different perspectives.
- Logo Search: Wallos can search the web for the logo of your subscriptions if you don't have them available for upload.
- Mobile view: Wallos on the go.
- Statistics: Another perspective into your spendings.
- Notifications:  Wallos supports multiple notification methods (email, discord, pushover, telegram, gotify and webhooks). Get notified about your upcoming payments.
- Multi Language support.
- OIDC with OAuth
- AI Recommendations with ChatGPT, Gemini or Local Ollama

## Demo

If you want to try Wallos, a demo is available at [https://demo.wallosapp.com](https://demo.wallosapp.com).  
The database is reset every 2 hours.  
To access the demo use the following credentials:

```python
Username: demo  
Password: demo
```

## Getting Started

See instructions to run Wallos below.

### Prerequisites

#### Baremetal

- NGINX or APACHE websever running
- PHP 8.3 with the following modules enabled:
    - curl
    - dom
    - gd
    - intl
    - openssl
    - sqlite3
    - zip
    - mbstring
    - fpm
    - `pdo_pgsql` — only if you use the [PostgreSQL backend](#postgresql-support)

#### Docker

- Docker

> The Docker examples below use upstream's `bellamy/wallos` image. To run **this
> fork**, substitute `ghcr.io/thorstenhornung1/wallos:latest`.

### Installation

#### Baremetal

1. Download or clone this repo and move the files into your web root - usually `/var/www/html`
2. Rename `/db/wallos.empty.db` to `/db/wallos.db`
3. Open the app in your browser — migrations run automatically on the registration page
4. Add the following scripts to your cronjobs with `crontab -e`

```bash
0 1 * * * php /var/www/html/endpoints/cronjobs/updatenextpayment.php >> /var/log/cron/updatenextpayment.log 2>&1
0 2 * * * php /var/www/html/endpoints/cronjobs/updateexchange.php >> /var/log/cron/updateexchange.log 2>&1
0 8 * * * php /var/www/html/endpoints/cronjobs/sendcancellationnotifications.php >> /var/log/cron/sendcancellationnotifications.log 2>&1
0 9 * * * php /var/www/html/endpoints/cronjobs/sendnotifications.php >> /var/log/cron/sendnotifications.log 2>&1
*/2 * * * * php /var/www/html/endpoints/cronjobs/sendverificationemails.php >> /var/log/cron/sendverificationemail.log 2>&1
*/2 * * * * php /var/www/html/endpoints/cronjobs/sendresetpasswordemails.php >> /var/log/cron/sendresetpasswordemails.log 2>&1
0 */6 * * * php /var/www/html/endpoints/cronjobs/checkforupdates.php >> /var/log/cron/checkforupdates.log 2>&1
30 1 * * 1 php /var/www/html/endpoints/cronjobs/storetotalyearlycost.php >> /var/log/cron/storetotalyearlycost.log 2>&1
30 3 * * 1 php /var/www/html/endpoints/cronjobs/generaterecommendations.php weekly >> /var/log/cron/generaterecommendations.log 2>&1
0 4 1 * * php /var/www/html/endpoints/cronjobs/generaterecommendations.php monthly >> /var/log/cron/generaterecommendations.log 2>&1
```

5. If your web root is not `/var/www/html/` adjust the cronjobs above accordingly.

#### Updating

1. Re-download the repo and move the files into the correct folder or do `git pull` (if you used git clone before)
2. Check the [Prerequisites](#baremetal) and install / enable the missing ones, if any.
3. Run http://domain.example/endpoints/db/migrate.php if you are logged in, or via CLI run:
```bash
php /var/www/html/endpoints/db/migrate.php
```

#### Docker

```bash
docker run -d --name wallos -v /path/to/config/wallos/db:/var/www/html/db \
-v /path/to/config/wallos/logos:/var/www/html/images/uploads/logos \
-e TZ=Europe/Berlin -p 8282:80 --restart unless-stopped \
bellamy/wallos:latest
```

Disable healthcheck (optional, e.g., for Docker <25 or faster startup reporting):

```bash
docker run -d --name wallos -v /path/to/config/wallos/db:/var/www/html/db \
-v /path/to/config/wallos/logos:/var/www/html/images/uploads/logos \
-e TZ=Europe/Berlin -p 8282:80 --restart unless-stopped \
--health-cmd=NONE \
bellamy/wallos:latest
```

### Docker Compose

```
services:
  wallos:
    container_name: wallos
    image: bellamy/wallos:latest
    ports:
      - "8282:80/tcp"
    environment:
      TZ: 'America/Toronto'
    # Volumes store your data between container upgrades
    volumes:
      - './db:/var/www/html/db'
      - './logos:/var/www/html/images/uploads/logos'
    restart: unless-stopped
```

Disable healthcheck (optional, e.g., for Docker <25 or faster startup reporting):

```
services:
  wallos:
    container_name: wallos
    image: bellamy/wallos:latest
    ports:
      - "8282:80/tcp"
    environment:
      TZ: 'America/Toronto'
    volumes:
      - './db:/var/www/html/db'
      - './logos:/var/www/html/images/uploads/logos'
    restart: unless-stopped
    healthcheck:
      test: ["NONE"]
```

## Usage

Just open the browser and open `ip:port` of the machine running wallos.  
On the first time you run wallos a user account must be created.  
Go to settings and personalise your Avatar and add members of your household. While there add / remove any categories and currencies.  
Get a free API Key from [Fixer](https://fixer.io/#pricing_plan) and add it in the settings.  
If you want to trigger an Update of the exchange rates, change your main currency after adding the API Key, and then change it back to your preferred one.  

## Screenshots

![Screenshot](screenshots/wallos-subscriptions-light.png)

<details>
<summary>See more screenshots</summary>

![Screenshot](screenshots/wallos-subscriptions-dark.png)

![Screenshot](screenshots/wallos-subscriptions-popup.png)

![Screenshot](screenshots/wallos-dashboard-light.png)

![Screenshot](screenshots/wallos-dashboard-dark.png)

![Screenshot](screenshots/wallos-stats.png)

![Screenshot](screenshots/wallos-calendar.png)

![Screenshot](screenshots/wallos-form.png)

![Screenshot](screenshots/wallos-subscriptions-mobile-light.png) ![Screenshot](screenshots/wallos-subscriptions-mobile-dark.png)

![Screenshot](screenshots/wallos-subscriptions-mobile-sheet.png)

![Screenshot](screenshots/wallos-dashboard-mobile-light.png) ![Screenshot](screenshots/wallos-dashboard-mobile-dark.png)

</details>

## OIDC configuration

OIDC can be enabled on the Admin page and can be used with providers that support OAuth.
Wallos can also resolve OIDC settings declaratively from environment variables. When an `OIDC_*` variable is set, it overrides the corresponding database value at runtime without rewriting the database.

If `OIDC_ISSUER` is set, Wallos will fetch `/.well-known/openid-configuration` at runtime and use discovery for the authorization, token, and user info endpoints unless a more specific endpoint variable is also set.

| Environment Variable | UI Equivalent |
| --- | --- |
| `OIDC_ENABLED` | `Enable OIDC/OAuth` |
| `OIDC_PROVIDER_NAME` | `Provider Name` |
| `OIDC_CLIENT_ID` | `Client ID` |
| `OIDC_CLIENT_SECRET` | `Client Secret` |
| `OIDC_CLIENT_SECRET_FILE` | `Client Secret` |
| `OIDC_ISSUER` | No direct UI field |
| `OIDC_AUTH_URL` | `Auth URL` |
| `OIDC_TOKEN_URL` | `Token URL` |
| `OIDC_USERINFO_URL` | `User Info URL` |
| `OIDC_REDIRECT_URL` | `Redirect URL` |
| `OIDC_LOGOUT_URL` | `Logout URL` |
| `OIDC_USER_IDENTIFIER` | `User Identifier Field` |
| `OIDC_SCOPES` | `Scopes` |
| `OIDC_AUTO_CREATE_USER` | `Create user automatically` |
| `OIDC_DISABLE_PASSWORD_LOGIN` | `Disable password login` |
| `OIDC_REQUIRE_EMAIL_VERIFIED` | `Require verified email for account linking` |

### SSRF allowlist

Wallos blocks webhook, SMTP, and OIDC endpoint URLs that resolve to private/link-local/loopback addresses unless the host is present in the Security Settings allowlist. Normally that allowlist is edited through the Admin UI, which requires a manual login before OIDC can be used against an identity provider on a private address (e.g. a self-hosted IdP at `auth.example.com`).

Setting the `SSRF_ALLOWLIST` environment variable overrides the database value entirely (same full-override semantics as the `OIDC_*` variables above), so the allowlist can be provisioned on first boot with no manual UI step. It accepts a comma-separated list of hosts/IPs, optionally with a port (e.g. `SSRF_ALLOWLIST=auth.example.com,192.168.1.100:8123`). While set, the Security Settings field in the Admin UI is shown but disabled.

## Shared instance integrations

In a multi-user installation, SMTP, the currency exchange provider and the AI provider are usually infrastructure that belongs to the installation, not to each individual user. Wallos can therefore configure them once for the whole instance, and every user inherits them by default.

Each of those integrations offers an explicit choice in the user settings:

* **Use instance …** — the credentials configured for the installation are used. They are resolved at runtime and never sent to the browser.
* **Use custom …** — the user's own credentials are used, exactly as before.

Existing installations keep working after an upgrade: a user who already had their own SMTP server, currency API key or AI provider is migrated to `custom`, so nothing changes for them until they deliberately switch.

Personal settings stay personal. Notification recipients, chat IDs, topics, webhook destinations, the main currency, whether AI recommendations are enabled and their schedule remain per user.

Instance values can be set in **Admin → SMTP Settings** and **Admin → Instance Integrations**, or declaratively through environment variables. When a variable is set, it takes precedence over the database, the corresponding admin field is shown read-only, and the value is never written to SQLite.

| Environment Variable | Purpose |
| --- | --- |
| `WALLOS_SMTP_HOST` | SMTP server address |
| `WALLOS_SMTP_PORT` | SMTP port |
| `WALLOS_SMTP_ENCRYPTION` | `none`, `tls` or `ssl` |
| `WALLOS_SMTP_USERNAME` | SMTP username |
| `WALLOS_SMTP_PASSWORD` | SMTP password |
| `WALLOS_SMTP_PASSWORD_FILE` | Path to a file containing the SMTP password |
| `WALLOS_SMTP_FROM` | Sender address |
| `WALLOS_SMTP_FROM_NAME` | Sender name (optional) |
| `WALLOS_CURRENCY_PROVIDER` | `fixer`, `apilayer` or `frankfurter` |
| `WALLOS_CURRENCY_API_KEY` | Exchange rate provider API key |
| `WALLOS_CURRENCY_API_KEY_FILE` | Path to a file containing that API key |
| `WALLOS_AI_PROVIDER` | `chatgpt`, `gemini`, `openrouter`, `ollama` or `openai-compatible` |
| `WALLOS_AI_API_KEY` | AI provider API key |
| `WALLOS_AI_API_KEY_FILE` | Path to a file containing that API key |
| `WALLOS_AI_BASE_URL` | Base URL for `ollama` and `openai-compatible` |
| `WALLOS_AI_MODEL` | Model used by default |

### Secret files

Every secret variable has a `*_FILE` companion that reads the value from a file, which fits Docker Secrets, Kubernetes Secrets, Podman Secrets and any other mounted secret. Trailing newlines are stripped; the rest of the file is used verbatim.

The `*_FILE` variant takes precedence over the plain variable. If a configured secret file cannot be read, the integration is reported as misconfigured rather than falling back to a previously stored credential, so a failed rotation never silently keeps using the old secret.

```yaml
services:
  wallos:
    image: ghcr.io/thorstenhornung1/wallos:latest
    environment:
      WALLOS_SMTP_HOST: smtp.example.internal
      WALLOS_SMTP_PORT: "587"
      WALLOS_SMTP_ENCRYPTION: tls
      WALLOS_SMTP_USERNAME: wallos
      WALLOS_SMTP_PASSWORD_FILE: /run/secrets/smtp_password
      WALLOS_SMTP_FROM: wallos@example.com
      WALLOS_CURRENCY_PROVIDER: apilayer
      WALLOS_CURRENCY_API_KEY_FILE: /run/secrets/currency_api_key
      WALLOS_AI_PROVIDER: openai-compatible
      WALLOS_AI_BASE_URL: https://llm.example.internal/v1
      WALLOS_AI_MODEL: example-model
      WALLOS_AI_API_KEY_FILE: /run/secrets/ai_api_key
      SSRF_ALLOWLIST: smtp.example.internal,llm.example.internal
    secrets:
      - smtp_password
      - currency_api_key
      - ai_api_key

secrets:
  smtp_password:
    file: ./secrets/smtp_password
  currency_api_key:
    file: ./secrets/currency_api_key
  ai_api_key:
    file: ./secrets/ai_api_key
```

Host based integrations keep their SSRF validation: self-hosted SMTP servers, Ollama and OpenAI-compatible endpoints on private addresses still need to be present in the allowlist described above.

## API Documentation

Wallos provides a comprehensive API that allows you to interact with the application programmatically. The API documentation is available at [https://api.wallosapp.com/](https://api.wallosapp.com/).

## Contributing

This is a personal fork. For the upstream project, feel free to open Pull
requests and issues at [ellite/Wallos](https://github.com/ellite/Wallos) — bug
fixes there take priority and benefit everyone. Fork-specific issues can be
raised on [this repository](https://github.com/thorstenhornung1/Wallos).
Backend-independent fixes made here are offered back to upstream as individual
pull requests.

### Contributors

Wallos is built by the upstream community:

<a href="https://github.com/ellite/wallos/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=ellite/wallos" />
</a>

### Translations

If you want to contribute with a translation of wallos:
- Add your language code to `includes/i18n/languages.php` in the format `"en" => ["name" => "English", "dir" => "ltr"],`. Please use the original language name and not the english translation.
- Create a copy of the file `includes/i18n/en.php` and rename it to the language code you used above. Example: pt.php for "pt" => ["name" => "Português", "dir" => "ltr"],.
- Translate all the values on the language file to the new language. (Incomplete translations will not be accepted).
- Create a copy of the file `scripts/i18n/en.js` and rename it to the language code you used above. Example: pt.js for "pt" => ["name" => "Português", "dir" => "ltr"],.
- Translate all the values on the language file to the new language. (Incomplete translations will not be accepted).

## License

This project is licensed under the [GNU General Public License, Version 3](LICENSE.md) - see the [LICENSE.md](LICENSE.md) file for details.

### Why GPLv3?

The GNU General Public License version 3 (GPLv3) was chosen for this project because it ensures that the software remains open source and freely available to the community. GPLv3 mandates that any derivative works or modifications must also be released under the same license, promoting the principles of software freedom.

This fork is distributed under the same license, in keeping with the spirit of open source and the collaborative nature of development.

## Links

- Upstream project: [ellite/Wallos](https://github.com/ellite/Wallos)
- The upstream author: [henrique.pt](https://henrique.pt)
- Wallos Landingpage: [wallosapp.com](https://wallosapp.com)
- Join the conversation: [Discord Server](https://discord.gg/anex9GUrPW)
</content>
</invoke>
