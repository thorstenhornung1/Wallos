# What can go upstream

Established 2026-08-24 by walking `upstream/main..origin/main` and checking each
candidate against the upstream tree rather than against memory. Every defect
below was confirmed to exist upstream by reading the upstream file; anything
that could not be confirmed is marked as such.

**Check the base before opening anything.** The comparison stand is
`upstream/main`, release 5.5.0, since 2026-09-01 — see "the maintainer moved"
below. `v5_6_0` is dead: #1187 carried its content home and it has not moved
since. Line numbers written before that date still refer to `v5_6_0`, which is
why each entry names the ref it was checked against.

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
