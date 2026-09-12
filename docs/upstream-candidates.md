# What can go upstream

Established 2026-08-24 by walking `upstream/main..origin/main` and checking each
candidate against the upstream tree rather than against memory. Every defect
below was confirmed to exist upstream by reading the upstream file; anything
that could not be confirmed is marked as such.

**Check the base before opening anything.** The comparison stand is
`upstream/main`, release **5.7.1**, since 2026-09-12. `v5_6_0` is dead: #1187
carried its content home and it has not moved since. Line numbers written before
a re-verification date still refer to the ref they were checked against, and
each entry names it.

**Read the 2026-09-12 section first.** Everything below it was written while the
question was "which cheap fix next". Thirteen of those have been sent and all
thirteen were merged; the question is now a different one, and the sections
below are kept as the record of how the list was built rather than as the
queue.

## 2026-09-12: all thirteen prepared branches are spent, and that changes the question

The 5.7.1 merge (`b98cd3c`) brought eleven of this fork's pull requests home.
Every `upstream-fix/*` branch prepared here is now in `upstream/main`:

| upstream PR | branch | released in |
|---|---|---|
| #1181 totp replay | `upstream-fix/totp-replay` | 5.5.0 |
| #1184 logout token | `upstream-fix/logout-token` | 5.5.0 |
| #1190 registration theme XSS | `upstream-fix/registration-theme-xss` | 5.6.0 |
| #1192 account deletion coverage | `upstream-fix/delete-account-coverage` | 5.6.0 |
| #1193 disable 2FA atomically | `upstream-fix/disable-totp` | 5.6.0 |
| #1194 enable 2FA atomically | `upstream-fix/enable-totp` | 5.6.0 |
| #1195 password reset | `upstream-fix/password-reset` | 5.6.0 |
| #1196 verify email | `upstream-fix/verify-email` | 5.6.0 |
| #1197 delete before replace | `upstream-fix/delete-before-replace` | 5.6.0 |
| #1198 order by the column | `upstream-fix/order-by-constant` | 5.6.0 |
| #1199 skip fresh rates | `upstream-fix/skip-fresh-rates` | 5.6.0 |
| #1200 payment logo result | `upstream-fix/payment-logo-result` | 5.6.0 |
| #1202 CI registry login | `upstream-fix/ci-pr-build-login` | 5.6.0 |

Thirteen sent, thirteen merged, none refused, none discussed. Delete the
branches or leave them; they are history now.

### The question that follows

Thirteen correctness fixes is a channel, not a contribution. Every one of them
was chosen for how little the maintainer had to take on trust, and that was the
right choice while the channel was unproven. It is proven. Continuing to pick
only the cheapest items now optimises for a constraint that no longer binds.

Two facts decide what to do instead, and both are new:

**He runs this fork's test harness, and writes in it.** `tests/bootstrap.php`,
`tests/run.php` and the migration-chain fixture went up with #1165–#1168.
5.6.0 and 5.7.0 arrive carrying *his own* cases written against them —
`logo_cleanup_test.php`, `pwa_manifest_test.php`, `notes_markdown_test.php`,
`ical_export_test.php`. A proposal that lands with tests is no longer an unusual
offer; it is the house style, and this fork set it.

**He already shipped the shape of our largest feature.** `includes/oidc_settings.php`
on `upstream/main` reads `OIDC_ENABLED`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`
**and `OIDC_CLIENT_SECRET_FILE`**, records which fields the environment owns in a
`managedFields` map, and reports the variable name that owns each one. That is
this fork's instance-configuration design, for one integration, written by him.
The proposal below is not "adopt our architecture"; it is "you built this for
OIDC — here is the same thing for the other three integrations, using your
helpers".

## Two tracks from here

The small fixes keep going, because they cost nothing and keep the channel warm.
But they are no longer the plan; they are the background. The plan is a queue of
substantial proposals, one at a time, each sent while a small fix is still
settling — and each carrying the two arguments a self-hoster actually decides on:
what comparable systems already do, and what it saves the person in the household
who never asked to run a server.

### Send first, before either track: a regression in 5.7.0

**A0. `webhookJsonEscape()` breaks every note that ends in a quotation mark.**

Found by this merge, in code the maintainer merged on 2026-09-10. The helper
5.7.0 added to keep a Markdown note from breaking the webhook payload is:

```php
return trim(json_encode((string) $value), '"');
```

`trim()` with a character mask strips *every* leading and trailing quote
character, not the two `json_encode()` added. A value ending in a quotation mark
therefore loses the closing quote of its own `\"` escape and the payload ends in
a bare backslash:

```
note     He said "hi"
escaped  He said \"hi\
payload  {"notes": "He said \"hi\"}      → json_decode(): syntax error
```

