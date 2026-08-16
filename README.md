<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./images/siteicons/walloswhite.png">
    <source media="(prefers-color-scheme: light)" srcset="./images/siteicons/wallos.png">
    <img alt="Wallos" src="./images/siteicons/wallos.png">
  </picture>

  <p>Wallos: Open-Source Personal Subscription Tracker</p>

  [![Stars](https://img.shields.io/github/stars/ellite/Wallos?style=flat-square)](https://github.com/ellite/Wallos)
  [![Docker](https://img.shields.io/docker/pulls/bellamy/wallos?style=flat-square)](https://hub.docker.com/r/bellamy/wallos)
  [![GitHub contributors](https://img.shields.io/github/contributors/ellite/Wallos?style=flat-square)](https://github.com/ellite/Wallos/graphs/contributors)
  [![GitHub Sponsors](https://img.shields.io/github/sponsors/ellite?style=flat-square)](https://github.com/sponsors/ellite)
  [![Discord](https://img.shields.io/discord/1237073478910214235?logo=discord&style=flat-square)](https://discord.gg/anex9GUrPW)
</div>


## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [Demo](#demo)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
    - [Baremetal](#baremetal)
    - [Docker](#docker)
  - [Installation](#installation)
    - [Baremetal](#baremetal-1)
      - [Updating](#updating)
    - [Docker](#docker-1)
    - [Docker-Compose](#docker-compose)
- [Usage](#usage)
- [Screenshots](#screenshots)
- [OIDC](#oidc)
- [API Documentation](#api-documentation)
- [Contributing](#contributing)
  - [Contributors](#contributors)
  - [Translations](#translations)
- [License](#license)
- [Links](#links)

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

#### Docker

- Docker

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

## OIDC

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

## About this fork

This is a fork of [ellite/Wallos](https://github.com/ellite/Wallos) that adds
instance-wide configuration for shared infrastructure, plus correctness and
performance fixes.

```sh
docker pull ghcr.io/thorstenhornung1/wallos:latest
```

Switching an existing installation over is described in
[docs/switching-to-this-fork.md](docs/switching-to-this-fork.md), and
[docs/test-instance.md](docs/test-instance.md) sets up a throwaway instance on
Kubernetes with a mail sink, for trying it before touching anything real. Your data is
unaffected: the schema only gains columns, and every user who configured their
own SMTP server, currency key or AI provider keeps it.

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
| `WALLOS_CURRENCY_PROVIDER` | `fixer` or `apilayer` |
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
    image: bellamy/wallos:latest
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

Host based integrations keep their SSRF validation: self-hosted SMTP servers, Ollama and OpenAI-compatible endpoints on private addresses still need to be present in the allowlist described below.

## API Documentation

Wallos provides a comprehensive API that allows you to interact with the application programmatically. The API documentation is available at [https://api.wallosapp.com/](https://api.wallosapp.com/).

## Contributing

Feel free to open Pull requests with bug fixes and features. I'll do my best to keep an eye on those.  
Feel free to open issues with bug reports or feature requests. Bug fixes will take priority.  
I welcome contributions from the community and look forward to working with you to improve this project.

### Contributors

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

I chose the GNU General Public License version 3 (GPLv3) for this project because it ensures that the software remains open source and freely available to the community. GPLv3 mandates that any derivative works or modifications must also be released under the same license, promoting the principles of software freedom.

I strongly believe in the importance of open source software and the collaborative nature of development, and I invite contributors to help improve this project.

## Links

- The author: [henrique.pt](https://henrique.pt)
- Wallos Landingpage: [wallosapp.com](https://wallosapp.com)
- Join the conversation: [Discord Server](https://discord.gg/anex9GUrPW)
