# What can go upstream

Established 2026-08-24 by walking `upstream/main..origin/main` and checking each
candidate against the upstream tree rather than against memory. Every defect
below was confirmed to exist upstream by reading the upstream file; anything
that could not be confirmed is marked as such.

**Check the base before opening anything.** The comparison stand is
`upstream/v5_6_0`, not `upstream/main`: `main` is release 5.4.5, and `v5_6_0` is
twelve commits further on and carries the actual development. Line numbers below
refer to `v5_6_0`.

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

**One word in a SELECT.** Branch `origin/upstream-fix/totp-replay` (+12/−4),
merges cleanly into both `main` and `v5_6_0`. Best first PR: a security defect
whose fix a reviewer can verify in under a minute.

### 2. `logout.php` — the login token survives logout

`upstream/v5_6_0:logout.php:27-31` runs `DELETE FROM login_tokens WHERE token =
:token AND user_id = :userId`, and `$userId` is never assigned in that file —
it includes only `includes/connect.php` (11 lines, sets `$db` alone) and
`includes/oidc_settings.php`. The predicate is therefore `user_id = NULL` and
matches nothing. Every logout leaves a valid remember-me token behind.

Branch `origin/upstream-fix/logout-token` (+16/−3). The one discussion point is
dropping the `user_id` predicate; the argument — the token is 32 random bytes
and is itself the credential — is in the commit message.

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
  is off". Branch `origin/upstream-fix/disable-totp` (+58/−14).
* **#97a** — `includes/validate_endpoint.php` (22 lines) contains no
  `http_response_code` at all; three exit paths all answer 200. Needs adapting:
  ours uses `wallos_user_is_admin()`, upstream `$userId !== 1`.
* **#93** — `endpoints/payments/delete.php` deletes without any reference check
  and answers `success: true`. `endpoints/categories/category.php:102-113` shows
  upstream's own correct pattern. Needs rewriting, not cherry-picking: our
  version goes through the boundary. Also needs a `payment_method_in_use` key.
* **`verifyemail.php`** — the DELETE *is* the verification and its result is
  discarded; the redirect to `login.php?validated=true` happens regardless.
* **`passwordreset.php`** — DELETE then INSERT, neither checked,
  `$hasSuccessMessage = true` unconditional. If the insert fails the old token
  is already gone and the account has no way back.
* **`includes/http_status.php` + the `set_fixer.php` half of #101** — upstream
  carries the same asymmetry (`ignore_errors` on line 107, absent on 113).

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

Two upstream defects were confirmed but have no portable fix yet, because ours
depend on the boundary: `endpoints/admin/deleteuser.php:18-20` deletes the
`user` row before its 15+ dependent tables with no transaction and every result
discarded, and `endpoints/subscription/add.php:237-245` binds `$_POST` straight
into the insert with no existence or ownership check.

## The base, decided

**`v5_6_0`**, if a pull request is ever authorised — not `main`, despite `main`
being the default branch.

The evidence is what happened to the last four: opened against `main`, closed
unmerged, and merged into `v5_6_0` by hand. `main` receives release merges
(#1176 "chore(main): release 5.4.5", #1175 "V5 6 0", #1160 "V5 4 3"), so the
version branch is where development lands and `main` is where it surfaces.
Opening against `main` again would repeat a step the maintainer already had to
do manually four times.