A note reading `cancel "soon"` is enough. Every webhook for that subscription is
then sent with a body the receiver cannot parse, and nothing on any screen says
so — the request goes out, it is simply nonsense. A note consisting of a single
`"` escapes to `\`, which is the same failure in its shortest form. The fix is
`substr($encoded, 1, -1)`: the two characters `json_encode()` actually added.

Why this goes first, ahead of everything else on this page:

* It is **his own newest code**, two days old, and it is a regression rather
  than an old defect — the feature it belongs to does not work for a plausible
  note.
* The argument is one line of output. Nothing to take on trust.
* It comes with a test in his harness, and his own test for this helper misses
  it because the note it uses ends in `- Bob`. The new case asks about the
  first and last character, which is exactly where a "strip the quotes"
  implementation goes wrong.
* It is the natural carrier for the rest of the escaping work below, which
  would otherwise be a PR about a doubled backslash.

Ride **B2** along with it, in the same pull request or immediately behind: the
other seven placeholders in the same `str_replace()` block are still
interpolated raw. Once the helper is correct, applying it to all eight is the
obvious next line, and it needs no separate argument.

The fork carries the fix already (`includes/webhook_helper.php`), with
`tests/cases/notes_markdown_test.php` asserting it and verified by putting
upstream's `trim()` back and watching six assertions fail.

### Track A — the substantial ones

Not one proposal. Seven, ranked, and each carries the same two arguments
because those are the two that decide whether a self-hoster adopts something:

**(a) it is what every comparable self-hosted application already does**, so the
maintainer is being asked to match a convention rather than to invent one; and
**(b) it removes work from the person in the household who did not choose to run
a server** — the partner, the parent, the teenager who just wants to see what
the family pays for.

Wallos is a *household* subscription tracker. Its own feature list says so: a
household table, per-member payers, shared categories. Every argument below
follows from that and from nothing else.

> The comparison systems are named from what they document publicly. Re-check
> the exact variable names against their current documentation before quoting
> one in a pull request — the pattern is what matters, and it is stable; the
> spelling of a particular variable is not.

---

**A1. Configuration from the environment, secrets from a file.**
`WALLOS_SMTP_*`, `WALLOS_CURRENCY_*`, `WALLOS_AI_*`, each with a `*_FILE`
sibling, shown read-only in the admin page with the variable that owns it.

*Home-lab standard, and not narrowly:* the `*_FILE` convention comes from the
official `postgres` and `mysql` images (`POSTGRES_PASSWORD_FILE`) and is how
Docker and Kubernetes secrets have been consumed ever since. Vaultwarden takes
`SMTP_HOST`/`SMTP_FROM` from the environment and nothing else. Gitea takes
`GITEA__mailer__*` with a `__FILE` suffix on any of them. The official Nextcloud
image takes `SMTP_HOST` and `NEXTCLOUD_ADMIN_PASSWORD_FILE`. Grafana takes
`GF_SMTP_HOST` and supports a `__FILE` suffix on every setting it has. Paperless
takes `PAPERLESS_*`. Authentik — the identity provider a lot of these households
already run — takes `AUTHENTIK_EMAIL__HOST`. Miniflux is configured by the
environment and by nothing else at all. **Wallos is the odd one out here, not
the candidate.**

*For the family:* nobody in the household ever sees an SMTP form. The one person
who set the server up put the mail credentials in the compose file once; every
other account simply has working password resets and working reminder mail. And
the credential is not sitting in a database row that a backup carries around.

*Shape:* one integration per pull request, SMTP first — and smaller than it
sounds, because **upstream already has an instance SMTP.** `migrations/000020.php`
puts `smtp_address`, `smtp_port`, `smtp_username`, `smtp_password`, `from_email`,
`encryption` and `server_url` on the `admin` table, `admin.php` renders them, and
`passwordreset.php:41` refuses to run until two of them are filled in. The
concept, the table and the screen are his. What is missing is the layer that lets
the deployment own those fields — which `OIDC_CLIENT_SECRET_FILE` already
concedes for the one integration that has it. Inert unless a variable is set.

---

**A2. The account an identity provider creates is in the household's language.**

`includes/oidc/oidc_create_user.php:10` hardcodes `$language = 'en'` and
`:13` hardcodes `$main_currency_id = 1`. Every account an IdP creates is
therefore English, whatever the provider said and whatever the instance is. The
person never sees the registration form that would have asked.

*Home-lab standard:* `locale` is a standard OIDC claim — it is in the core
specification's standard claim set, and Authentik, Authelia and Keycloak all
send it. `Accept-Language` has been in HTTP since 1996. Nextcloud, Immich and
Home Assistant all pick the language up rather than defaulting to English.
Reading a claim the provider already sends is not a feature; it is the absence
of a bug.

*For the family:* this is the single most visible thing in the whole list. A
German household runs Authentik, a family member clicks "Wallos", and lands in
an English application — with no idea that a language setting exists, because
they never went through a registration form. One claim, read once, and they land
in German.

*Shape:* small and self-contained. Read `locale` from the userinfo claims,
resolve it to a supported language, fall back to an instance default
(`WALLOS_DEFAULT_LANGUAGE`), fall back to `en`. Needs the tag resolver below,
which is why A3 travels with it or just ahead of it.

---

**A3. A language tag that is not an exact file name still resolves.**

`includes/i18n/getlang.php` matches the cookie against the keys of `$languages`,
which are file names: `pt_br`, `zh_cn`, `sr_lat`. Anything else is silently
English. So `de-DE` from a browser, `pt-BR` from an identity provider and
`zh-Hans` from anywhere all fall through to English — including every value
A2 would read.

*Home-lab standard:* BCP-47 is what browsers send in `Accept-Language` and what
identity providers put in `locale`. Every application that reads either one has
to normalise `de-DE` to `de` and `zh-Hans` to `zh_cn`. This is ten lines of
string handling that upstream does not have.

*For the family:* the same as A2 — it is the half of it that makes the claim
usable. On its own it also fixes the browser case: somebody opening Wallos for
the first time on a German phone gets German.

*Shape:* the fork's `wallos_resolve_language()`, adapted to upstream's file
names so that **nothing is renamed** — the full BCP-47 rename stays here. Accept
the tag, answer the file name. One function, one call site, a test table.

---

**A4. An exchange-rate provider that needs no account.**

Upstream offers exactly two: `fixer.io` and `apilayer.com`
(`settings.php:1158-1159`). Both require signing up, receiving an API key and
pasting it into a settings page. A household that tracks a subscription in CHF
and one in EUR cannot see a correct total until somebody does that.

*Home-lab standard:* the direction of travel in self-hosted software is away
from "register for an API key to use the thing you already installed".
Frankfurter serves European Central Bank reference rates over HTTPS with no
account, no key and no rate limit worth the name. It is the provider self-hosted
finance tools reach for precisely because it removes the signup.

*For the family:* it is the difference between "converted totals work" and
"converted totals work once Dad has made an account at a currency API". Nobody
who is not already running a server will do the second, and until they do, the
dashboard shows a number that is quietly wrong.

*Shape:* a third entry in the provider select and a third branch where the two
existing ones fetch — roughly sixty lines across three files against upstream's
inline style, plus a test. The fork's own `currency_provider.php` is 1,280 lines
and is **not** what goes; only the provider.

---

**A5. Reminders that arrive on the phone without configuring a service.**

Upstream has ten notification channels — email, Discord, Gotify, Telegram,
PushPlus, Mattermost, Pushover, ntfy, webhook, ServerChan. Every single one of
them requires the recipient, or somebody on their behalf, to set up an account,
a bot, a topic or a server first.

*Home-lab standard:* Web Push is what a progressive web app uses when it wants
to reach a phone with nothing installed — Home Assistant, Immich, Nextcloud and
Vikunja all do it. Wallos already ships a service worker and a manifest, and
5.7.0 just improved both; the remaining piece is VAPID keys and a subscription
table.

*For the family:* this is the one channel a non-technical household member can
turn on themselves. Open Wallos on the phone, allow notifications, done — no
Telegram bot, no ntfy topic, no Pushover licence. The renewal reminder is the
entire point of the application, and today it reaches exactly the people who
already run infrastructure.

*Shape:* `includes/webpush.php` is 712 lines here and carries an SSRF check and
a 410-Gone sweep that upstream would want. Medium size, one concern, and it is a
feature pull request rather than a fix — which is a kind he has merged from
others twice in the last two releases (#1191, #1207).

---

**A6. A new account's categories are in the account's language.**

`oidc_create_user.php:51-57`, `registration.php` and `endpoints/admin/adduser.php`
each seed seventeen English category names — "Food & Beverages", "Charity &
Donations" — and a list of English payment methods, whatever language was
chosen. The German-speaking account is German everywhere except in its own data.

*Home-lab standard:* seeding demo content in the user's language is what every
application that seeds demo content does. The argument is thinner here, and it
should be made thinner rather than dressed up: this is a polish item, not a
convention Wallos is breaking.

*For the family:* strong all the same. It is the first screen anybody sees, and
it is the one place where "the app is in German" visibly stops being true.

*Shape:* the seed lists move behind `translate()`, with the account's language
resolved at creation. The fork's one-time localizer for *existing* accounts
(`localize.php`) stays here — that is migration machinery for a decision
upstream has not taken yet.

---

**A7. The migration runner, behind migration 000016.**

Small diff, nine-year consequence, and not a tidy-up.

`migrations/000016.php` opens `SELECT COUNT(*) FROM notifications` and never
finalises it, then runs `DROP TABLE IF EXISTS notifications` while that result
is still open. SQLite refuses with "database table is locked", the `exec()`
result is not read, and `includes/run_migrations.php` records the migration as
applied regardless — in **every installation ever made**. The dead table is
still there. Demonstrable on his own database in one query, before he reads a
line of the diff.

The runner is the general case: `require_once` discards the migration's return
value, the `INSERT INTO migrations` is unconditional, "completed successfully"
is printed unconditionally, and the migrations query is held open across the
whole loop — the same lock, one layer up. Ours is portable except for
`$db->tableExists('migrations')`, which goes back to the `sqlite_master` query.

*No family argument, and it should not be given one.* This is an argument about
data integrity, addressed to the maintainer, and it is strong enough alone. Send
000016 first and the runner second: the first is the proof that the second is
needed.

---

**A8. The database boundary — still the conversation, not a patch.**

Tier 3, unchanged. What is new is a reason to believe an issue would be read.
What is not new is that a 500-file diff is unreviewable and that PostgreSQL is a
maintenance commitment he has never asked for. If it is opened at all, open it
as *the boundary* — one interface, a SQLite adapter that is a pass-through, no
second backend in the diff — and say plainly that the PostgreSQL adapter exists
here and is his to take or leave. Not before A1 lands.

---

### What deliberately carries no family argument

Worth naming, so that nobody reaches for one later and overstates it:

* **The rootless, hardened container.** Genuine home-lab standard — LinuxServer's
  `PUID`/`PGID`, Paperless's `USERMAP_UID`, Kubernetes' `runAsNonRoot`, rootless
  Podman by default, TrueNAS SCALE refusing root — and
  `upstream/main:Dockerfile:34` still does
  `chown -R www-data:www-data /var/www/html` with no `USER` directive at all, so
  the whole webroot is writable by the process serving it. But it helps the
  *operator*, not the family, and the nginx half of our fix depends on the
  ownership split, which is a wider change upstream than here. It stays where it
  is until the ownership work can travel with it.
* **The explicit admin role, OIDC session authority, back-channel logout, cron
  reporting, the archive work.** All of them help exactly one person per
  household: the one already running a server.

### Ordering, and why it is not the order of value

1. **A0** — his own two-day-old regression. Nothing to take on trust.
2. **A3 + A2 together** — the language a family actually lands in. Small, and it
   is the most visible defect in the list for a non-English household.
3. **A1 (SMTP)** — the convention argument, against his own precedent.
4. **A7** (000016, then the runner) — the integrity argument, which wants a
   reviewer who is already reading.
5. **A4**, then **A5**, then **A6** — the feature-shaped ones, once three have
   landed.
6. **A8** only after A1.

A2/A3 go ahead of A1 despite being smaller, because they cost him almost nothing
to read and they answer a complaint anybody with a non-English household can
reproduce in thirty seconds. A1 is the bigger prize and wants the reviewer warm.

### Track B — the background, one at a time between the big ones

Re-verified against `upstream/main` on 2026-09-12; every one still present.

**B1. `api/settings/set_settings.php` — the file that contradicts itself.**
Four writes discarded (`:87`, `:92` custom CSS; `:137`, `:144` custom colours),
then `success: true` — while the settings UPDATE in the same file (`:248`)
reads its result and answers "Database error". #1197 fixed the identical shape
in `api/fixer/set_fixer.php`; this is the copy it did not reach. The contrast is
the whole argument and needs no second file.

**B2. Finish the webhook escaping he just started.** 5.7.0 added
`webhookJsonEscape()` and applied it to `{{subscription_notes}}` alone
(`endpoints/cronjobs/sendnotifications.php:888`,
`sendcancellationnotifications.php`). The other seven placeholders — name,
price, currency, category, payer, date, url — are still interpolated raw into a
JSON string field.

On its own this is a weak candidate, and the measurement is worth writing down
rather than repeating the guess: every one of those fields goes through
`validate()`, which is `trim` → `stripslashes` → `htmlspecialchars`. A quote
becomes `&quot;` and a single backslash is eaten by `stripslashes`, so the only
input that still breaks the payload is a *doubled* backslash — `Acme\\Corp`
stores as `Acme\Corp` and `{"name": "Acme\Corp"}` is a syntax error. Real, and
not something anybody has hit.

Which is why it rides with A0 rather than going alone. Once the helper is
correct, "and apply it to the other seven" is a line in the same diff, and the
argument is consistency with his own fix rather than a backslash nobody types.

**B3. The `$payer` leak.** `sendnotifications.php:871-873` assigns `$payer`
inside the per-user loop only `if ($user['name'])`, and never resets it, so a
household member with no name inherits the previous member's name — into an
outgoing webhook, under `{{subscription_payer}}`. Three lines. Worth more now
that B2 puts somebody in that block anyway; send them together.

**B4. `endpoints/db/backup.php` — the archive that outlives the download.**
`readfile()` then `unlink()`: an aborted download ends the script inside
`readfile()`, the unlink never runs, and a complete database-and-uploads archive
stays in the temp directory. Offer `register_shutdown_function()`;
unlink-before-read is POSIX-only and a reviewer will say so.

**B5. `includes/validate_endpoint.php` — three refusals, all HTTP 200.** The
whole file is 22 lines and contains no `http_response_code` at all. Ours asks
`wallos_user_is_admin()`; upstream's asks `$userId !== 1`, so the admin half is
dropped and only the status codes travel.

### Still not portable

Container hardening and the nginx work (see "What deliberately carries no family
argument" above), cron reporting, the explicit admin role, OIDC back-channel
logout and session authority, the full BCP-47 rename with its migration, the
CLDR currency dataset, and the one-time localizer page.

Two corrections to how that list used to read, both of them measurements rather
than changes of mind:

* **Instance configuration is not part of the #32 conversation.** It was grouped
  there because it was assumed to need the database boundary. It does not — see
  A1, and upstream's own `OIDC_CLIENT_SECRET_FILE`.
* **Parts of the i18n work travel without the rest.** The full BCP-47 rename
  stays here, because it renames thirty files and needs a migration. Resolving a
  tag to a supported language (A3) and reading the provider's `locale` claim
  (A2) do not, and they are where the visible benefit is.

## Nothing goes to the maintainer without Thorsten asking for it

Stated 2026-08-25: pushes go to `origin` only. Opening a pull request against
`ellite/Wallos`, or commenting there, needs his explicit request **and** his
approval. Reading upstream is fine — this whole page is built from it. Treat the
list below as prepared work, not as a queue that runs itself.

## Already taken upstream — do not propose again

Four changes from this fork are in `upstream/v5_6_0`. Note how they got there,
because it decides the base for anything future:

They were opened against `main` and show as **CLOSED, not merged**. The
maintainer merged the branches into `v5_6_0` himself — the merge commits name
the PRs (`69b1e3f`, `fd96cdc`, `a566380`, `6809cba`) — and the stale PRs against
`main` were closed. Opening against `main` cost him a manual step.

| upstream | PR | our commit |
|---|---|---|
| `6809cba` | #1165 currency rate scoping | `7dd2ea4` |
| `a566380` | #1166 convert prices from a cached rate map | `917531d` |
| `fd96cdc` | #1167 atomic exchange rate refresh | `dc300d3` |
| `69b1e3f` | #1168 index the subscription queries | `c677d9a` |

`267f057` (#1175) also brought the scoping fix to `main`. The branches
`origin/perf/*` and `origin/fix/scope-currency-rate-updates` are therefore spent.

## 2026-09-01: the maintainer moved — the ground shifted

Both open PRs were merged on 2026-09-01 (~20:30 UTC), silently, as is his
pattern: **#1181** (totp replay) and **#1184** (logout token) went into his
collective PR **#1187 "v5.5.0"** (47 files, squashed to `main`), release-please
cut **5.5.0** (#1188). He also fixed the stats warnings himself (41494ef,
closes his #1182; our equivalent guard is already in the fork, so the next
merge meets code of the same shape — his open contributor PR #1183 was left
unused).

Consequences, each one binding for whoever picks this up:

* **The base is `upstream/main` now.** `v5_6_0` has not moved since our merge
  and is dead; #1187 carried its content home. Every future PR opens against
  `main`.
* **#1187 is merged into the fork** — `7697283`, 2026-09-02. See the section
  below for what the merge actually cost and found.
* **The three remaining single-file branches are re-verified and rebased onto
  `upstream/main`** (2026-09-02). All three defects still exist there. Each is
  one file and applies cleanly. They wait on Thorsten's send, as before.
* `origin/upstream-fix/totp-replay` and `origin/upstream-fix/logout-token`
  are **spent**.

## 2026-09-02: the merge, and what it found

`7697283` merges upstream 5.5.0. Three things are worth knowing before the
next merge.

**The squash moved the merge base.** #1187 squashed `v5_6_0` into `main`, so
`upstream/main` does not carry `v5_6_0` as an ancestor and git fell back to
5.4.4 — re-offering everything this fork already merged at `b373668`. Nine of
the twenty-four conflicts were that artefact. The check that settles it, for
next time:

```sh
git diff <the upstream ref we last merged>:$file upstream/main:$file
```

Empty means upstream contributed nothing since, and our side is simply newer.
Do not resolve those by eye.

**Two files were rebuilt byte-wise.** Resolving `admin.php` and
`includes/oidc/handle_oidc_callback.php` with a text tool rewrote 876 and 205
CRLF lines and turned a two-line change into a 1765-line diff. The tree mixes
line endings; conflict resolution in these files has to be binary-safe
(`git merge-file --diff3` plus a byte-level marker strip). The same trap bit a
later one-line insert: a replacement that matches a line *without* its newline
inserts before the existing `\r`, leaving `';\r\r\n`. Check for `\r\r\n`.

**What it found, in this fork rather than upstream:** `totp.php` issued a
remember-me token and never named it in `$_SESSION['token']`, which is the
only thing `logout.php` revokes. An account with 2FA therefore kept a usable
token across a logout while an account without 2FA did not — upstream #1184
again, surviving in the one login path the fix had not touched. Upstream
carries the line; the fork did not. `tests/cases/session_tokens_test.php` now
asserts that every token-issuing path names it.

**Decisions taken in the merge, so they are not re-litigated:**

* The SSRF gate follows upstream's `allow_standard_users_local_webhooks`
  opt-in rather than the ordering the fork used since #126. Upstream's setting
  is strictly more expressive — on it is the fork's previous behaviour, off it
  is stricter — and it answers what #126 asked for without letting an
  allowlist entered for an administrator's internal service become reachable
  by every account. The half upstream cannot have stays: the gate asks the
  role model, never the account number.
* His `migrations/000056.php` is our `000073` (000056 is this fork's
  subscription indexes, which he shipped as his 000055), through the boundary
  rather than `pragma_table_info`.
* His inline in-use count in `api/payment_methods/set_payment_methods.php`
  goes through the shared `wallos_subscriptions_referencing()`.
* His newly placed save button arrived with an id shadowing its own handler —
  the #95 defect. Placement taken, name kept.

### New candidate: `registration.php`, the page his own XSS fix missed

5.5.0 fixed reflected XSS through the `theme` and `colorTheme` cookies —
validate against a fixed list, encode what reaches the inline script — in
`login.php`, `totp.php` and `includes/header.php`. `registration.php` still
carries `window.colorTheme = "<?= $colorTheme ?>";` straight from the cookie.
It is the one page of the four reachable with no account at all.

**Prepared: `upstream-fix/registration-theme-xss`**, based on `upstream/main`,
+4/−3, one file, using the sanitizers 5.5.0 itself added — nothing new for a
reviewer to take on trust. The fork's own fix is `7ddc45d`, which also found
`passwordreset.php` and `verifyemail.php` reading both cookies unvalidated;
neither emits into a script, so neither was injectable, and they are fork-side
tidying rather than part of this PR.

Also still upstream, found while checking the above and not yet prepared:
`includes/stats_calculations.php` orders by `'order'` — a string constant, not
the column. SQLite sorts by nothing and PostgreSQL refuses the statement
outright. Our fix is one line (`"order"` double-quoted); it travels alone.

## The five to send, in this order

Decided 2026-09-03 out of the full list below. Five, not seventeen: the
maintainer is one person who merges in silence and in batches, and a queue he
cannot work through is a queue he stops reading. Each of these stands alone,
each is one concern, and the order is what to send *next* rather than what is
worth most.

**All five are built and pushed as of 2026-09-03, each with a regression test
that upstream's own harness runs, each checked by breaking it.** They wait on
the send decision alone.

| branch | files | test |
|---|---|---|
| `upstream-fix/registration-theme-xss` | +95/−3 | `theme_cookie_test.php` |
| `upstream-fix/payment-logo-result` | +177/−6 | `logo_fetch_result_test.php` |
| `upstream-fix/enable-totp` | +152/−17 | `totp_enrolment_test.php` |
| `upstream-fix/password-reset` | +181/−18 | `password_reset_test.php` |
| `upstream-fix/delete-account-coverage` | +132 | `account_deletion_test.php` |

Every one of them carries, without exception:

* **a regression test.** Upstream has our harness since #1165–#1168 and had
  never been offered a test with a fix. It is the largest lever this fork has,
  and until today it had never been pulled. Where the endpoint cannot be run
  from a test — most of these are scripts that need a session — the guard is
  structural, which is the idiom upstream's suite already carries, because we
  put it there in #1167. Two of the five also assert their guarantee against
  the built schema: that a rollback takes both halves of a 2FA enrolment with
  it, and that a rolled back token swap puts the previous reset token back.
* **no fork issue numbers, versions or migration numbers in the prose.** He
  publishes our comments verbatim.

**Every `upstream-fix/*` branch tracks `origin`, and it has to be set.** Cutting
one with `git checkout -b X upstream/main` makes `upstream` its tracking remote,
so a bare `git push` from it aims at `ellite/Wallos`. Eight branches were in
that state on 2026-09-03 and were repointed. The push would have failed for want
of write access, which is luck rather than a safeguard — the rule here is that
nothing reaches the maintainer without Thorsten asking for it, and a rule that
holds only because a credential is missing is not being kept. After cutting a
branch: `git branch --set-upstream-to=origin/<name>`.

**Never `git add -A` on an upstream branch.** It carries *upstream's*
`.gitignore`, not this fork's, so everything this fork ignores is fair game
there: `dev/secrets/` went into a commit that way on 2026-09-03 and had to be
taken back out before the branch was pushed. The values were placeholders, so
nothing leaked, and the next ones might not be. Add named paths.

It happened a second time the next morning, on
`upstream-fix/skip-fresh-rates`, and that time the agent worktrees under
`.claude/` came along too — after this note was written, by the person who
wrote it. A rule that lives only in a document is a rule that gets broken while
concentrating on something else. Until something enforces it, the habit that
actually works is to name the files:

```sh
git add includes/foo.php tests/cases/foo_test.php
git diff --stat upstream/main     # before pushing, every time
```

That `--stat` is the cheap catch. A branch touching more files than the change
touches is either this mistake or the line-ending one below, and both are
obvious in one line of output.

### 1. `registration.php` — finish the XSS fix he started

Ready: `upstream-fix/registration-theme-xss`, +4/−3.

5.5.0 fixed reflected XSS through the theme cookies in `login.php`, `totp.php`
and `includes/header.php`, using sanitizers he wrote, and missed
`registration.php` — the one page of the four reachable with no account. Four
lines, his own helpers, his own pattern. There is nothing to take on trust,
which is exactly why it goes first: it re-opens the conversation at zero cost
after two silent merges.

### 2. The payment-method logo failure — `Closes #1185`

**Built 2026-09-03: `upstream-fix/payment-logo-result`**, +177/−6 over three
files including its test.

`endpoints/payments/add.php`, `api/payment_methods/set_payment_methods.php`
(add and edit). The shape turned out cleaner than the survey suggested: the
helper exists in **four** copies, and the two subscription copies already
answer `['success' => bool, 'filename'|'message']` and are checked at the call
site. Only the two payment copies were inconsistent — one of them returning
three different types from one function. So the PR is "make these two match the
two you already wrote correctly", which needs no argument at all.

**#1185 is open, reported 2026-08-30 by a user**: "Unknown error, please try
again" when adding AMEX, logo found, nothing in the log. This is that. On
failure the logo helper returns an array where a string is expected, and it is
bound anyway. Upstream's own `endpoints/subscription/add.php:271` shows the
correct pattern.

All three call sites, not one — the edit branch is the worst of them, because a
failed fetch overwrites a working icon. A PR that closes a live complaint in
his tracker is worth more than any correctness argument we can make in the
abstract.

### 3. `endpoints/user/enable_totp.php` — the account nobody can reach again

**Built 2026-09-03: `upstream-fix/enable-totp`**, +152/−17 with its test. The
heaviest single finding in the tree.

DELETE, INSERT and `UPDATE user SET totp_enabled = 1`, none of them checked, no
transaction, then a hardcoded `success: true` with ten backup codes. If the
INSERT fails while the UPDATE succeeds, the account has `totp_enabled = 1` and
no secret: `login.php` sends the person to `totp.php`, which has nothing to
check against. No code and no backup code ever works again, and they have just
printed ten of them.

Our fix uses the boundary's transaction methods; the rewrite to `exec('BEGIN')`
is the same one `upstream-fix/password-reset` already carries, so it is proven
rather than invented. Note the file already holds our prose from #1181.

### 4. `passwordreset.php` — both halves

Ready: `upstream-fix/password-reset`, +88/−18, corrected 2026-09-03.

Same argument as 3, one step less severe, and it goes after 3 so the reviewer
meets the worst case while the pattern is fresh. Issuing the token and using it
both reported success over unchecked writes; the second is the one that tells
somebody their password changed while the old one still works and the only link
back in has been spent.

### 5. Account deletion — the twelve tables nobody removes

**Built 2026-09-03: `upstream-fix/delete-account-coverage`**, +132 across the
two files plus its test.

`endpoints/settings/deleteaccount.php` and `endpoints/admin/deleteuser.php`,
mechanical.

The figures were checked against the built schema rather than by parsing the
source, which is how the count settled: 38 tables, **31** carry a `user_id`,
both paths delete 19. A first attempt at parsing `CREATE TABLE` out of the
migrations answered 12 and 9 — wrong twice, and wrong in a way that looked
plausible. Twelve user-bound tables are never touched — `login_tokens` and `password_resets` among them. Upstream's `user`
table has no `AUTOINCREMENT`, so SQLite hands a deleted id straight back out:
delete the newest account, create another, and it inherits the leftovers. That
is what turns twelve tidy-up lines into a security argument.

**Coverage only. Leave the atomicity out** — that half needs fork work and
would turn a mechanical diff into a design discussion.

### Held back deliberately

`api/settings/set_settings.php` and `api/fixer/set_fixer.php` are the best
*arguments* on the list — each file contradicts itself, no second file needed —
but they fix nothing anyone has noticed. They are the next batch once these
five land, and they will be easier then, because 3 and 4 will have established
the same argument on files where the consequence was visible.

`upstream-fix/verify-email` and `upstream-fix/disable-totp` stay prepared and
unsent for the same reason. The `$payer` leak in `sendnotifications.php` is
three lines and obviously right, but narrow — it rides along with the next
notification change rather than spending a PR.

The OIDC callback (`login.php:143-144`) and the client secret in
`get_oidc_settings.php:92` are conversations, not patches. Opening them as PRs
would ask him to accept a changed UX contract in a diff.

### How to send them

One at a time, and wait. He merged #1181 and #1184 within days, silently, into a
collective release PR. Five at once asks him to schedule a review; one at a time
asks him to click merge. Send 1, and send 2 and 3 once it lands.

## 2026-09-03: the list re-verified against 5.5.0, and much longer

Every candidate below this section was re-read in the upstream file at
`upstream/main`. The list held; it also grew by seventeen. What follows is the
part that changes how the work is done — the full re-verification is the list
itself, updated in place.

### Two rules that apply to every PR from here on

**Strip fork issue numbers from the comment prose.** The maintainer takes our
comments *verbatim* — `upstream/main:logout.php:26-34` is our text, published
unedited. A `#87` in a comment therefore ships to a repository where #87 is
somebody else's issue. The same goes for fork version numbers ("5.8.2") and
fork migration numbers (000065–000071). Upstream numbers like #1185 and #990
are real there and stay. The four prepared branches were checked and are clean;
one of them had carried a stray `#87` until 2026-09-02.

**Bring a regression test.** Upstream now has our test harness —
`tests/bootstrap.php`, `tests/run.php`, the fixture that runs the migration
chain — all of it arrived through #1165–#1168. Every candidate here can ship
with a test the maintainer runs himself. That is the largest lever this fork
has upstream, and none of the PRs so far have used it.

### The prepared branches, corrected

`upstream-fix/password-reset` **fixed only half its file** and would have gone
out as a half fix. `passwordreset.php` reports success over unchecked writes
twice: issuing the token (`:63-75`, which the branch had) and *using* it
(`:114-123`, which it had not). The second is worse — the password UPDATE is
unchecked, the token is consumed regardless, and the person is told the
password changed while the old one still works and the one link back in has
been spent. Both halves are now in the branch (`2ed9ea7`).

The lesson generalises: these files carry the same defect more than once, and
finding it in one place is not finding it. Check the whole file before sending.

Likewise the payment-methods logo fix must cover **three** call sites, not one:
`endpoints/payments/add.php:220` and `api/payment_methods/set_payment_methods.php`
at `:286` (add) and `:395` (edit). The edit branch is the worst of the three —
a failed fetch overwrites a working icon.

### The strongest new candidates

Ordered, as always, by how little a reviewer has to take on trust.

* **The file that contradicts itself.** `api/settings/set_settings.php` discards
  four writes (`:83-90`, `:133-142`) and answers `success: true` at `:239` —
  while the settings UPDATE *in the same file* (`:227-236`) checks its result
  and answers "Database error". The contrast is the whole argument; no second
  file needed. Same shape in `api/fixer/set_fixer.php:141-144`, where the
  DELETE is unchecked and the INSERT right below it is checked.
* **A name leaking between people.** `endpoints/cronjobs/sendnotifications.php:871-873`
  sets `$payer` inside the per-user loop and never resets it, so a household
  member without a name inherits the previous member's name in
  `{{subscription_payer}}` — into an outgoing webhook. Three lines.
* **`endpoints/user/enable_totp.php:97-118` — the heaviest single finding.**
  DELETE, INSERT and `UPDATE user SET totp_enabled = 1`, none checked, no
  transaction, then a hardcoded `success: true` with the backup codes. If the
  INSERT fails while the UPDATE succeeds, the account has `totp_enabled = 1`
  and no secret: `login.php` sends the person to `totp.php`, which has nothing
  to check against. No code and no backup code ever works again — and they have
  just printed ten of them. Our fix uses the boundary's transaction methods;
  the rewrite to `exec('BEGIN')` is the same trick `upstream-fix/password-reset`
  already uses, so it is proven.
* **Twelve tables nobody deletes.** `deleteaccount.php` is a second complete
  copy of the `deleteuser.php` defect — the table lists are identical. The
  portable half is not the atomicity (that needs fork work) but the *coverage*:
  both paths delete 19 tables, upstream has 38, and twelve user-bound ones are
  never touched, `login_tokens` and `password_resets` among them. Upstream's
  `user` table has no `AUTOINCREMENT`, so SQLite hands a deleted id straight
  back out — which is what turns twelve tidy-up lines into a security argument.
  Send the coverage; leave the atomicity.
* **`endpoints/db/backup.php:85-86`** — `readfile()` then `unlink()`. An aborted
  download ends the script inside `readfile()`, the unlink never runs, and a
  complete database-and-uploads archive stays in the temp directory forever.
  Offer `register_shutdown_function` in the body: unlink-before-read is
  POSIX-only and a reviewer will say so.

### Conversations, not patches

`login.php:143-144` never consumes an OIDC callback (only
`includes/checksession.php` does), so a provider callback aimed at `login.php`
is discarded silently — expect "then configure the right redirect URI".
`api/admin/get_oidc_settings.php:92` returns `client_secret` including one
resolved from `OIDC_CLIENT_SECRET_FILE`, and `admin.php` renders it as a text
input: admin-only, so hardening rather than a hole, but a mounted container
secret ends up in the page source. Both change a UX contract and want agreement
first.

### Checked and dropped

#93 (upstream fixed it), the nginx work (the real hole is the Dockerfile
`chown`, and proposing our fix without the ownership work would give a false
sense of it), everything PostgreSQL-motivated, the `cron_run.php` reporting, and
the `integration_config.php` and `validate_endpoint_session.php` refactorings —
upstream already checks the session inline there.

## Portable, in the order they should go out

Priority is by how little a reviewer has to take on trust, not by how much the
change is worth to us.

### 1. `totp.php` — the replay guard never runs

`upstream/v5_6_0:totp.php:58` selects `totp_secret, backup_codes,
failed_attempts, lockout_until` and not `last_totp_used`. Line 74 therefore
reads `$lastUsedStep = (int) ($row['last_totp_used'] ?? 0)` as 0 always, and the
check at line 123 (`$valid && $matchedStep <= $lastUsedStep`) can never be true.
The guard, its comment and the write that maintains it all exist; the column is
simply missing from the SELECT. It has been there since `migrations/000027.php`.

**One word in a SELECT.** Opened 2026-08-28 as **upstream PR #1181**, base
`v5_6_0`, from `origin/upstream-fix/totp-replay` (+12/−4) — verified after the
fact to carry exactly that one file. The body frames the impact as limited —
replaying needs the password plus a code observed inside the leeway window —
at Thorsten's direction, not as a hard vulnerability. It went out alone; after
two days without any reaction, Thorsten approved sending the next one
(2026-08-30) rather than waiting longer.

### 2. `logout.php` — the login token survives logout

`upstream/v5_6_0:logout.php:27-31` runs `DELETE FROM login_tokens WHERE token =
:token AND user_id = :userId`, and `$userId` is never assigned in that file —
it includes only `includes/connect.php` (11 lines, sets `$db` alone) and
`includes/oidc_settings.php`. The predicate is therefore `user_id = NULL` and
matches nothing. Every logout leaves a valid remember-me token behind.

Branch `origin/upstream-fix/logout-token` (+16/−3). The one discussion point is
dropping the `user_id` predicate; the argument — the token is 32 random bytes
and is itself the credential — is in the commit message.

Opened 2026-08-30 as **upstream PR #1184**, base `v5_6_0`, with Thorsten's
approval. The body states precisely what the defect does and does not do: the
logging-out browser loses its cookie, so nothing looks wrong in normal use —
what never happens is the revocation, so any other holder of the cookie value
stays signed in for the token's 30-day lifetime.

### 3. `migrations/000016.php` — one `finalize()`

Lines 44-45 open `SELECT COUNT(*) as count FROM notifications`; lines 57 and 60
run `DROP TABLE IF EXISTS notifications` while that result is still open. SQLite
refuses ("database table is locked") and the `exec` result is not checked, so
the migration records itself as applied with its work undone — in every
installation ever made.

**One line**, and the maintainer can see the defect on his own database before
reading the diff. It is also the natural argument for #4.

### 4. The migration runner

`upstream/v5_6_0:includes/run_migrations.php:30-38` includes each migration,
inserts the row unconditionally, and prints success unconditionally. Our version
(`f007247`) is portable except for **one line**: `$db->tableExists('migrations')`
has to go back to the `sqlite_master` query, since upstream has no boundary.

Sell it behind #3: upstream has no migration that returns `false` yet, so the
change is inert until it is needed — and 000016 is the proof that it is needed.

### 5. #95 — eighteen dead buttons

Confirmed against `upstream/v5_6_0`: `admin.php` 193, 292, 340, 342, 367, 504,
542, 545/546; `profile.php` 182, 208, 211, 227, 273; `settings.php` 90, 124,
489, 1000, 1317. An element id shadows the handler of the same name, so the
button does nothing.

**Not cherry-pickable.** `admin.php` and `settings.php` have diverged here
through the instance-configuration work; the renames have to be reapplied to the
upstream files. Nine files and a naming convention to agree on — valuable, but
not a first PR.

### Also portable, lower down

* **`endpoints/user/disable_totp.php`** — `UPDATE user SET totp_enabled = 0` and
  `DELETE FROM totp` both unchecked, then `success: true`. If the update fails
  and the delete succeeds, the account keeps `totp_enabled = 1` with no
  enrolment row: unreachable by any credential, and the user has just read "2FA
  is off". Branch `origin/upstream-fix/disable-totp`, rebased onto
  `upstream/main` 2026-09-02 (+58/−14, one file). Re-verified: the defect is
  intact and now sits in *two* branches upstream — the TOTP path and the
  backup-code path — and the patch covers both. `enable_totp.php` moved in
  5.5.0; `disable_totp.php` did not.
* **#97a** — `includes/validate_endpoint.php` (22 lines) contains no
  `http_response_code` at all; three exit paths all answer 200. Needs adapting:
  ours uses `wallos_user_is_admin()`, upstream `$userId !== 1`.
* **#93** — `endpoints/payments/delete.php` deletes without any reference check
  and answers `success: true`. `endpoints/categories/category.php:102-113` shows
  upstream's own correct pattern. Needs rewriting, not cherry-picking: our
  version goes through the boundary. Also needs a `payment_method_in_use` key.
* **`verifyemail.php`** — the DELETE *is* the verification and its result is
  discarded; the redirect to `login.php?validated=true` happens regardless.
  **Prepared: `origin/upstream-fix/verify-email`**, rebased onto
  `upstream/main` 2026-09-02, +24/−6, one file. Uses only the SQLite3 API, so
  it applies as-is. The re-verification changed it: it used to redirect a
  failure to `login.php?validated=false`, and upstream's `login.php` only ever
  reads `validated == "true"` — so the person would have landed on a silent
  page. It now falls through to `verifyemail.php`'s own error box, which
  already renders `email_verification_failed`. No new message, one fewer
  redirect. A stray reference to a fork issue number was removed with it.
* **`passwordreset.php`** — DELETE then INSERT, neither checked,
  `$hasSuccessMessage = true` unconditional. If the insert fails the old token
  is already gone and the account has no way back.
  **Prepared: `origin/upstream-fix/password-reset`**, rebased onto
  `upstream/main` 2026-09-02, +48/−9, one file. Re-verified: the defect is
  intact at `upstream/main:passwordreset.php:63-75`, and `$hasErrorMessage`
  already exists there and renders `translate('error')` in an error box, so
  the patch needs no new key.

  Two things a reviewer will look for, and both are deliberate. The fork's fix
  lives in `includes/password_reset.php` and uses the boundary's
  `beginTransaction()`; the patch inlines it with `exec('BEGIN')` so it needs
  no new file and no abstraction upstream does not have. And the success
  message stays **unconditional for an unknown address** — answering
  differently for a registered and an unregistered address would turn the form
  into an account enumeration oracle, which is evidently why it was written
  that way. Only a genuine failure to store the token changes the answer.
* **`includes/http_status.php` + the `set_fixer.php` half of #101** — upstream
  carries the same asymmetry (`ignore_errors` on line 107, absent on 113).
* **The progress bar before a subscription has begun (#114)** — the calculation
  reconstructs the period start by walking whole cycles back from
  `next_payment` and never consults `start_date`, so a subscription starting in
  the future shows up to 96 % progress. `upstream/v5_6_0` carries the
  byte-identical function in `includes/list_subscriptions.php`. Our fix
  (`74fe954`) moves the arithmetic into `includes/subscription_progress.php`
  with no boundary dependency, so it adapts with little work.

## Not portable

`nginx`: only `6ce11cd` (deny `/db/` by prefix) travels. The second nginx commit
assumes the 5.8.0 ownership split; `upstream/v5_6_0:Dockerfile:34` still does
`chown -R www-data:www-data /var/www/html`, so upstream's whole webroot is
writable by the web server user. **The problem is wider there, not narrower** —
proposing our fix without the ownership work would give a false sense of it.

Everything resting on the boundary or PostgreSQL belongs to the #32 conversation
instead: the abstraction itself, the PostgreSQL backend, the instance
configuration work, cron reporting (2000+ lines), the explicit admin role, OIDC
back-channel logout, BCP-47.

Three upstream defects were confirmed but have no portable fix yet, because
ours depend on fork-only work: `endpoints/admin/deleteuser.php:18-20` deletes
the `user` row before its 15+ dependent tables with no transaction and every
result discarded; `endpoints/subscription/add.php:237-245` binds `$_POST`
straight into the insert with no existence or ownership check; and the OIDC
logout (`upstream/v5_6_0:logout.php:38-41`) sends `post_logout_redirect_uri`
with no `id_token_hint` on **every** logout — a certification-compliant
provider answers 400 — while a remember-me-restored session does not attempt
provider logout at all, silently leaving the provider session alive. A
portable fix would have to carry the id-token persistence from our
RP-initiated logout work (`960b514`, and #123 for the remaining remember-me
gap) with it.

## The base, decided

**`upstream/main`**, since 2026-09-01.

This reverses what this section said before, and the reversal is the
maintainer's doing rather than a change of mind. The first four PRs were
opened against `main`, closed unmerged, and merged into `v5_6_0` by hand,
which is why `v5_6_0` was the answer. #1181 and #1184 were then opened against
`v5_6_0` — and he merged them by folding `v5_6_0` wholesale into `main` as the
squashed #1187, after which `v5_6_0` stopped moving. There is no version
branch to aim at now; the four branches prepared here are all rebased onto
`main`.
