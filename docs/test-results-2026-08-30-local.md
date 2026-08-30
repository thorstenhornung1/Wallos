# Test run 2026-08-30 — main at 4dd68e7, local round

A targeted local round against a real running instance, focused on the
2026-08-30 changes: the backup/restore staging and ephemeral-write work of
[#85](https://github.com/thorstenhornung1/Wallos/issues/85)/[#86](https://github.com/thorstenhornung1/Wallos/issues/86)
(unreleased on main), the union fetch of
[#9](https://github.com/thorstenhornung1/Wallos/issues/9) (unreleased), and
the two changes that shipped in 5.8.9 the same morning —
[#124](https://github.com/thorstenhornung1/Wallos/issues/124) (clearing the
stored OIDC client secret) and
[#106](https://github.com/thorstenhornung1/Wallos/issues/106) (the
`fixer_usage` endpoint). The rootless matrix of #86 runs separately and was
deliberately left out; the shared test instance was never touched.

**Result: every behaviour under test does what it claims. Four findings on
the way, one of them methodological and the largest: `dev/e2e.sh`'s
second-account gate is vacuous on a fresh database — the account it tests
against is never created, because registrations are closed by default.**

## Environment

Local development stack from `dev/compose.yaml`, started out of this
worktree with `dev/up.sh` — the working copy bind-mounted into the
container, SQLite by default, Mailpit receiving everything, the currency key
deliberately invalid so refreshes fail in a controlled way instead of
spending a real quota. Not the shared test instance.

```
$ git rev-parse --short HEAD
4dd68e7                      # main, 8 commits past the v5.8.9 release commit

$ podman ps --format '{{.Names}}\t{{.Ports}}'
wallos-dev            0.0.0.0:8383->80/tcp
wallos-dev-postgres   0.0.0.0:5433->5432/tcp
wallos-dev-mailpit    0.0.0.0:8025->8025/tcp

$ podman exec wallos-dev php -r 'include "/var/www/html/includes/version.php"; echo $version;'
v5.8.9                       # version.php has not moved since the release
```

Fresh state: the worktree carried no `db/wallos.db`, so the container built
its database from nothing — first registered account and all. Migration
chain through `000072.php`, confirmed by the e2e suite below. Ports 8383,
8025 and 5433 were free before the start; one exited container from another
session's rootless work (`wallos-mode-gid0-70445`, later `wm2-debug`
running) was present on the host and left alone.

Fixture credentials, throwaway by design: `e2e` / `E2ePass123!` (admin,
id 1, created by the e2e suite), `qa2` / `QaPass123!` (id 2, created for
section 3 — see finding 1 for why that took a detour).

## Baseline — `dev/e2e.sh` fully green

All 20 checks pass, from the migration chain (`through 000072.php`) and
"startup produced no PHP errors" through role separation, secret hiding,
instance SMTP into Mailpit, and the five cron jobs. The suite's own
"a second account cannot reach the admin page" check is green for the wrong
reason — finding 1.

## Results

| # | Test | Result | Evidence |
| --- | --- | --- | --- |
| 0 | `dev/e2e.sh` complete | **pass** | 20/20 ok, `end-to-end checks passed` |
| 1.1 | Backup download (#85) | **pass** | HTTP 200, `application/zip`, 96 965 bytes, `unzip -t`: no errors |
| 1.2 | No backup staging remnant | **pass** | `ls /tmp/wallos_backup_*` → No such file or directory |
| 1.3 | Restore refuses a non-zip | **pass** (finding 2) | `success:false`, staging empty afterwards — but HTTP 200 |
| 1.4 | Real restore round-trip | **pass** | `success:true`, staging empty, re-login works, data intact |
| 2a | API sets client_secret | **pass** | DB reads `'testsecret'` |
| 2b | Empty secret means unchanged | **pass** | DB still `'testsecret'` |
| 2c | `clear_client_secret=1` clears | **pass** | DB reads `''` |
| 2d | clear + new secret refused | **pass** (finding 3) | exact refusal message, DB unchanged |
| 3.1 | `fixer_usage` answers | **pass** | `success:true`, `provider_reports:true`, `local_calls`, `rates_updated` |
| 3.2 | One run, one call — identical lists | **pass** | counter 1 → 2, two due users |
| 3.3 | One run, one call — differing lists (#9) | **pass** | counter 5 → 6, 34-code vs 3-code list |
| 4.1 | Sessions in `/tmp/wallos-sessions` | **pass** | 9 `sess_*` files there, none in `/tmp` directly |
| 4.2 | Cron logs under `/tmp/cron/` | **pass** | scheduled jobs and the crontab-form run both write there |
| 4.3 | `/var/log/nginx` empty | **pass** | directory exists, zero files; runtime state under `/tmp/nginx` |
| 4.4 | No webroot `.tmp` content | **pass** (finding 4) | only the tracked `.gitignore` remains |

## 1. Backup and restore with the moved staging (#85/#86)

As the first registered account (`e2e`, the local admin), with a curl cookie
jar and the CSRF token from `settings.php` — `endpoints/db/backup.php` and
`restore.php` sit behind `validate_endpoint.php`, so both want POST plus
`X-CSRF-Token`, exactly as `scripts/admin.js` sends them.

**1.1 — the download.**

```
$ curl -b admin.jar -X POST -H "X-CSRF-Token: $CSRF" -o backup.zip \
      -w 'HTTP=%{http_code} type=%{content_type} size=%{size_download}' \
      http://localhost:8383/endpoints/db/backup.php
HTTP=200 type=application/zip size=96965

$ unzip -t backup.zip | tail -1
No errors detected in compressed data of backup.zip.
```

The archive holds `wallos.db` (274 432 bytes), `wallos.empty.db`,
`setup_token.db` and the `logos/` tree — the SQLite file-copy branch, as an
existing installation's archives expect.

**1.2 — nothing stays behind.** The #85 change unlinks the temp file before
the first byte is streamed; the open handle carries the download. After the
download:

```
$ podman exec wallos-dev ls /tmp/
cron  nginx  nginx.pid  wallos-sessions        # no wallos_backup_*
```

**1.3 — the refusal path cleans up.** A plain text file uploaded as `file`:

```
{"success":false,"message":"Failed to extract the uploaded file"}
HTTP=200

$ podman exec wallos-dev find /tmp/wallos-restore -mindepth 1 | wc -l
0
```

The staging directory (`/tmp/wallos-restore`, mode 0700, outside the
webroot since #86) exists and is empty — the shutdown hook removed the
uploaded file even though this failure path returns early. The HTTP 200 on
a refusal is finding 2.

**1.4 — the real restore.** The zip from 1.1 uploaded back:

```
{"success":true,"message":"Success"}

$ podman exec wallos-dev find /tmp/wallos-restore -mindepth 1 | wc -l
0
```

Afterwards: a fresh login as `e2e` succeeds (302 into the session,
`settings.php` renders with `Use instance SMTP`), the migration chain still
answers `migrations/000072.php`, and the user table matches the archive's
content exactly — one user, one local admin role. That the archive held
only *one* user was this round's surprise; it is not a restore defect, it
is finding 1.

## 2. Clearing the OIDC client secret over the admin API (#124)

Through `api/admin/set_oidc_settings.php` with the admin's `api_key` read
from the database. `oauth_settings` started this round with zero rows.

| Step | Request | Answer | DB (`client_secret`) afterwards |
| --- | --- | --- | --- |
| a | `client_secret=testsecret` | `success:true` | `'testsecret'` |
| b | `client_secret=` (empty) | `success:true` | `'testsecret'` — unchanged, the placeholder rule |
| c | `clear_client_secret=1`, empty secret | `success:true` | `''` |
| d | `client_secret=neu` **and** `clear_client_secret=1` | `success:false` | unchanged (`'testsecret2'`, planted between c and d) |

The refusal in (d) names the contradiction rather than picking a side:

```
{"success":false,"title":"Database error","message":"A new client secret and
clear_client_secret were submitted together; send one or the other."}
```

The rule holds in both directions: an empty secret alone never clears
(protecting the "unchanged" convention both save paths share), and the
explicit clear never coexists with a new value. The `"Database error"`
title on a validation refusal is finding 3, cosmetic.

## 3. `fixer_usage`, and the union fetch behind it (#106, #9)

**3.1 — the endpoint.** Logged in, plain GET:

```
$ curl -b admin.jar http://localhost:8383/endpoints/settings/fixer_usage.php
{"success":true,"provider_reports":true,"shared":true,"used":null,"total":null,
 "exhausted":false,"local_calls":1,"rates_updated":null}
```

Every field is plausible for this instance: `provider_reports:true` because
the dev stack configures apilayer, `shared:true` because the key is the
instance credential, `used`/`total` null because the invalid key has never
produced a response whose quota headers could be captured, `rates_updated`
null because no refresh has ever succeeded, and `local_calls:1` — the one
wire call the e2e suite's `updateexchange` run had spent (one user existed
then; see finding 1).

**3.2 — one run, one call.** With `qa2` created (id 2; both accounts due,
both on the instance credential, both with the default 34-currency list),
one `updateexchange` run via `podman exec`:

```
For user: e2e   … rejected the API key (HTTP 401)
For user: qa2   … rejected the API key (HTTP 401)

local_calls: 1 → 2      # exactly +1 for two due users
```

A control run directly after: 2 → 3. One call per run, not per user. A
further run in exact crontab form (for section 4's log evidence) made it 4,
and the container restart before 3.3 spent the boot run's single call, 5.

**3.3 — the run that actually distinguishes #9.** The +1 in 3.2 is
necessary but not sufficient: both users carried *identical* code lists,
and the pre-#9 per-run cache already answered identical lists from the
first response. The new mechanism — fetch the union once, serve any covered
subset, covering refusals included — only shows against **differing**
lists. So `qa2` was cut down to three currencies:

```
currencies user 1: 34        currencies user 2: 3   (EUR GBP USD)

$ podman exec wallos-dev php /var/www/html/endpoints/cronjobs/updateexchange.php
For user: e2e   … (HTTP 401)
For user: qa2   … (HTTP 401)

local_calls: 5 → 6      # exactly +1
```

Under the old cache this is two cache misses — two distinct code strings —
and would have cost two calls. One call for the union, both users answered
from it (the 401 refusal covering both, as the commit argues a quota
refusal must), is the #9 behaviour, observed rather than inferred.

Also observed in passing: `WALLOS_CRON_STRICT=1` turns the failed job into
exit status 1, as the crontab asks.

## 4. Ephemeral paths (#85/#86)

After all of the above had exercised sessions, uploads, backups, restores
and cron:

```
$ podman exec wallos-dev ls /tmp/
cron  nginx  nginx.pid  wallos-restore  wallos-sessions

$ podman exec wallos-dev ls /tmp/wallos-sessions | head -3
sess_1cb60cf46b5581c0247960f8c4b6f737
sess_3bfab0f6c6f4c31d836da6fab692c4db
sess_6bf43d623ecd26fbeba2803419772883        # 9 files; /tmp itself holds none

$ podman exec wallos-dev php -i | grep -E 'session.save_path|sys_temp_dir|upload_tmp_dir'
session.save_path => /tmp/wallos-sessions
sys_temp_dir => /tmp
upload_tmp_dir => /tmp
```

* **Sessions** live in `/tmp/wallos-sessions` (0700, `www-data`), not in
  `/tmp` directly — `php-wallos.ini` names the path and the files land there.
* **Cron logs** live under `/tmp/cron/`: the every-two-minutes jobs wrote
  `sendverificationemails.log` and `sendresetpasswordemails.log` on schedule
  (the scheduler itself firing, not just my exec), and running
  `updateexchange` in exact crontab form produced
  `/tmp/cron/updateexchange.log` (245 bytes, truncated per run). A plain
  `podman exec php …` writes no log file — the redirection lives in the
  crontab line, which is worth knowing when reading an instance.
* **nginx** keeps `/var/log/nginx` empty (the directory exists with zero
  files); its pid and buffers sit under `/tmp/nginx*`, logs go to the
  container's streams.
* **The webroot's `.tmp/`** contains nothing but its tracked `.gitignore` —
  no staging data ever appears there. That the directory is still in the
  repository at all is finding 4.

## Findings

### 1. `dev/e2e.sh`'s second-account gate is vacuous on a fresh database

The suite registers `e2e2` and then checks "a second account cannot reach
the admin page". On a fresh database **that account is never created**:
`registrations_open` has defaulted to `0` since `migrations/000020.php`, so
after the first account exists, `registration.php` answers every request —
GET and POST alike — with `302 Location: login.php` before reading the
form. The suite's registration curl ends in `|| true`, the login against
the nonexistent user produces an empty page, `absent` matches, and the
check turns green having tested nothing.

Verified three ways in this round: the backup taken *after* the full e2e
run contains exactly one user; a manual `e2e2` registration answered 302
and left no row; after opening registrations through
`endpoints/admin/saveopenregistrations.php` the identical POST created the
user immediately.

The role-separation logic itself is fine — it is covered by
`tests/cases/` and was re-verified here with the real second account `qa2`,
who gets 403 semantics on admin surfaces. What is broken is the gate: it
cannot fail for the reason it exists. This also silently falsified this
round's own brief ("the dev stack has 2–3 users after the registrations" —
it had one). Suggested fix in e2e.sh: open registrations with the admin
session before registering `e2e2`, assert the row exists, close them again
— or fail loudly when registration did not create the account.

### 2. `restore.php`'s SQLite-branch refusals answer HTTP 200

"Failed to extract the uploaded file" (and the same for a zip without
`wallos.db`, and the traversal/extension refusals) arrive with status 200;
only the row-based backend branch sets 400. The UI is unaffected — it reads
the `success` flag — but this is the same class as #97, which the project
has been closing refusal by refusal: anything watching status codes is told
the restore worked. Small, mechanical fix.

### 3. `set_oidc_settings.php` labels a validation conflict "Database error"

The (d) refusal above is a request-validation outcome, but the endpoint
knows only two titles — everything not prefixed "Security Error" becomes
"Database error". Cosmetic; the message text itself is precise.

### 4. The webroot `.tmp/` directory is dead weight in the repository

Since #86 nothing writes to it — staging lives under the system temp
directory, and the only remaining reference in the tree is a history
comment in `backup.php`. The tracked `.tmp/.gitignore` still ships the
empty directory into every checkout and image. Removal candidate.

## Fixtures and cleanup

| Fixture | Created via | Removed / final state |
| --- | --- | --- |
| `e2e` account (id 1) | `dev/e2e.sh` | remains in the throwaway dev DB |
| `qa2` account (id 2) | registration, after opening registrations | remains in the throwaway dev DB |
| `registrations_open = 1` | `endpoints/admin/saveopenregistrations.php` | set back to `0`, verified |
| `oauth_settings` row, secrets `testsecret`/`testsecret2` | the section-2 API calls | secret cleared to `''` via `clear_client_secret`, verified; the row itself remains |
| `qa2`'s 31 deleted currency rows | SQL delete, for 3.3 | not restored — throwaway DB |
| `local_calls` 1 → 6 | the section-3 runs | remains; each increment accounted for above |
| backup.zip, cookie jars, page dumps | curl session | scratchpad outside the repo |

The stack was torn down with `podman compose -f dev/compose.yaml down`
(only the three `wallos-dev*` containers; the other session's containers
were never touched). `db/wallos.db` and `db/setup_token.db` remain in the
worktree as untracked dev artifacts, as they do after any local round.

## Method notes, kept honest

* The first union-fetch check (3.2) was ambiguous as designed: with
  identical currency lists, the pre-#9 cache produces the same +1. The
  differing-lists run (3.3) was added once the ambiguity was noticed — that
  one separates the mechanisms, and it is the check that should be repeated
  in future rounds.
* The brief asked for cron log files "after the updateexchange run"; a
  plain `podman exec php` run never writes one, because the redirection
  into `/tmp/cron/*.log` belongs to the crontab line, not to the job. The
  scheduled two-minute jobs proved the scheduler's own writes; the
  crontab-form invocation proved the updateexchange log path.
* Sections 2 and 3 exercise changes that shipped in 5.8.9 the same
  morning; sections 1, 3.3 and 4 exercise unreleased main (`9e6d50c`,
  `cfbe697`, `c51c311`). The instance reports `v5.8.9` from `version.php`
  while running main's code — expected for a bind-mounted worktree, noted
  so the header's "main at 4dd68e7" is read as the authority.

## Conclusions

* **The 2026-08-30 changes hold up against a real instance.** Backup
  streams and leaves nothing, restore stages outside the webroot and
  cleans up on every observed path, the OIDC clear rule is exact in all
  four quadrants, the usage endpoint reports honestly, and the union fetch
  spends one call where the old code spent one per distinct list.
* **The one defect class that keeps returning is refusals with the wrong
  status code** (finding 2, after #97 and the 2026-08-28 #103 finding) —
  worth a sweep rather than another one-at-a-time fix.
* **A gate that cannot fail is worse than no gate**, because it reports
  coverage that does not exist (finding 1). The e2e suite has carried this
  one since the check was written; this round only noticed because the
  restored backup was missing a user that was never there.
