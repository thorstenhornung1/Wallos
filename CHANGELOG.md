# Changelog

## [5.8.8](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.8) (2026-08-28)

The first upstream merge since the fork diverged, an OIDC logout that
survives the deploy that used to break it, and a cron log that finally says
OK. Everything here was field-verified on the shared instance before this
tag existed — it ran the exact code as a pinned `main` digest.

### Changed

* **Upstream v5_6_0 is merged** — the first merge since the fork left 5.4.4,
  and the tree now records its ancestry, which makes every next merge
  smaller. Eight upstream fixes come along: logo save failures are reported
  instead of swallowed, the setup token is logged whenever it is generated,
  a restored database is migrated (taken through the boundary, with the
  runner's answer read — the raw form was the #103 anti-pattern and both
  gates refused it), the iCal feed honours currency conversion, 2FA QR codes
  survive non-ASCII usernames, the get_subscriptions all-user filter no
  longer crashes, and OIDC without a client secret counts as configured.
  The public-client change was finished rather than just merged: the
  diagnostics treat an empty secret as a public client instead of a
  permanent error, and the token exchange omits `client_secret` entirely
  instead of sending it empty. What remains is
  [#124](https://github.com/thorstenhornung1/Wallos/issues/124) — a stored
  secret cannot be cleared through the UI yet.

### Fixed

* **auth:** the OIDC logout works after the container restart that used to
  break it ([#123](https://github.com/thorstenhornung1/Wallos/issues/123)).
  A remember-me-restored session recovered `from_oidc` but not the id token,
  so its end-session request carried `post_logout_redirect_uri` with no
  `id_token_hint` — a certified provider answers that with 400. The token
  now travels with the `oidc_sessions` row (migration 000068) and is
  restored with the session; rows from before the migration degrade once to
  a bare end-session request and heal at the next login. The architecture
  was chosen against its alternative and security-reviewed before merging —
  the verdict, its conditions and the accepted residual risks are on the
  issue. Field-verified end to end: restart, restore, logout, provider
  session ended, return redirect honoured.

### Added

* **cron:** sessions at rest have a bounded lifetime — a required condition
  of the #123 security review. The new daily `cleanupsessions` job deletes
  `login_tokens` and `oidc_sessions` rows older than the 30-day remember-me
  cookie that is the only way back into them; nothing garbage-collected
  either table before, so a session that died by PHP GC left a working
  credential at rest indefinitely. The backup manifest now also names the
  login and ID tokens it has always carried.

* **cron:** a clean run that did work logs one `[Wallos cron] OK` line
  ([#122](https://github.com/thorstenhornung1/Wallos/issues/122)). Only
  failures wrote to the container log before, so the operator watching a
  deploy had to open the database to see the quota guard work. Idle runs
  stay quiet on purpose.

* **tests:** two gates that exist because of what this release survived —
  migrations after the 5.8.0 baseline are checked for SQLite-only dialect
  (the #123 migration's first draft would have broken every PostgreSQL
  upgrade), and the suite's cached template database rebuilds when the
  migration chain is newer than it (the stale one produced forty misleading
  failures).

### Documentation

* Section 7 (OIDC) of the test plan was driven end to end for the first
  time since 5.7.0 (`docs/test-results-2026-08-28-oidc.md`); 7.3 now
  expects single logout, matching the provider's invalidation flow.

## [5.8.7](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.7) (2026-08-28)

Everything in here was found on 2026-08-28, by the two QA runs that
verified 5.8.6 and by the questions they raised. The theme is channels
nobody listened on: a parse error no test parsed, a status code that fired
after its headers, a freshness check that died before running, and quota
spent telling every account the same bad news.

### Fixed

* **admin:** every admin-page button works again
  ([#119](https://github.com/thorstenhornung1/Wallos/issues/119)). One
  missing comma in `scripts/admin.js` was a parse error, a parse error
  discards the whole file, and every button whose handler lived there was
  dead from 5.8.1 through 5.8.6. No server-side test could see it — the
  endpoints behind the buttons are fine — so `dev/js-audit.sh` now parses
  every served JavaScript file, locally and as its own CI job.

* **subscriptions:** creating a subscription without a logo works on
  PostgreSQL ([#115](https://github.com/thorstenhornung1/Wallos/issues/115)).
  The INSERT named 22 placeholders and bound 19 unless a logo was uploaded;
  SQLite quietly made the missing three NULL, PostgreSQL counted and
  refused — the browser form's default request. The binds are explicit now,
  and a gate reads the INSERT out of the file and requires every placeholder
  to find a bind even when the logo branches are skipped.

* **migrations:** a failed run reaches the caller as a status
  ([#116](https://github.com/thorstenhornung1/Wallos/issues/116)). The #103
  check fired after the runner's output had already sent the headers, so
  HTTP said 200 and the CLI exited 0 on a run that stopped halfway. The
  output is buffered until the answer is known, and a failed CLI run exits 1
  — tested by running the shipped endpoint as a process against a sandboxed
  migration chain.

* **currency:** the manual refresh endpoint works at all
  ([#120](https://github.com/thorstenhornung1/Wallos/issues/120)). It
  compared a date it never read off the result — a TypeError on every
  request since the file exists, killing the only refresh path that checked
  freshness before spending quota. Upstream carries the identical defect.

* **subscriptions:** one unparseable start date no longer takes down the
  whole subscriptions page
  ([#121](https://github.com/thorstenhornung1/Wallos/issues/121)).
  Unreadable now means what missing already meant: unknown, old semantics,
  no exception.

* **i18n:** the deletion toast says "Subscription deleted successfully"
  instead of naming the missing key
  ([#118](https://github.com/thorstenhornung1/Wallos/issues/118)).

### Changed

* **currency:** a container start stops spending provider quota
  ([#117](https://github.com/thorstenhornung1/Wallos/issues/117)). The
  exchange job runs at every start and fetched unconditionally, one call
  per account — the 5.8.6 deploy spent two calls against a quota that was
  already exhausted, learning the same 429 twice. An account refreshed
  today is skipped before anything is resolved; failures are cached per
  run the way successes already were; and the client's one network touch
  now sits behind a seam, so all of it is tested without a socket — no
  test in this suite makes a request.

## [5.8.6](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.6) (2026-08-28)

Two answers that existed and reached nobody — the migration runner's and the
database configuration's — plus the notification cron finishing what #99
started, and a progress bar that ran ahead of its subscription.

### Added

* **admin:** the admin page names the database backend, its version and where
  the data lives
  ([#102](https://github.com/thorstenhornung1/Wallos/issues/102)).
  `wallos_database_configuration()` resolved the backend carefully and showed
  the result to nobody: SQLite and PostgreSQL were indistinguishable through
  the interface, which is how the test instance served SQLite for three days
  while three reports called it PostgreSQL. The page also says which values
  are built-in defaults — an unset driver reads "sqlite" too, and that is
  precisely the case worth noticing.

### Fixed

* **migrations:** a failed migration no longer reports success
  ([#103](https://github.com/thorstenhornung1/Wallos/issues/103)). The runner
  sets its failure flag for the caller to read, and no caller read it:
  `migrate.php` answered 200 regardless and `import.php` answered
  `success: true` after a restore that did not finish, both discarding the
  runner's output. This was the mechanism by which an instance could serve
  pages on a schema older than its code without anyone learning of it.

* **currency:** the provider client says which of the four failures happened
  ([#101](https://github.com/thorstenhornung1/Wallos/issues/101)). A 401, a
  429, a 503 and an unplugged network cable all arrived as `false` through
  `@file_get_contents()` without `ignore_errors`, and all produced "could not
  be reached" — correct in one case out of four. The absence of
  `$http_response_header` is the real outage; everything else now carries the
  provider's own words, which explain themselves better than a category
  assigned from outside.

* **ui:** the progress bar stays empty until a subscription has begun
  ([#114](https://github.com/thorstenhornung1/Wallos/issues/114)). The period
  start was reconstructed by walking whole cycles back from the next payment
  and never consulted the start date, so a subscription that had not started
  showed up to 96 % of a period elapsed. Upstream carries the identical
  function; recorded in `docs/upstream-candidates.md`.

### Changed

* **cron:** the notification job asks who has work before loading anyone's
  rows ([#99](https://github.com/thorstenhornung1/Wallos/issues/99)). The
  question — the notify subscriptions and the budget row, two queries however
  many accounts exist — comes first; currencies, household, categories and
  the active list are loaded for the accounts with work alone, and a day with
  nothing due costs two queries. The rules were extracted unimproved into
  `includes/notification_due.php` and pinned by tests before the loop began
  trusting them, including the account that is owed a period-start summary
  precisely because it has no payment to report.

### Documentation

* The first upstream pull request is out: the TOTP replay guard that never
  ran ([ellite/Wallos#1181](https://github.com/ellite/Wallos/pull/1181)),
  base `v5_6_0`. Four more single-file branches wait on the reaction to it.
* The price-history gap has a milestone: editing a price rewrites the past
  ([#107](https://github.com/thorstenhornung1/Wallos/issues/107), six
  sub-issues). Upstream was asked for this twice and built half of it; the
  whole account is in the parent issue.

## [5.8.5](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.5) (2026-08-21)

Four issues that had been waiting on somebody deciding what the answer should
be, and one observation the 2026-08-21 test run made by quoting 5.8.4's own
reasoning back at it.

### Added

* **db:** backup and restore work on PostgreSQL
  ([#23](https://github.com/thorstenhornung1/Wallos/issues/23)). Until 5.8.2
  both reported success and did nothing — the archive was built from `db/`,
  which on PostgreSQL holds only `setup_token.db`. 5.8.2 refused instead of
  lying; this does the thing itself.

  The archive is the rows rather than the file: a manifest, one JSON file per
  table, and the uploads. A dump belongs to the engine that produced it, and
  neither backend can read the other's; rows are what both agree on. That also
  makes it portable in the direction that matters — an installation that
  outgrows SQLite can restore into PostgreSQL.

  Three things it has to get right, each asserted rather than assumed. Rows go
  in parents-first, in an order computed from the target's own foreign keys
  rather than a list that would go stale. Every serial sequence is moved past
  the restored ids, because inserting an explicit id does not advance one and
  the first write afterwards would otherwise collide — hours later, somewhere
  unrelated. And the whole restore is one transaction: a restore that stops
  halfway leaves an installation that is neither the old one nor the new one.

  SQLite keeps the file copy: it restores faster and existing archives keep
  working. **The archive holds SMTP passwords, API keys and the OIDC client
  secret in clear text**, because restoring an installation requires them. The
  manifest says so.

### Fixed

* **security:** rows left behind by an account that no longer exists are
  removed before the next account can inherit them
  ([#92](https://github.com/thorstenhornung1/Wallos/issues/92)). `user.id` does
  not ask SQLite to keep counting, so a deleted id is handed out again — delete
  the newest account, create another, and it gets the same number along with
  whatever the old one left. Another person's subscriptions, spending history
  and household members, shown as the new account's own.

  Deletion has been complete since #81, so nothing new is being created;
  migration 000067 clears what is already there. `user_id 0` and NULL are not
  orphans — older installations carry system rows belonging to nobody — and an
  installation with no accounts is left alone entirely, because a fresh
  database seeds its defaults against user 1 before user 1 exists. That last
  one was found by running the repair against the schema generator's reference
  database: 83 rows removed from a database whose only fault was being new.
* **web server:** `db/` is denied whole rather than by extension. The rule
  protected `.db` and served anything else — a `.sql`, a `.bak`, a `.tar.gz`.
  Nothing writes such a file, which is exactly the dependency 5.8.4 refused to
  keep for `images/uploads/`. Found by the 2026-08-21 test run, which quoted
  that release's own sentence back at it.
* **ui:** a refusal says which check it came from
  ([#100](https://github.com/thorstenhornung1/Wallos/issues/100)). Sending
  `paymentId` where an endpoint reads `id` produced the same word as a row that
  does not exist, and a test run read it as the reference check failing to
  fire — two of four such confusions in one session would have become issues
  against working code. Removing the last administrator now says so as well,
  rather than answering with the word used for database failures.

### Changed

* **cron:** the notification job asks its six per-account questions once for
  everybody instead of once per account
  ([#99](https://github.com/thorstenhornung1/Wallos/issues/99)). On SQLite that
  loop was invisible — the engine runs in the same process — and on PostgreSQL
  it was the whole cost: about 2.5 ms per account over loopback and 10 ms over
  an overlay network, the same code at four times the price, because the
  per-account cost tracks the distance to the database.

  The guard is a query count rather than a timing, which is what
  [#18](https://github.com/thorstenhornung1/Wallos/issues/18) asks for: five
  accounts cost the same number of queries as one. Two measurements of the same
  code disagreed by a factor of four on milliseconds and agreed exactly on this
  number.
* **db:** the boundary answers which tables exist, so the backup archive no
  longer reads SQLite's schema table from application code (#41).

### Documentation

* **test plan:** section 8.4 covers backup and restore on PostgreSQL, including
  the three things worth checking after a restore rather than the one that is
  obvious.
* **test plan:** section 5.6 sets the payment date relative to the run. A
  fixture with a fixed date is a different test case the next day — on
  2026-08-21 it produced no output at all, which is the hardest result to read.

## [5.8.4](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.4) (2026-08-21)

What the 5.8.3 night run found, plus a night of work on the shape behind
several of these: a write whose result nobody reads, and a refusal nobody can
tell from a success.

### Security

* **web server:** PHP executed in the three directories the web server user can
  write to — `db/`, `images/uploads/` and everything under it. The 5.8.0
  ownership split made the application code unwritable and left exactly those
  writable, so they were the ones that could run what was placed in them
  ([#94](https://github.com/thorstenhornung1/Wallos/issues/94)).

  Not a live vulnerability: the application re-encodes uploads, forces the
  extension and sanitises the name, and restore rejects `.php` in an archive.
  But that invariant was the entire safety margin, in a layer with no reason to
  depend on it. The rules named directories one at a time — `logos/` was
  refused, `icons/` was not, and nothing writes to `icons/` at all, which is how
  it went unnoticed. It is a prefix now, and `security.limit_extensions` in the
  php-fpm pool is the second layer.

* **auth:** every refusal from the endpoint guards answered HTTP 200. A request
  with no session, an expired session or an invalid CSRF token was refused
  correctly and reported as successful, so anything reading status codes rather
  than parsing bodies — a proxy, a monitoring probe, `curl -f`, a rate limiter
  counting 401s — was told the request had worked
  ([#97](https://github.com/thorstenhornung1/Wallos/issues/97)).

  Now 405, 403 and 401. Ten endpoints had no guard at all, because they read
  over GET and the only guard available demanded POST and a CSRF token: with no
  cookie, `endpoints/subscriptions/get.php` ran the page-building code with no
  user and answered 200 with three PHP warnings naming absolute paths. Eight of
  them now refuse before anything can write; the two that run during setup check
  a token of their own.

### Fixed

* **db:** a migration was recorded as applied whether or not it worked, and
  whether or not the record itself was written
  ([#87](https://github.com/thorstenhornung1/Wallos/issues/87)). This is not
  hypothetical: migration 000016 drops a table while its own read of it is still
  open, SQLite refuses, the result was never checked — so it recorded itself as
  done with the table still there, on every installation ever made, until 000065
  removed it years later. A migration that is marked done is never retried.

  A migration returning false is now not recorded, a failed record is not
  treated as applied, and neither case runs the migrations that follow.
* **auth:** `verifyemail.php` sent people to a success page when the
  verification had failed. Removing the row *is* the verification, and the
  redirect ran regardless — so the next step was a login that refused them with
  nothing saying why.
* **auth:** the remember-me cookie was set whether or not the token behind it
  reached the database, and `includes/remember_me.php` restored a session
  without checking that the recorded session id had followed it. The second one
  put the defect 5.8.0 closed back within reach of one failed write: back-channel
  revocation would have deleted a session that no longer existed and left the
  restored one running for as long as the cookie lasts.
* **db:** the rates job committed new exchange rates without recording that it
  had updated them, so the next run either refetched — spending quota at a
  provider that charges per call — or the page reported rates as older than they
  are.
* **ui:** custom CSS and colour schemes were reported as saved whether or not
  they were written. Each is a delete followed by an insert, so the message now
  distinguishes "could not save" from "could not save, and the previous one is
  gone".
* **accounts:** the currencies and payment methods a new account starts with
  were written out in three files, sixteen inserts, every result discarded — so
  an account holding eleven of its thirty-four currencies was reported as
  created. One helper now, which stops at the first failed write.
* **email:** password reset redirected to the front page in silence on an
  instance with no usable transport or no `server_url`, which is what a broken
  feature looks like as well ([#96](https://github.com/thorstenhornung1/Wallos/issues/96)).
* **dev:** the benchmark left eleven orphaned notification rows behind on every
  run and reported a clean removal
  ([#98](https://github.com/thorstenhornung1/Wallos/issues/98)). Both scripts
  take the tables from the schema now, and the cleanup reports what it could
  *not* remove — "what I deleted" and "what is left" being different questions,
  and only the second one would have shown this.

### Added

* **tests:** the migration chain now runs against a PostgreSQL database that
  predates it. Every PostgreSQL test until now started from the baseline, which
  records all migrations as applied — so none of them ever ran one, and a
  migration PostgreSQL rejects would have passed everything and failed on the
  first real upgrade. The case loads the 5.8.0 baseline, applies the rest, and
  compares the result to a fresh install column by column. It runs on
  PostgreSQL 14 and 18 in CI ([#80](https://github.com/thorstenhornung1/Wallos/issues/80)).
* **dev:** `dev/write-audit.php` counts and holds the writes whose outcome
  nobody reads. 66 discarded results and 368 unchecked prepares when it was
  written; 23 and 315 now, each step held by the ratchet
  ([#87](https://github.com/thorstenhornung1/Wallos/issues/87)).
* **cron:** a failure survives the next success. `cron_runs` holds one row per
  job, so a job dying every third night showed green every morning after it
  worked. Three columns record the last failure and a count a success does not
  reset, and the admin page warns while it is recent.

### Documentation

* **test plan:** section 6 carries the first PostgreSQL load figures — two runs,
  on the test instance and on a laptop. The list is within two milliseconds of
  the SQLite numbers; the notification cron is flat on SQLite and grows by about
  10 ms per account on the instance and 2.5 ms on the laptop. Same code, same
  query count, four times the price: the per-user cost tracks the distance to
  the database, which is what an N+1 looks like when the constant is the round
  trip ([#99](https://github.com/thorstenhornung1/Wallos/issues/99)).
* **test plan:** section 8 states which PostgreSQL versions this is known to
  work on, and — more usefully — the two things that range does not cover.

## [5.8.3](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.3) (2026-08-20)

Two things the 5.8.2 test run recorded without an issue capturing them, one
defect found while building the instance it ran on, and the section that run
reported as blocked — which is not blocked: the tooling defect behind it was
fixed in 5.8.2 itself, so the load measurements can be taken on PostgreSQL for
the first time. The test plan is corrected where it cost that run time.

### Fixed

* **db:** deleting a payment method left every subscription that used it
  pointing at a row that no longer exists, and answered success. The three
  comparable endpoints — categories, currencies, household members — all count
  what still references the row and refuse; payment methods were the one
  deletion of a referenced entity that just went ahead
  ([#93](https://github.com/thorstenhornung1/Wallos/issues/93)).

  On SQLite the dangling references sit there indefinitely, because foreign keys
  are not enforced. PostgreSQL does not accept them, so
  `dev/migrate-to-pgsql.php` refuses a source that holds them: an ordinary
  interface action in March becomes a migration that cannot run in August.

  All eight delete paths — four endpoints and four REST — now share one count
  instead of carrying eight private copies of it, and the gate finds them by
  looking for the statement rather than from a list, so a ninth is covered
  without anyone remembering to add it. The shared count deliberately ignores
  who owns the referencing subscription: a stranger's row satisfies the foreign
  key just as well, and where the two counts differ the difference is a defect
  that says so in the log.
* **db:** the payment method endpoint no longer reports a delete that matched
  nothing as a deletion — an id belonging to another account, or to the system
  rows older installations carry with `user_id 0`, said "removed" while the
  method was still on the list after a reload
  ([#87](https://github.com/thorstenhornung1/Wallos/issues/87)).
* **ui:** seven admin buttons, four two-factor buttons and seven more across the
  settings page did nothing at all when clicked. Every element carrying an id is
  also exposed as a property of `window`, so a handler with the same name as an
  element's id can resolve to the element instead of the function — no request,
  no message, nothing in any log. The OIDC settings could not be saved through
  the interface at all ([#95](https://github.com/thorstenhornung1/Wallos/issues/95)).

  Eighteen elements carried the collision, not the seven the issue names. They
  were found by comparing the set of ids against the set of names inline
  handlers call, which is how the new gate finds them: the next one fails a test
  the day it is written rather than the day somebody clicks it in the right
  browser.
* **cron:** a job that failed and then succeeded again looked as though it had
  never failed. `cron_runs` holds one row per job, replaced on every run, so a
  job that dies every third night shows a green line and a fresh timestamp the
  morning after it worked.

  Migration 000066 adds the last failure, its reason and a cumulative count that
  a success does not reset — three columns rather than a history table, so the
  row count stays at one per job and nothing needs pruning. The admin page warns
  while the failure is recent and goes quiet afterwards, where "recent" is three
  of the job's own staleness windows: two hours ago is many runs back for a job
  that runs every two minutes and last night for one that runs daily.

### Added

* **dev:** `dev/write-audit.php`, a ratchet on the writes whose outcome nobody
  reads ([#87](https://github.com/thorstenhornung1/Wallos/issues/87)). It does
  not fix them — it counts them and holds the number, the way
  `dev/db-audit.sh` holds the SQLite boundary, so the next one is a failing
  test rather than the fifth coat of the same defect.

  The answer, on this tree: **66 discarded results and 368 unchecked prepares
  across 131 files**, of which 304 carry a statement that changes data or one
  the audit cannot read. That number is what the open design question in #87 —
  whether the boundary should offer a write returning rows-affected-or-null —
  should be decided against, rather than an estimate.

  It parses instead of searching: a text search counts 311 discarded results
  where there are 66, because it cannot tell `$stmt->execute();` from
  `$ok = $stmt->execute();` or either from a docblock. What it does not see —
  `changes()` used as a success signal, multi-statement work with no
  transaction, hardcoded success responses — is written into its header, so a
  file at zero reads as "free of two countable shapes" rather than as cleared.

### Changed

* **dev:** `dev/compose.yaml` pointed at `dev/pgsql.sh` for switching a
  container to PostgreSQL. There is no such script; the instructions are in
  `dev/README.md`.

### Documentation

* **test plan:** section 6 no longer reads as blocked. `dev/benchmark.sh` seeded
  one database and measured another on PostgreSQL until 5.8.2
  ([#91](https://github.com/thorstenhornung1/Wallos/issues/91)); that is fixed,
  and the section now says that the figures in it are from SQLite and that
  PostgreSQL has none yet.
* **test plan:** the password reset test has a section of its own, in section 5
  where it belongs rather than inside the OIDC chapter, with the precondition
  that made the whole feature look broken — `passwordreset.php` redirects away
  silently unless `admin.server_url` is set, and on a fresh instance it is not.
* **test plan:** back-channel logout says under what condition Authentik sends
  the notification at all. It builds it from the session's live access tokens,
  which last minutes where sessions last days, so "end session" in the admin
  interface reports success and sends nothing for most of a session's life.
* **test plan:** section 7.2 listed two fixed defects as current limitations. A
  section that does that is itself a finding — a reader takes it as a reason not
  to test — so both are named as fixed instead of quietly deleted.
* **test report:** the 2026-08-20 run closes on section 6 being blocked by
  [#91](https://github.com/thorstenhornung1/Wallos/issues/91). That issue was
  closed the evening before the run and fixed in the release the run tested. The
  correction is appended rather than edited in, because the error was procedural
  — the issue's state was taken from the plan instead of being looked up — and
  that is the part worth keeping.

## [5.8.2](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.2) (2026-08-19)

Corrects 5.8.1, whose test suite fails: the migration fix in that release changes
the schema the migration chain produces, and the checked-in PostgreSQL baseline
was not regenerated with it.

### Fixed

* **db:** the PostgreSQL baseline still declared the `notifications` table that
  migration 000016 now succeeds in dropping, so a fresh PostgreSQL installation
  was created with a table nothing uses
* **dev:** `dev/generate-pgsql-schema.php` could not be run. It has been broken
  since `543f25e` added two requires to `createdatabase.php` that its sandbox
  does not provide — while the test case that tells you to run it builds the
  same sandbox through `tests/bootstrap.php`, where those files are symlinked,
  and so kept passing. The instructions for fixing a stale baseline were
  themselves the thing that did not work

## [5.8.1](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.1) (2026-08-19)

Failures that reported success. A security audit of the 5.8.0 fixes found the
same shape in four more places — including one that needed no failure at all to
exploit — and instrumenting the scheduled jobs found eleven more.

### Security

* **auth:** the TOTP replay guard was dead code. `totp.php` compared a submitted
  code's time-step against `last_totp_used`, a column its `SELECT` never asked
  for, so the value was always null and the comparison always ran against 0.
  Since a time-step is a number in the tens of millions, no code was ever
  rejected as reused: a code observed once — shoulder-surfed, relayed, captured
  by a proxy — could be replayed for as long as it was accepted at all, which
  with the ±15 s leeway is 60 seconds rather than the 30 of its own step.
  RFC 6238 section 5.2 requires that a code be accepted only once. This needed
  no failure of any kind to exploit, and nothing in the code, the comments or
  the logs suggested the feature was not working
* **auth:** a backup code whose removal failed was still accepted, which turns a
  single-use code into a permanent one. It now counts only once struck off
* **auth:** `disable_totp.php` ran two unchecked writes and reported success
  regardless, in two identical copies. If only one landed, the account was left
  with `totp_enabled` set and no enrolment row — a state no credential can
  satisfy, so every login attempt failed no matter what the user typed, having
  just been told 2FA was switched off. Both writes are now one transaction
* **oidc:** back-channel logout counted sessions whose remember-me token
  survived. The session row could be deleted perfectly while its token lived on,
  and the count returned to the identity provider included that session — which
  the provider does not retry. The browser holding the cookie is signed back in,
  into a session with no `oidc_sessions` row, permanently out of reach of any
  future back-channel logout
* **auth:** `logout.php` discarded the result of revoking the remember-me token,
  so a failed revocation left the cookie working while the session was
  destroyed. The user believes they have logged out; the next request signs them
  back in
* **auth:** the password reset request deleted any outstanding token, inserted a
  new one, checked neither, and displayed "check your email" regardless. A
  failed insert left the account with no way to reset at all, and retrying
  reproduced it exactly. Issuing is now one transaction, so a failure leaves the
  previous token working and says so — while an unregistered address still gets
  the same response as a registered one, so the page cannot be used to find out
  which addresses have accounts
* **oidc:** the admin role sync reported `revoked` for a revocation that had not
  happened — the case where a provider has just withdrawn someone's
  administrator group

### Fixed

* **cron:** every scheduled job could fail silently. They exited 0 whatever
  happened, wrote into per-job files nobody reads, and left no record the admin
  page could show. Jobs now report failure through an exit status, the
  container's own stderr, and a `cron_runs` row; a job that stops without
  finishing is a failure rather than a quiet night. Instrumenting them surfaced
  eleven failures nobody would have noticed, among them `cleanupresettokens`
  printing "no expired tokens" precisely when the DELETE had failed,
  `updateexchange` reporting a refused API key as "skipped",
  `generaterecommendations` ending with `"success" => true` hardcoded, and
  `sendnotifications` reporting every successful Mattermost delivery as an error
* **cron:** a `TypeError` in `sendcancellationnotifications` on empty ntfy
  headers, and an escaping PHPMailer exception in both notification jobs, each
  ended the whole run at the first affected recipient
* **startup:** a container whose database directory is not writable now refuses
  to start and says which directory and why. Before, SQLite reported "attempt to
  write a readonly database", every page still rendered, and the instance looked
  healthy while every subscription, setting and rate refresh was silently
  discarded. The message distinguishes wrong ownership from a read-only mount,
  because they need opposite fixes
* **startup:** dropped the recursive `chown` of `/tmp`. It was pointless —
  `/tmp` is 1777 — and handed every file any other process left there to the web
  server user
* **db:** file backup, restore and import now refuse on PostgreSQL instead of
  reporting success. `backup.php` produced an archive holding nothing the running
  instance uses; on an instance migrated from SQLite the stale file is still on
  disk, so the archive looked plausible and was months out of date. `import.php`
  additionally consumed the setup token, leaving setup unfinishable

### Internal

* the database-boundary Semgrep rules can now be switched on: they produced 1119
  findings of which 1118 were not violations, and two of them were broken in
  ways that read as clean — a `\b` that let `PRAGMA` match only one-letter words,
  and a rule that timed out on the largest file in the repo and so contributed
  no findings at all
* snapshot and benchmark tooling for repeating migration runs against real data

## [5.8.0](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.8.0) (2026-08-19)

PostgreSQL as an optional backend, and two security fixes found by auditing code
that already had tests.

### Security

* **oidc:** back-channel logout reached one of 113 entry points. The revocation
  check lived only in `checksession.php`, the path that renders pages; all 112
  files bootstrapping through `connect_endpoint.php` never asked whether the
  session was still valid. After an identity provider ended a session the browser
  kept full API access — user administration and database backup included — until
  the PHP session expired, up to thirty days later
* **oidc:** a session rebuilt from the remember-me cookie lost its OIDC origin and
  was permanently exempt from revocation afterwards. Since PHP sessions are
  collected after about 24 minutes and the cookie lives 30 days, this is the path
  most long-lived sessions actually take
* **oidc:** the client secret reached the admin API and the page HTML. A secret
  supplied through `OIDC_CLIENT_SECRET_FILE` — deliberately kept out of the
  database — was returned verbatim in JSON and rendered as an editable text field.
  The API now reports whether a secret is set, never its value
* **oidc:** back-channel logout now revokes the provider-derived admin role along
  with the session, so a de-privileged administrator stops administering rather
  than keeping the role until their next login
* **auth:** both user-deletion flows deleted the account row before its children,
  in no transaction, without checking a single result. On PostgreSQL that fails
  and both endpoints reported success anyway, leaving a half-dismantled account.
  Twelve tables were missing from both flows, two more from the self-service one —
  including `login_tokens`, so a self-deleted account left a working remember-me
  token behind ([#81](https://github.com/thorstenhornung1/Wallos/issues/81))
* **subscriptions:** six foreign-key ids went from the form into the database
  unvalidated, so a subscription could reference another account's category,
  payment method or household member ([#82](https://github.com/thorstenhornung1/Wallos/issues/82))

### Features

* **database:** PostgreSQL is selectable with `WALLOS_DB_DRIVER=pgsql` plus host,
  port, name, user, password and sslmode. SQLite stays the default and an
  installation that sets nothing keeps exactly what it has
  ([#20](https://github.com/thorstenhornung1/Wallos/issues/20),
  [#21](https://github.com/thorstenhornung1/Wallos/issues/21))
* **database:** `dev/migrate-to-pgsql.php` moves an existing SQLite installation
  across, verified by comparing a content fingerprint of every table before and
  after, and by inserting into every sequence-backed table afterwards
  ([#79](https://github.com/thorstenhornung1/Wallos/issues/79))
* **dev:** a stress instance covering all 42 tables, a fingerprint tool, a load
  script and a repeatable benchmark

### Bug Fixes

* **stats:** the category list was ordered by a string constant rather than by the
  `order` column, on both backends. It has never sorted; now it does
* **api:** the subscriptions endpoint refused cycle 5, "One-time", which migration
  000046 added and the interface has offered ever since — the allowlist was
  hardcoded and had drifted from the table
* **registration:** an unknown currency code was silently accepted as the first
  currency in the list, because a failed `array_search` returns `false` and `false`
  as an array index is `0`
* **oidc:** four defects in the PostgreSQL layer, each of which reported success
  while doing nothing: a commit on a transaction PostgreSQL had already discarded,
  a bind failure escaping as a fatal, a boolean coercion running backwards, and a
  change counter that survived a failed statement — which the session and role
  revocation functions read as "rows removed"

### Upgrade notes

Nothing to do. SQLite installations are unaffected; the migration chain runs as
before.

To try PostgreSQL, set `WALLOS_DB_DRIVER=pgsql` and the connection variables on
an empty database — the schema installs itself. To move existing data, run
`dev/migrate-to-pgsql.php`, which refuses by default if the source contains rows
that violate a foreign key PostgreSQL enforces and SQLite never did.

Tested against PostgreSQL 14 and 18 in CI, both running the full test suite.

## [5.7.2](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.7.2) (2026-08-17)

No application code changes. Both fixes come from the 2026-08-16/17 test run on
Docker Swarm.

### Bug Fixes

* **dev:** `dev/benchmark.sh` measured nothing in its cron column. It timed with
  `date +%s%N`, and BusyBox — what the Alpine-based image ships — ignores `%N` and
  returns whole seconds, so two readings inside the same second differ by zero.
  Every cron figure came out as `0 ms`, baseline included, which reads like "too
  fast to measure" when it means "not measured". Timing now happens inside a PHP
  process with `microtime()`
* **docs:** the Swarm stack pinned the service with `node.labels.app == true`
  while the document claimed it was constrained to one node. A label can be on
  several nodes and Swarm will eventually use that freedom — during the test run
  the task moved, found an empty node-local volume, migrated it, and came up as a
  fresh installation. It looks exactly like total data loss. The constraint is a
  hostname now, and the failure mode is described where the volumes are

### Documentation

* test 5.2 has a separate administrator block for `admin.php`, which returns a
  redirect to a non-administrator — and `grep -c` on a redirect returns `0` for the
  same reason an empty page does, proving nothing. The block asserts the page
  rendered before trusting the count
* an authentik trap: logging out of authentik before logging out of Wallos hits a
  hard-coded `Logout successful` page in `end_session.py`, which also suppresses
  the back-channel token. Nothing to fix in Wallos; documented because it is silent
  and every parameter looks correct while it happens
* the image no longer ships a `VERSION` file; the document says how to read the
  running image tag instead

## [5.7.1](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.7.1) (2026-08-17)

### Bug Fixes

* **oidc:** discovery now runs from an issuer stored in the admin interface, not
  only from the `OIDC_ISSUER` environment variable. Without a discovery document
  there is no JWKS, so back-channel logout refused every token, and no
  `end_session_endpoint`, so RP-initiated logout fell back to whatever had been
  pasted into the logout URL — with nothing in the interface suggesting a setting
  was missing ([#78](https://github.com/thorstenhornung1/Wallos/issues/78))

### Performance

* **oidc:** the discovery document is cached for an hour. It is fetched inside the
  configuration resolution, which `login.php` runs on every render, so before this
  every visit to the login page waited on an HTTP request to the identity provider
  — up to the ten second timeout when the provider was unwell. A failed refresh
  falls back to the cached copy however old it is

## [5.7.0](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.7.0) (2026-08-17)

### Features

* **auth:** the administrator role is a stored role rather than `user.id == 1`.
  Multiple administrators are possible, the role can be granted and revoked, and
  it no longer goes to whichever account happened to be created first — which,
  with OIDC auto-provisioning, could be whoever authenticated first
  ([#46](https://github.com/thorstenhornung1/Wallos/issues/46))
* **oidc:** an admin claim from the identity provider can grant the role,
  configured in the admin interface or through `OIDC_ADMIN_CLAIM` /
  `OIDC_ADMIN_VALUE`. Provider-neutral — the operator names the claim. Re-read on
  every login, so removing the group at the provider removes the role at the next
  sign in. Locally granted admin rights are never touched by it
  ([#47](https://github.com/thorstenhornung1/Wallos/issues/47))
* **oidc:** logout follows RP-initiated logout semantics — `id_token_hint`,
  a dedicated `post_logout_redirect_uri`, and `state`. The end-session URL comes
  from discovery when it is not configured explicitly
  ([#48](https://github.com/thorstenhornung1/Wallos/issues/48))
* **oidc:** back-channel logout at `/backchannel-logout.php`, so the provider can
  end a Wallos session without the browser. Signed `logout_token` validation with
  no new dependency. Revocation reaches a running PHP session, not just the
  remember-me token, and never deletes an account or its data
  ([#49](https://github.com/thorstenhornung1/Wallos/issues/49))
* **dev:** `dev/benchmark.sh`, a repeatable performance measurement over
  subscription lists of 100/1000/5000 entries and notification crons at
  1/10/100 users

### Bug Fixes

* **auth:** logging out now actually deletes the login token. The statement
  matched on a variable that was never assigned, so `user_id = NULL` matched
  nothing and every logout left a usable remember-me token behind — the user
  appeared signed out while the credential that signs them back in survived
  ([#45](https://github.com/thorstenhornung1/Wallos/issues/45))
* **oidc:** both save paths for OIDC settings share one writer. The admin
  interface trimmed text fields and the API did not, so a client id pasted
  through the API with a trailing space was stored with it and every handshake
  afterwards failed as "invalid client"

### Upgrade notes

The account with id 1 keeps administrator rights. If that account was deleted at
some point, the oldest surviving account is given the role instead — otherwise
the admin area would be unreachable, since ids are never reused.

To make a different account an administrator:

```sh
sqlite3 /path/to/db/wallos.db \
  "INSERT OR IGNORE INTO user_roles (user_id, role, source)
   SELECT id, 'admin', 'local' FROM user WHERE username = 'yourname';"
```

## [5.6.3](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.6.3) (2026-08-16)

### Bug Fixes

* **oidc:** every login failure now shows what actually went wrong. The five
  error codes introduced in 5.6.2 all rendered as a single "login failed" — the
  distinction existed only in the server log. A user whose address the provider
  reports as unverified is told exactly that, and what to do about it
* **oidc:** the configuration check abbreviates the client id. It is not a
  secret, but this page exists to be pasted into bug reports and a
  forty-character random string reads like a credential in a screenshot
* **oidc:** required email verification no longer warns once accounts have
  demonstrably been provisioned through the provider — it evidently reports
  verified addresses, and warning about a rejection that is not happening is
  noise. A fresh installation still gets the warning

## [5.6.2](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.6.2) (2026-08-16)

### Features

* **oidc:** a configuration check in the admin area reports what Wallos already
  knows before anyone attempts a login — an unreadable secret file, a failed
  discovery with the provider's error, missing endpoints, a relative redirect
  URL, and that required email verification will reject providers whose default
  scope mapping reports `email_verified: false` ([#43](https://github.com/thorstenhornung1/Wallos/issues/43))

### Bug Fixes

* **oidc:** `oidc_invalid_state` covered three problems with three different
  fixes; a malformed response, an expired session and a genuine state mismatch
  are now distinct
* **oidc:** a failed token exchange no longer dies with a bare string and
  discards the provider's explanation. The response body — `invalid_client`,
  `invalid_grant`, a redirect_uri mismatch — is logged, as are the claims a
  userinfo response actually returned when the identifier field is missing

No secret reaches the diagnostics or the log: the client secret is reported as
a state, and authorization codes and tokens never appear.

## [5.6.1](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.6.1) (2026-08-16)

### Features

* **i18n:** default categories are seeded in the language of the account being
  created, rather than always in English. The list is no longer duplicated
  across registration, the admin form and OIDC provisioning ([#38](https://github.com/thorstenhornung1/Wallos/issues/38))

German is translated; other languages fall back to English per key, which is
what they showed before. Adding a language is sixteen entries in one file.

Existing accounts are untouched: seeded categories are user-owned data from the
moment they are created, and changing a language never renames them.

## [5.6.0](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.6.0) (2026-08-16)

### Features

* **i18n:** language identifiers are canonical BCP-47 tags — `pt-BR`,
  `sr-Latn`, `zh-CN`, `zh-TW` — resolved through one function that still
  accepts the legacy spellings, so existing cookies and stored preferences keep
  working while only canonical values are written ([#33](https://github.com/thorstenhornung1/Wallos/issues/33))
* **i18n:** `WALLOS_DEFAULT_LANGUAGE`, or an admin field, decides the language
  of accounts created without a language choice. A value Wallos has no
  translation for is reported rather than silently becoming English ([#34](https://github.com/thorstenhornung1/Wallos/issues/34))
* **oidc:** a newly provisioned account takes its language from the provider's
  `locale` claim, falling back to the instance default. The claim is read once,
  at creation; afterwards the language belongs to the Wallos user ([#35](https://github.com/thorstenhornung1/Wallos/issues/35))

### Upgrading

Migration `000057` rewrites stored language values to their canonical form.
Downgrading afterwards is possible but not clean: an older version does not
know `pt-BR` and would fall back to English for accounts migrated from `pt_br`.

## [5.5.2](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.5.2) (2026-08-16)

### Bug Fixes

* **oidc:** an authorization response arriving at `login.php` is consumed
  instead of discarded. Only the document root handled callbacks, so an
  identity provider configured with the login page as its redirect URI — the
  obvious choice — authorised successfully and the user was returned to the
  login form with no account created and no error anywhere ([#42](https://github.com/thorstenhornung1/Wallos/issues/42))

## [5.5.1](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.5.1) (2026-08-16)

### Bug Fixes

* **notifications:** a successful test mail now says when scheduled
  notifications will still not be sent. The test button resolves the transport
  directly and delivers even when the user never enabled or saved
  notifications, at which point the scheduled job sends nothing — found during
  the Swarm test run documented in `docs/test-results-2026-08-16.md`

### Performance

* **config:** effective configuration is resolved once per request instead of
  on every call; the settings and admin pages together drop from 48 queries to
  11, and each secret file is read once
* **notifications:** provider settings load once per table instead of once per
  user per provider, users with nothing enabled are skipped before any
  expensive work, and seventeen redundant household lookups are gone — 110
  queries become 12 with 11 users

### Documentation

* a copy-paste test instance for Docker Swarm and Kubernetes, with Mailpit as
  the mail sink and a test plan that checks properties rather than uptime
  (`docs/test-instance.md`)
* the results of running that plan against a live Swarm cluster
  (`docs/test-results-2026-08-16.md`)

## [5.5.0](https://github.com/thorstenhornung1/Wallos/releases/tag/v5.5.0) (2026-08-16)

First release of this fork. Based on upstream 5.4.4, with instance-wide
configuration and the low-risk correctness and performance fixes that came out
of the same work.

### Features

* **config:** shared configuration and secret resolution layer, with `WALLOS_*`
  environment variables and `*_FILE` secrets for Docker, Kubernetes and Podman
* **smtp:** one instance SMTP transport serving password resets, verification
  mail, renewal and cancellation notifications, and the test button, with an
  explicit per-user instance/custom choice
* **currency:** instance-wide exchange rate provider that users inherit by
  default, with quota reported where the credential lives
* **ai:** instance-wide AI provider; users keep their own enable flag, schedule
  and optional model override without ever seeing the shared key
* **admin:** Instance Integrations section, environment-managed fields shown
  read-only with the variable that owns them, and secrets reported as status
  rather than rendered into the page

### Bug Fixes

* **currency:** exchange rate updates are scoped to the user being refreshed;
  previously a scheduled refresh overwrote every other user's rates with a
  conversion base that was not theirs

### Performance

* **currency:** prices convert from a rate map loaded once per request instead
  of one query per subscription — 200 queries and 41ms become 1 query and 0.3ms
  on a 200-subscription list
* **currency:** one user's rate refresh is committed atomically and reuses one
  prepared statement
* **subscriptions:** two measured indexes; the subscription list drops from
  26.2ms to 1.8ms on 10 users and 10,000 subscriptions

### Development

* container-based test suite (`dev/test.sh`) and development environment
  (`dev/up.sh`, `dev/e2e.sh`) with Mailpit, needing no local PHP

## [5.4.4](https://github.com/ellite/Wallos/compare/v5.4.3...v5.4.4) (2026-08-15)


### Bug Fixes

* bump version ([#1162](https://github.com/ellite/Wallos/issues/1162)) ([1efd340](https://github.com/ellite/Wallos/commit/1efd340c92ff713e1ec71682065846bd210acb6a))

## [5.4.3](https://github.com/ellite/Wallos/compare/v5.4.2...v5.4.3) (2026-08-15)


### Bug Fixes

* **security:** block IPv6 transition addresses in SSRF guard ([44e3b62](https://github.com/ellite/Wallos/commit/44e3b62a2e4ae01fb3f3bf3ec8db35ce5b8783df))
* **security:** rate limit totp verification ([44e3b62](https://github.com/ellite/Wallos/commit/44e3b62a2e4ae01fb3f3bf3ec8db35ce5b8783df))
* **security:** stream database backups instead of writing them to the web root ([44e3b62](https://github.com/ellite/Wallos/commit/44e3b62a2e4ae01fb3f3bf3ec8db35ce5b8783df))
* **security:** unsafe zip extraction during db restore ([44e3b62](https://github.com/ellite/Wallos/commit/44e3b62a2e4ae01fb3f3bf3ec8db35ce5b8783df))

## [5.4.2](https://github.com/ellite/Wallos/compare/v5.4.1...v5.4.2) (2026-07-19)


### Bug Fixes

* use themed version of the logo on edit subscription page ([#1133](https://github.com/ellite/Wallos/issues/1133)) ([e913511](https://github.com/ellite/Wallos/commit/e9135115d0e238f78c5561ecb00c9e36acb63dd6))

## [5.4.1](https://github.com/ellite/Wallos/compare/v5.4.0...v5.4.1) (2026-07-18)


### Bug Fixes

* bump version ([#1131](https://github.com/ellite/Wallos/issues/1131)) ([18dd08b](https://github.com/ellite/Wallos/commit/18dd08bd80a85b4f21fc30b25f04172af07f2e13))

## [5.4.0](https://github.com/ellite/Wallos/compare/v5.3.0...v5.4.0) (2026-07-18)


### Features

* add Arabic localization ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* add manual logo search box and png prioritization ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* add OIDC_REQUIRE_EMAIL_VERIFIED environment variable and SSRF_ALLOWLIST environment variable ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))


### Bug Fixes

* ai recommendations not handling varied provider responses ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* deleting orphaned logos not taking into account themed variants ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* email notification test rejecting non-admin users ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* notification test/send requests hanging on unreachable hosts ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* pin discord notification action to a commit sha ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* progress bar showing 100% when next payment is more than one cycle away ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* service worker caching stale logo search results and broken images as logos ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))
* stats page not using themed logo variants ([aff3ed0](https://github.com/ellite/Wallos/commit/aff3ed06b154a6b9bca6d0777f5935b5f9e8dd59))

## [5.3.0](https://github.com/ellite/Wallos/compare/v5.2.0...v5.3.0) (2026-07-18)


### Features

* add payment-period budgeting ([4b8fbe5](https://github.com/ellite/Wallos/commit/4b8fbe578c8a27ba668db5feaeb005f5718519f4))

## [5.2.0](https://github.com/ellite/Wallos/compare/v5.1.1...v5.2.0) (2026-07-14)


### Features

* add new logo themed versions generation to add subscription api endpoint ([921fcfd](https://github.com/ellite/Wallos/commit/921fcfd1598d5efcaca584f2ad4df652735399f3))
* better navigation inside logo search ([921fcfd](https://github.com/ellite/Wallos/commit/921fcfd1598d5efcaca584f2ad4df652735399f3))
* bottom sheet slide up animation ([921fcfd](https://github.com/ellite/Wallos/commit/921fcfd1598d5efcaca584f2ad4df652735399f3))
* improve menu navigation on grid mode ([921fcfd](https://github.com/ellite/Wallos/commit/921fcfd1598d5efcaca584f2ad4df652735399f3))


### Bug Fixes

* syntax error on nl.js translation file ([921fcfd](https://github.com/ellite/Wallos/commit/921fcfd1598d5efcaca584f2ad4df652735399f3))

## [5.1.1](https://github.com/ellite/Wallos/compare/v5.1.0...v5.1.1) (2026-07-12)


### Bug Fixes

* bump version ([#1113](https://github.com/ellite/Wallos/issues/1113)) ([d3b72d3](https://github.com/ellite/Wallos/commit/d3b72d33cd111d9aa2fa619be0098f8423f54eeb))

## [5.1.0](https://github.com/ellite/Wallos/compare/v5.0.0...v5.1.0) (2026-07-12)


### Features

* create dark and light theme versions of the logos when removing background ([8d22f04](https://github.com/ellite/Wallos/commit/8d22f0435372c8874fcc2f42733230cdb2674167))


### Bug Fixes

* ajax calls after session expired ([8d22f04](https://github.com/ellite/Wallos/commit/8d22f0435372c8874fcc2f42733230cdb2674167))

## [5.0.0](https://github.com/ellite/Wallos/compare/v4.9.6...v5.0.0) (2026-07-11)


### ⚠ BREAKING CHANGES

* complete ui overhaul ([#1108](https://github.com/ellite/Wallos/issues/1108))

### Features

* Allow setting beginning of week as Sunday in calendar ([#1010](https://github.com/ellite/Wallos/issues/1010)) ([f01685e](https://github.com/ellite/Wallos/commit/f01685e0eb36690e3ecdcf2f029cae29764e3389))
* complete ui overhaul ([#1108](https://github.com/ellite/Wallos/issues/1108)) ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* dashboard icons image search ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* declarative oidc settings ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* google image search with serpapi ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* grid view for subscriptions ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* more statistics ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* option for the week to start on sunday ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* redesign login / registration pages ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* selfh.st image search ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* subscription details popup ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* translate categories with ai ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* v2.0 api - write endpoints ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))


### Bug Fixes

* calendar occurrences to respect subscription start date ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* escape iCal property values to prevent crlf injection ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* honor configured outbound proxy for logo search without reopening httpoxy SSRF bypass ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* improve background removal feature for logos ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* include todays subscriptions on amount due this month ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* remove hardcode string from the admin page ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* require cron auth guard on storetotalyearlycost.php ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* ssrf via http proxy env var in payments logo search ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))
* validate per-user smtp host against ssrf ([11eaf40](https://github.com/ellite/Wallos/commit/11eaf402e841a628c68a805694227ce66c45f6f3))

## [4.9.6](https://github.com/ellite/Wallos/compare/v4.9.5...v4.9.6) (2026-06-22)


### Bug Fixes

* account takeover via email-based account linking ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))
* harden oidc state validation and session rotation ([#1071](https://github.com/ellite/Wallos/issues/1071)) ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))
* missing fields when cloning a subscription ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))
* ssrf via oidc token/userInfo url configuration ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))
* ssrf via test email notification ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))
* zip slip path traversal in database restore writes files to webroot ([b75f13d](https://github.com/ellite/Wallos/commit/b75f13d0ffa3ed7e77e8e79e4b9fd3fc528c98d3))

## [4.9.5](https://github.com/ellite/Wallos/compare/v4.9.4...v4.9.5) (2026-06-06)


### Bug Fixes

* container startup ([#1077](https://github.com/ellite/Wallos/issues/1077)) ([b33d2cb](https://github.com/ellite/Wallos/commit/b33d2cb29350fd512af337e8831e87510edd680b))

## [4.9.4](https://github.com/ellite/Wallos/compare/v4.9.3...v4.9.4) (2026-06-06)


### Bug Fixes

* restrict migrate.php to CLI and admin session ([85bba48](https://github.com/ellite/Wallos/commit/85bba489f20e00af7dd42593804092a1d33286bb))
* secure unauthenticated db restore endpoint with a setup token ([85bba48](https://github.com/ellite/Wallos/commit/85bba489f20e00af7dd42593804092a1d33286bb))
* validate oidc state parameter to prevent csrf login attack  ([85bba48](https://github.com/ellite/Wallos/commit/85bba489f20e00af7dd42593804092a1d33286bb))

## [4.9.3](https://github.com/ellite/Wallos/compare/v4.9.2...v4.9.3) (2026-05-27)


### Bug Fixes

* bump version ([#1069](https://github.com/ellite/Wallos/issues/1069)) ([fb96250](https://github.com/ellite/Wallos/commit/fb96250429f7b420b0f388503d6aecf73daca6d4))

## [4.9.2](https://github.com/ellite/Wallos/compare/v4.9.1...v4.9.2) (2026-05-27)


### Bug Fixes

* build ([#1067](https://github.com/ellite/Wallos/issues/1067)) ([ee62138](https://github.com/ellite/Wallos/commit/ee621382e3722949640b53378b0808b5b4a8f768))

## [4.9.1](https://github.com/ellite/Wallos/compare/v4.9.0...v4.9.1) (2026-05-27)


### Bug Fixes

* cross-user data isolation issues ([e276147](https://github.com/ellite/Wallos/commit/e276147cab1fd6b1e8e200d94f9200caa9a0376f))
* ensure a user always has an api key generated ([e276147](https://github.com/ellite/Wallos/commit/e276147cab1fd6b1e8e200d94f9200caa9a0376f))
* null pointer on subscription with price 0 ([e276147](https://github.com/ellite/Wallos/commit/e276147cab1fd6b1e8e200d94f9200caa9a0376f))

## [4.9.0](https://github.com/ellite/Wallos/compare/v4.8.4...v4.9.0) (2026-05-16)


### Features

* allow multiple filters on the settings page ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* filter by notification status ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* lifetime subscriptions ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* rework icons ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* sort graphs on the statistics page by usage ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))


### Bug Fixes

* don't use mbstring ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* migrations using double quotes ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* ntfy notifications with strange chars ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* null array on empty subscription list ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))
* open 3 dot menu abone for the subscriptions at the bottom ([0fef959](https://github.com/ellite/Wallos/commit/0fef9597ef9eadce725128e454cbd60ec051391d))

## [4.8.4](https://github.com/ellite/Wallos/compare/v4.8.3...v4.8.4) (2026-04-27)


### Bug Fixes

* improve date formatting with IntlDateFormatter fallback (b2c565f) ([#1048](https://github.com/ellite/Wallos/issues/1048)) ([8d43623](https://github.com/ellite/Wallos/commit/8d43623da9c27d32c30a219fec84a4724f62c38b))
* missing year for subscription next payment display (ca5823d) ([8d43623](https://github.com/ellite/Wallos/commit/8d43623da9c27d32c30a219fec84a4724f62c38b))

## [4.8.3](https://github.com/ellite/Wallos/compare/v4.8.2...v4.8.3) (2026-04-26)


### Bug Fixes

* cases on private endpoints where self-xss was possible ([#1045](https://github.com/ellite/Wallos/issues/1045)) ([d4725f3](https://github.com/ellite/Wallos/commit/d4725f36bd967e7dbd622982cdfccbf8567673e2))

## [4.8.2](https://github.com/ellite/Wallos/compare/v4.8.1...v4.8.2) (2026-04-18)


### Bug Fixes

* logo cut on registration page ([#1040](https://github.com/ellite/Wallos/issues/1040)) ([a95aaad](https://github.com/ellite/Wallos/commit/a95aaadbcc1b32cf9e995bf0b1afecce524b4036))

## [4.8.1](https://github.com/ellite/Wallos/compare/v4.8.0...v4.8.1) (2026-04-18)


### Bug Fixes

* dns rebinding vulnerability ([e79f28b](https://github.com/ellite/Wallos/commit/e79f28be6be0435fbc93563fb3c0e62206b48e85))
* only allow to use internal urls csrf validation bypass by admin user ([e79f28b](https://github.com/ellite/Wallos/commit/e79f28be6be0435fbc93563fb3c0e62206b48e85))
* ssrf vultenaribility on add subscription ([#1038](https://github.com/ellite/Wallos/issues/1038)) ([e79f28b](https://github.com/ellite/Wallos/commit/e79f28be6be0435fbc93563fb3c0e62206b48e85))

## [4.8.0](https://github.com/ellite/Wallos/compare/v4.7.3...v4.8.0) (2026-03-23)


### Features

* add openai compatible host for ai recommendations ([99c30e7](https://github.com/ellite/Wallos/commit/99c30e70c8018697ea36babe5e063b3693956600))
* enable ai recommendations at a schedule ([99c30e7](https://github.com/ellite/Wallos/commit/99c30e70c8018697ea36babe5e063b3693956600))
* move update banner to the dashboard ([99c30e7](https://github.com/ellite/Wallos/commit/99c30e70c8018697ea36babe5e063b3693956600))


### Bug Fixes

* handle some ai responses that come in a different format ([99c30e7](https://github.com/ellite/Wallos/commit/99c30e70c8018697ea36babe5e063b3693956600))

## [4.7.3](https://github.com/ellite/Wallos/compare/v4.7.2...v4.7.3) (2026-03-21)


### Bug Fixes

* image search failing to save ([4fd87c3](https://github.com/ellite/Wallos/commit/4fd87c30144ae9cc38a68d4c3a30df181f8e1827))
* session expiration on pwa on android ([#1023](https://github.com/ellite/Wallos/issues/1023)) ([4fd87c3](https://github.com/ellite/Wallos/commit/4fd87c30144ae9cc38a68d4c3a30df181f8e1827))

## [4.7.2](https://github.com/ellite/Wallos/compare/v4.7.1...v4.7.2) (2026-03-19)


### Bug Fixes

* password reset tokens now expire after 60 minutes ([90bb618](https://github.com/ellite/Wallos/commit/90bb6186ee4091590b6efdef824c85f2494ff2bb))
* vulnerability would allow to bypass 2fa ([#1021](https://github.com/ellite/Wallos/issues/1021)) ([90bb618](https://github.com/ellite/Wallos/commit/90bb6186ee4091590b6efdef824c85f2494ff2bb))

## [4.7.1](https://github.com/ellite/Wallos/compare/v4.7.0...v4.7.1) (2026-03-19)


### Bug Fixes

* remove extra line on languages.php causing headers already sent ([#1019](https://github.com/ellite/Wallos/issues/1019)) ([f5c9a34](https://github.com/ellite/Wallos/commit/f5c9a3498ed2df8ae6b225fc63ce01a8ed5ce348))

## [4.7.0](https://github.com/ellite/Wallos/compare/v4.6.2...v4.7.0) (2026-03-19)


### Features

* add romanian translations ([#1017](https://github.com/ellite/Wallos/issues/1017)) ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* mask ai api key on the settings page ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))


### Bug Fixes

* ai recommendation numbering when deleting a recommendation ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* calendar ocurrences to respect subscriptions start date ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* logo search ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* retain first and last name when switching language during registration ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* set login cookie to httponly ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* ssrf vulnerability on several endpoints ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* unicode character on the css file ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))
* xss vulnerability on payment method rename endpoint ([e87387f](https://github.com/ellite/Wallos/commit/e87387f0ebb540cd33e6dfda7181db9db650ecef))

## [4.6.2](https://github.com/ellite/Wallos/compare/v4.6.1...v4.6.2) (2026-03-05)


### Bug Fixes

* ssrf vulnerability on all test notifications endpoint ([e8a5135](https://github.com/ellite/Wallos/commit/e8a513591dbbf885966e2ef55c38622785b9060d))
* vulnerability allowed to delete avatars from other users ([e8a5135](https://github.com/ellite/Wallos/commit/e8a513591dbbf885966e2ef55c38622785b9060d))
* xss vulnerability on password reset page ([e8a5135](https://github.com/ellite/Wallos/commit/e8a513591dbbf885966e2ef55c38622785b9060d))

## [4.6.1](https://github.com/ellite/Wallos/compare/v4.6.0...v4.6.1) (2026-02-10)


### Bug Fixes

* vulnerabily on add subscription endpoint ([#991](https://github.com/ellite/Wallos/issues/991)) ([76a53df](https://github.com/ellite/Wallos/commit/76a53df9cb4658123b8f0b7cf1826f1ba7d1c960))

## [4.6.0](https://github.com/ellite/Wallos/compare/v4.5.0...v4.6.0) (2025-12-20)


### Features

* add catalan translation ([#970](https://github.com/ellite/Wallos/issues/970)) ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))
* add robots.txt to disallow indexing. ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))
* add serverchan notifications. ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))
* notifications for subscription can be triggered up to 180 days before payment date. ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))


### Bug Fixes

* use RFC 5545 compliant date format in iCal exports ([#965](https://github.com/ellite/Wallos/issues/965)) ([b6b0abe](https://github.com/ellite/Wallos/commit/b6b0abed0d916c3ae5a31257f4c0b1a34436ad91))
* use RFC 5545 compliant date format in iCal exports. ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))
* use stable UID for iCal events to prevent duplicates. ([f5746e7](https://github.com/ellite/Wallos/commit/f5746e76a5dd6bbda7d52b1a2229c02bb9fad94b))

## [4.5.0](https://github.com/ellite/Wallos/compare/v4.4.1...v4.5.0) (2025-10-18)


### Features

* enforce CSRF protection and POST-only policy across endpoints ([#940](https://github.com/ellite/Wallos/issues/940)) ([3247ce2](https://github.com/ellite/Wallos/commit/3247ce2c8768d8e5910f74e5b8eba657b5b05cc1))

## [4.4.1](https://github.com/ellite/Wallos/compare/v4.4.0...v4.4.1) (2025-10-12)


### Bug Fixes

* get_subscriptions api endpoint was not returning subscriptions ([#937](https://github.com/ellite/Wallos/issues/937)) ([d6329a7](https://github.com/ellite/Wallos/commit/d6329a7af5a48f74b5f1d44a51cdc8c09dc2508b))

## [4.4.0](https://github.com/ellite/Wallos/compare/v4.3.0...v4.4.0) (2025-10-12)


### Features

* add mattermost notifications ([#923](https://github.com/ellite/Wallos/issues/923)) ([#934](https://github.com/ellite/Wallos/issues/934)) ([5629a31](https://github.com/ellite/Wallos/commit/5629a319bc5eb6cb80abfca06725aed9d2d9df88))
* add openrouter ai endpoint ([#922](https://github.com/ellite/Wallos/issues/922)) ([5629a31](https://github.com/ellite/Wallos/commit/5629a319bc5eb6cb80abfca06725aed9d2d9df88))
* enhance get_subscriptions API with admin access ([#928](https://github.com/ellite/Wallos/issues/928)) ([5629a31](https://github.com/ellite/Wallos/commit/5629a319bc5eb6cb80abfca06725aed9d2d9df88))


### Bug Fixes

* add autocomplete attribute to inputes ([#926](https://github.com/ellite/Wallos/issues/926)) ([5629a31](https://github.com/ellite/Wallos/commit/5629a319bc5eb6cb80abfca06725aed9d2d9df88))

## [4.3.0](https://github.com/ellite/Wallos/compare/v4.2.0...v4.3.0) (2025-09-15)


### Features

* add health endpoint and healthcheck to container ([#919](https://github.com/ellite/Wallos/issues/919)) ([852cb48](https://github.com/ellite/Wallos/commit/852cb485a65a58c91577b369fb9ea293d370bda8))

## [4.2.0](https://github.com/ellite/Wallos/compare/v4.1.1...v4.2.0) (2025-09-14)


### Features

* add pushplus notification service  ([#911](https://github.com/ellite/Wallos/issues/911)) ([27ac805](https://github.com/ellite/Wallos/commit/27ac805141c0d170a40c2a7796a589a5ef29544f))
* make container shutdown instant & graceful ([27ac805](https://github.com/ellite/Wallos/commit/27ac805141c0d170a40c2a7796a589a5ef29544f))
* make container shutdown instant & graceful  ([#916](https://github.com/ellite/Wallos/issues/916)) ([27ac805](https://github.com/ellite/Wallos/commit/27ac805141c0d170a40c2a7796a589a5ef29544f))
* option to delete ai recommendations ([27ac805](https://github.com/ellite/Wallos/commit/27ac805141c0d170a40c2a7796a589a5ef29544f))


### Bug Fixes

* parsing ai recommendations from gemini ([#909](https://github.com/ellite/Wallos/issues/909)) ([27ac805](https://github.com/ellite/Wallos/commit/27ac805141c0d170a40c2a7796a589a5ef29544f))

## [4.1.1](https://github.com/ellite/Wallos/compare/v4.1.0...v4.1.1) (2025-08-13)


### Bug Fixes

* missing apikey validation error on get_monthly_cost api endpoint ([3ecc160](https://github.com/ellite/Wallos/commit/3ecc160ccb73f22367bea427315519876de74a65))
* redirect from dashboard to subscriptions for new users ([3ecc160](https://github.com/ellite/Wallos/commit/3ecc160ccb73f22367bea427315519876de74a65))
* wrong check for disabling password login ([3ecc160](https://github.com/ellite/Wallos/commit/3ecc160ccb73f22367bea427315519876de74a65))

## [4.1.0](https://github.com/ellite/Wallos/compare/v4.0.0...v4.1.0) (2025-08-11)


### Features

* add at a glance dashboard ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))
* add get_oidc_settings endpoint to the api ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))
* ai recommendations with chatgpt, gemini or ollama ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))
* allow to disable password login when oidc is enabled ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))
* display ai recommendations on the dashboard ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))
* refactor css colors ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))


### Bug Fixes

* accept both api_key and apiKey as parameter on the api ([ba6dddf](https://github.com/ellite/Wallos/commit/ba6dddf52601fdbeb18897731beacc48d16043c3))

## [4.0.0](https://github.com/ellite/Wallos/compare/v3.3.1...v4.0.0) (2025-07-21)


### ⚠ BREAKING CHANGES

* add oauth / oidc support ([#875](https://github.com/ellite/Wallos/issues/875))

### Features

* add oauth / oidc support ([#875](https://github.com/ellite/Wallos/issues/875)) ([805e688](https://github.com/ellite/Wallos/commit/805e688ec0fac1dbb362e847ed8a4e3e301ee113))
* add oauth/oidc support ([#873](https://github.com/ellite/Wallos/issues/873)) ([c0d53e4](https://github.com/ellite/Wallos/commit/c0d53e4423996595e5c82404af92e077c00eae47))

## [3.3.1](https://github.com/ellite/Wallos/compare/v3.3.0...v3.3.1) (2025-07-19)


### Bug Fixes

* code of new taiwan dollar ([596cbc4](https://github.com/ellite/Wallos/commit/596cbc42464100dc8c6db5d07c090dab4b767268))
* decoding of header from database on the webhook notifications ([596cbc4](https://github.com/ellite/Wallos/commit/596cbc42464100dc8c6db5d07c090dab4b767268))
* unicode issue on telegram notifications ([#871](https://github.com/ellite/Wallos/issues/871)) ([596cbc4](https://github.com/ellite/Wallos/commit/596cbc42464100dc8c6db5d07c090dab4b767268))

## [3.3.0](https://github.com/ellite/Wallos/compare/v3.2.0...v3.3.0) (2025-06-09)


### Features

* set todays date on start subscription field for new subscriptions by default ([#848](https://github.com/ellite/Wallos/issues/848)) ([d3fd938](https://github.com/ellite/Wallos/commit/d3fd9387d34f430adb84ef553193b4ad3080c009))


### Bug Fixes

* visual issue with date fields on ios ([#846](https://github.com/ellite/Wallos/issues/846)) ([e2df8f7](https://github.com/ellite/Wallos/commit/e2df8f7e24678f9d62f36f68c94de838fc741913))

## [3.2.0](https://github.com/ellite/Wallos/compare/v3.1.1...v3.2.0) (2025-06-08)


### Features

* add button to auto fill the next payment date ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))
* add first and last names to the user profile ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))
* add indonesian language ([#842](https://github.com/ellite/Wallos/issues/842)) ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))
* add new currency ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))
* Add new currency ([#829](https://github.com/ellite/Wallos/issues/829)) ([288ad45](https://github.com/ellite/Wallos/commit/288ad456564c307018541a09df447898e1d62d26))
* enable IPv6 environments by configuring a dual-stack listen in nginx ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))


### Bug Fixes

* vulnerability on test webhook endpoint ([48db4e3](https://github.com/ellite/Wallos/commit/48db4e300df6128b7cc0b4e0c86271bfb3159545))

## [3.1.1](https://github.com/ellite/Wallos/compare/v3.1.0...v3.1.1) (2025-05-15)


### Bug Fixes

* issue listing prices when uah  was added to the list of currencies ([#823](https://github.com/ellite/Wallos/issues/823)) ([bd20b56](https://github.com/ellite/Wallos/commit/bd20b5697659fc6117113205a3995d7e5f9026c9))

## [3.1.0](https://github.com/ellite/Wallos/compare/v3.0.2...v3.1.0) (2025-05-08)


### Features

* add danish translation ([0cfefc7](https://github.com/ellite/Wallos/commit/0cfefc7f07056d59ad911f926cc56ff3e6c8e261))


### Bug Fixes

* disable totp with backup code ([0cfefc7](https://github.com/ellite/Wallos/commit/0cfefc7f07056d59ad911f926cc56ff3e6c8e261))
* gotify settings test ([0cfefc7](https://github.com/ellite/Wallos/commit/0cfefc7f07056d59ad911f926cc56ff3e6c8e261))
* vulnerability adding logos from url ([0cfefc7](https://github.com/ellite/Wallos/commit/0cfefc7f07056d59ad911f926cc56ff3e6c8e261))

## [3.0.2](https://github.com/ellite/Wallos/compare/v3.0.1...v3.0.2) (2025-05-03)


### Bug Fixes

* delete avatar would not work if wallos is on a subfolder ([69c7d52](https://github.com/ellite/Wallos/commit/69c7d52cf8d708bcb046343faa663209c8d36779))
* some strings not using translations on the calendar page ([69c7d52](https://github.com/ellite/Wallos/commit/69c7d52cf8d708bcb046343faa663209c8d36779))
* vulnerability on delete avatar ([69c7d52](https://github.com/ellite/Wallos/commit/69c7d52cf8d708bcb046343faa663209c8d36779))

## [3.0.1](https://github.com/ellite/Wallos/compare/v3.0.0...v3.0.1) (2025-04-30)


### Bug Fixes

* allow to clear the budget field ([f6b8fb9](https://github.com/ellite/Wallos/commit/f6b8fb9162c5fb4fefa1fbd9cde65c201e96be6c))
* don't show budget alert when budget is 0 ([f6b8fb9](https://github.com/ellite/Wallos/commit/f6b8fb9162c5fb4fefa1fbd9cde65c201e96be6c))

## [3.0.0](https://github.com/ellite/Wallos/compare/v2.52.2...v3.0.0) (2025-04-27)


### ⚠ BREAKING CHANGES

* simplified webhook notifications without iterator (might break your current webhook settings)

### Features

* simplified webhook notifications without iterator (might break your current webhook settings) ([e0f2048](https://github.com/ellite/Wallos/commit/e0f204803e635400c404529d87e5057c579c8531))
* use mobile style toggles instead of checkboxes ([e0f2048](https://github.com/ellite/Wallos/commit/e0f204803e635400c404529d87e5057c579c8531))
* webhooks can now be used for cancelation notifications ([e0f2048](https://github.com/ellite/Wallos/commit/e0f204803e635400c404529d87e5057c579c8531))


### Bug Fixes

* barely readable placeholder text on textarea on dark the ([e0f2048](https://github.com/ellite/Wallos/commit/e0f204803e635400c404529d87e5057c579c8531))

## [2.52.2](https://github.com/ellite/Wallos/compare/v2.52.1...v2.52.2) (2025-04-26)


### Bug Fixes

* incorrect headers on the api ([#802](https://github.com/ellite/Wallos/issues/802)) ([af68c11](https://github.com/ellite/Wallos/commit/af68c11abf5d5a64fd7136e1d2e37323d170c77e))

## [2.52.1](https://github.com/ellite/Wallos/compare/v2.52.0...v2.52.1) (2025-04-26)


### Bug Fixes

* error on statistics page when budget = 0 ([#800](https://github.com/ellite/Wallos/issues/800)) ([b7712dc](https://github.com/ellite/Wallos/commit/b7712dc80d6642a6a33a28adc641f9a4b3263ae6))

## [2.52.0](https://github.com/ellite/Wallos/compare/v2.51.1...v2.52.0) (2025-04-19)


### Features

* new graph cost vs budget on statistics ([#793](https://github.com/ellite/Wallos/issues/793)) ([6d67319](https://github.com/ellite/Wallos/commit/6d673195ba39f1a52e9ea16bad21221768690e7a))

## [2.51.1](https://github.com/ellite/Wallos/compare/v2.51.0...v2.51.1) (2025-04-19)


### Bug Fixes

* timezone for cronjobs now comes from TZ env var first ([#791](https://github.com/ellite/Wallos/issues/791)) ([66a1a45](https://github.com/ellite/Wallos/commit/66a1a45f2dc1df99f8292cbb531d569f706eca6d))

## [2.51.0](https://github.com/ellite/Wallos/compare/v2.50.1...v2.51.0) (2025-04-18)


### Features

* add over budget warnings on the calendar ([88eae10](https://github.com/ellite/Wallos/commit/88eae1002f0cc29a847e95b7698ab713779ec4f4))


### Bug Fixes

* force correct timezone on the cronjobs ([88eae10](https://github.com/ellite/Wallos/commit/88eae1002f0cc29a847e95b7698ab713779ec4f4))

## [2.50.1](https://github.com/ellite/Wallos/compare/v2.50.0...v2.50.1) (2025-04-16)


### Bug Fixes

* localization on date on browsers not in english ([c7b3fb4](https://github.com/ellite/Wallos/commit/c7b3fb445182e19bc464ac987977bac266628757))

## [2.50.0](https://github.com/ellite/Wallos/compare/v2.49.1...v2.50.0) (2025-04-16)


### Features

* shorten date displayed on the list of subscriptions ([68f1d47](https://github.com/ellite/Wallos/commit/68f1d4757737de50622bb4b2aeb8f291dec62972))
* use user defined language for the date on the list of subscriptions ([68f1d47](https://github.com/ellite/Wallos/commit/68f1d4757737de50622bb4b2aeb8f291dec62972))


### Bug Fixes

* limit name display, when sub has no logo to two lines ([68f1d47](https://github.com/ellite/Wallos/commit/68f1d4757737de50622bb4b2aeb8f291dec62972))
* use translations on the mobile menu ([68f1d47](https://github.com/ellite/Wallos/commit/68f1d4757737de50622bb4b2aeb8f291dec62972))

## [2.49.1](https://github.com/ellite/Wallos/compare/v2.49.0...v2.49.1) (2025-04-13)


### Bug Fixes

* version number ([eade2d9](https://github.com/ellite/Wallos/commit/eade2d9919e5d30e7be279f53e278fb746095762))

## [2.49.0](https://github.com/ellite/Wallos/compare/v2.48.1...v2.49.0) (2025-04-13)


### Features

* show name on mobile view when subscription has no logo ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))
* show timezone on sendnotification cronjob on admin page ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))
* use currencyConverter for notifications as well ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))
* use symbol from db when currencyFormatter does not support the currency ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))


### Bug Fixes

* date comparison check on sendnotifications cronjob ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))
* emails with encryption set to none not working without ssl ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))
* error when not setting custom headers for ntfy ([9eb2907](https://github.com/ellite/Wallos/commit/9eb2907145297e3b7aac54dd5b51451d961f549a))

## [2.48.1](https://github.com/ellite/Wallos/compare/v2.48.0...v2.48.1) (2025-03-27)


### Bug Fixes

* notifications would also be sent x days after subscription was due in some cases ([ba912a3](https://github.com/ellite/Wallos/commit/ba912a37d1a0d95401a38dabe8f98f29a6aa49db))

## [2.48.0](https://github.com/ellite/Wallos/compare/v2.47.1...v2.48.0) (2025-03-20)


### Features

* add update notification and release notes to the about page ([3e0e88d](https://github.com/ellite/Wallos/commit/3e0e88d6a2adc46c17773b09dd8684618c979711))
* increase privacy by not sending referrer to external urls ([3e0e88d](https://github.com/ellite/Wallos/commit/3e0e88d6a2adc46c17773b09dd8684618c979711))
* small layout change on the about page ([3e0e88d](https://github.com/ellite/Wallos/commit/3e0e88d6a2adc46c17773b09dd8684618c979711))

## [2.47.1](https://github.com/ellite/Wallos/compare/v2.47.0...v2.47.1) (2025-03-19)


### Bug Fixes

* small layout inconsistencies on the dashboard ([19d3067](https://github.com/ellite/Wallos/commit/19d30672b2635b6e79eaa6eb5c49100d7a27a63a))

## [2.47.0](https://github.com/ellite/Wallos/compare/v2.46.1...v2.47.0) (2025-03-19)


### Features

* add filter by renew type ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* add sort by renew type ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* add ukranian translation ([#756](https://github.com/ellite/Wallos/issues/756)) ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* remove "Wallos" text from calendar export ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))


### Bug Fixes

* ical trigger to spec RFC5545 ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* special chars on calendar exports ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* special chars on notifications ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))
* state filter not cleared by clear button ([1bec973](https://github.com/ellite/Wallos/commit/1bec973803e0b3c00d2765bbf80447439127574d))

## [2.46.1](https://github.com/ellite/Wallos/compare/v2.46.0...v2.46.1) (2025-03-06)


### Bug Fixes

* calculation of monthly cost progress graph ([#747](https://github.com/ellite/Wallos/issues/747)) ([77486ec](https://github.com/ellite/Wallos/commit/77486ec92c44b71f69e85b1eafb7f3a98c4a44c1))

## [2.46.0](https://github.com/ellite/Wallos/compare/v2.45.2...v2.46.0) (2025-02-22)


### Features

* sorting by category or payment method respects order from the settings page ([51b2272](https://github.com/ellite/Wallos/commit/51b22727bf5656a4a263519b5b56adfe6a2d12be))


### Bug Fixes

* access to tmp folder by www-data ([51b2272](https://github.com/ellite/Wallos/commit/51b22727bf5656a4a263519b5b56adfe6a2d12be))

## [2.45.2](https://github.com/ellite/Wallos/compare/v2.45.1...v2.45.2) (2025-02-05)


### Bug Fixes

* bug setting main currency for the first registered user ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))
* deprecation message ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))
* subscription progress above 100% for disabled subscriptions ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))
* typo on czech translation ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))
* use first currency on the list of currencies if user has not selected a main currency ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))
* use gd if imagick is not available ([c43b08a](https://github.com/ellite/Wallos/commit/c43b08aa4c45c907f82eb6afe37fd46aa5103654))

## [2.45.1](https://github.com/ellite/Wallos/compare/v2.45.0...v2.45.1) (2025-01-28)


### Bug Fixes

* improve czech translation ([e2dc269](https://github.com/ellite/Wallos/commit/e2dc2696310159900c1f8fbe0a090e66b29b778d))
* improve japanese translation ([#713](https://github.com/ellite/Wallos/issues/713)) ([e2dc269](https://github.com/ellite/Wallos/commit/e2dc2696310159900c1f8fbe0a090e66b29b778d))
* improve traditional chinese translation ([e2dc269](https://github.com/ellite/Wallos/commit/e2dc2696310159900c1f8fbe0a090e66b29b778d))
* setting pgid and puid for the container ([e2dc269](https://github.com/ellite/Wallos/commit/e2dc2696310159900c1f8fbe0a090e66b29b778d))

## [2.45.0](https://github.com/ellite/Wallos/compare/v2.44.1...v2.45.0) (2025-01-19)


### Features

* add czech translations ([#701](https://github.com/ellite/Wallos/issues/701)) ([426fdfa](https://github.com/ellite/Wallos/commit/426fdfa5c79d32c7d5a0722a0590d39547cfd1fa))

## [2.44.1](https://github.com/ellite/Wallos/compare/v2.44.0...v2.44.1) (2025-01-19)


### Bug Fixes

* error setting date of last exchange rates update ([#699](https://github.com/ellite/Wallos/issues/699)) ([d2f68c4](https://github.com/ellite/Wallos/commit/d2f68c457e9b1328caf983ddc6e2827430855aa6))

## [2.44.0](https://github.com/ellite/Wallos/compare/v2.43.1...v2.44.0) (2025-01-12)


### Features

* allow notifications on due date ([87f148d](https://github.com/ellite/Wallos/commit/87f148d1745bec19f5713b8a367a3615871e6e33))


### Bug Fixes

* don't expose disabled notifications to ical feed ([87f148d](https://github.com/ellite/Wallos/commit/87f148d1745bec19f5713b8a367a3615871e6e33))
* email notification test always sending to admins email ([87f148d](https://github.com/ellite/Wallos/commit/87f148d1745bec19f5713b8a367a3615871e6e33))

## [2.43.1](https://github.com/ellite/Wallos/compare/v2.43.0...v2.43.1) (2025-01-12)


### Bug Fixes

* edit / delete subscription menu not accessible ([#689](https://github.com/ellite/Wallos/issues/689)) ([b668d37](https://github.com/ellite/Wallos/commit/b668d37d38f799ee0dda5a69a4824d03dd21e1bc))

## [2.43.0](https://github.com/ellite/Wallos/compare/v2.42.2...v2.43.0) (2025-01-11)


### Features

* new api endpoint that returns the version ([ff13fcb](https://github.com/ellite/Wallos/commit/ff13fcb6547ec4a9c972a2c0f0b6f42d69620f8b))
* option to show progress of subscription cycle ([ff13fcb](https://github.com/ellite/Wallos/commit/ff13fcb6547ec4a9c972a2c0f0b6f42d69620f8b))


### Bug Fixes

* currency symbol for monthly budget ([ff13fcb](https://github.com/ellite/Wallos/commit/ff13fcb6547ec4a9c972a2c0f0b6f42d69620f8b))

## [2.42.2](https://github.com/ellite/Wallos/compare/v2.42.1...v2.42.2) (2024-12-21)


### Bug Fixes

* version number ([#668](https://github.com/ellite/Wallos/issues/668)) ([683a366](https://github.com/ellite/Wallos/commit/683a3662ff998066f5d8de3be88e4d40d766442a))

## [2.42.1](https://github.com/ellite/Wallos/compare/v2.42.0...v2.42.1) (2024-12-21)


### Bug Fixes

* remove debug echo on stats page ([#666](https://github.com/ellite/Wallos/issues/666)) ([d9a2488](https://github.com/ellite/Wallos/commit/d9a24885ffbbdb3c08d9015804eea8cb0fea6cea))

## [2.42.0](https://github.com/ellite/Wallos/compare/v2.41.0...v2.42.0) (2024-12-21)


### Features

* add total monthly cost trend graph to the statistics page ([e7185f9](https://github.com/ellite/Wallos/commit/e7185f92578b3103d097b12b8c4313635f263d9f))
* allow email notifications without authentication ([e7185f9](https://github.com/ellite/Wallos/commit/e7185f92578b3103d097b12b8c4313635f263d9f))


### Bug Fixes

* don't update next payment date for disabled subscriptions ([e7185f9](https://github.com/ellite/Wallos/commit/e7185f92578b3103d097b12b8c4313635f263d9f))
* xss security vulnerability with the avatar selection ([e7185f9](https://github.com/ellite/Wallos/commit/e7185f92578b3103d097b12b8c4313635f263d9f))

## [2.41.0](https://github.com/ellite/Wallos/compare/v2.40.0...v2.41.0) (2024-12-11)


### Features

* add payment cycle to csv/json export ([5e6bc90](https://github.com/ellite/Wallos/commit/5e6bc903bcd95580ed58f744977d92c6330b3d9f))
* run db migration after importing db ([5e6bc90](https://github.com/ellite/Wallos/commit/5e6bc903bcd95580ed58f744977d92c6330b3d9f))
* run db migration after restoring database ([5e6bc90](https://github.com/ellite/Wallos/commit/5e6bc903bcd95580ed58f744977d92c6330b3d9f))
* store weekly the total yearly cost of subscriptions ([5e6bc90](https://github.com/ellite/Wallos/commit/5e6bc903bcd95580ed58f744977d92c6330b3d9f))


### Bug Fixes

* double encoding in statistics labels ([5e6bc90](https://github.com/ellite/Wallos/commit/5e6bc903bcd95580ed58f744977d92c6330b3d9f))

## [2.40.0](https://github.com/ellite/Wallos/compare/v2.39.1...v2.40.0) (2024-12-10)


### Features

* add dutch translation ([#655](https://github.com/ellite/Wallos/issues/655)) ([b5a9880](https://github.com/ellite/Wallos/commit/b5a98806d1f453180ce15724fa198d248177e488))

## [2.39.1](https://github.com/ellite/Wallos/compare/v2.39.0...v2.39.1) (2024-12-06)


### Bug Fixes

* svg error on calendar page ([#650](https://github.com/ellite/Wallos/issues/650)) ([8ba79c0](https://github.com/ellite/Wallos/commit/8ba79c0725815c6de8458c74961bbdf23a7d3e9d))

## [2.39.0](https://github.com/ellite/Wallos/compare/v2.38.3...v2.39.0) (2024-12-06)


### Features

* add icalendar subscription ([f5ddbff](https://github.com/ellite/Wallos/commit/f5ddbff0c1e0be676604390101c56c04c778f56a))

## [2.38.3](https://github.com/ellite/Wallos/compare/v2.38.2...v2.38.3) (2024-12-06)


### Bug Fixes

* vulnerability on the restore database endpoints ([3b2de8b](https://github.com/ellite/Wallos/commit/3b2de8b7c22090afdf7115c25fd8b497a5626ea3))

## [2.38.2](https://github.com/ellite/Wallos/compare/v2.38.1...v2.38.2) (2024-11-19)


### Bug Fixes

* logo search positioned below other elements ([#637](https://github.com/ellite/Wallos/issues/637)) ([72f7e57](https://github.com/ellite/Wallos/commit/72f7e5791423c45f910a791b20aafba301d0172f))

## [2.38.1](https://github.com/ellite/Wallos/compare/v2.38.0...v2.38.1) (2024-11-17)


### Bug Fixes

* bug introduced on 2.38.0 on the subscriptions dashboard ([#634](https://github.com/ellite/Wallos/issues/634)) ([f63c543](https://github.com/ellite/Wallos/commit/f63c543cdd7512b216004db3b279884dbda87ce4))

## [2.38.0](https://github.com/ellite/Wallos/compare/v2.37.1...v2.38.0) (2024-11-17)


### Features

* add option for manual/automatic renewals ([6e44a26](https://github.com/ellite/Wallos/commit/6e44a26703486d0ba30ee6ae8d3c46bfc3c6630a))
* add some leeway for totp codes ([6e44a26](https://github.com/ellite/Wallos/commit/6e44a26703486d0ba30ee6ae8d3c46bfc3c6630a))
* add start date to subscriptions ([6e44a26](https://github.com/ellite/Wallos/commit/6e44a26703486d0ba30ee6ae8d3c46bfc3c6630a))


### Bug Fixes

* layout issue with subscriptions list during search ([6e44a26](https://github.com/ellite/Wallos/commit/6e44a26703486d0ba30ee6ae8d3c46bfc3c6630a))

## [2.37.1](https://github.com/ellite/Wallos/compare/v2.37.0...v2.37.1) (2024-11-15)


### Bug Fixes

* version mismatch ([#627](https://github.com/ellite/Wallos/issues/627)) ([c4a9b16](https://github.com/ellite/Wallos/commit/c4a9b1627fbc7278398bf2d8bf7cae2934d349ca))

## [2.37.0](https://github.com/ellite/Wallos/compare/v2.36.2...v2.37.0) (2024-11-15)


### Features

* add monthly statistics to the calendar page ([f085f8a](https://github.com/ellite/Wallos/commit/f085f8adece3af2548858f665db16d4843d3e622))


### Bug Fixes

* notifications being sent on the wrong day ([f085f8a](https://github.com/ellite/Wallos/commit/f085f8adece3af2548858f665db16d4843d3e622))

## [2.36.2](https://github.com/ellite/Wallos/compare/v2.36.1...v2.36.2) (2024-11-03)


### Bug Fixes

* only show swipe hint on mobile screens ([#612](https://github.com/ellite/Wallos/issues/612)) ([bd5e351](https://github.com/ellite/Wallos/commit/bd5e3511829a798ab47ca5e9c9d080aae45ae1a0))

## [2.36.1](https://github.com/ellite/Wallos/compare/v2.36.0...v2.36.1) (2024-11-03)


### Bug Fixes

* version number ([#610](https://github.com/ellite/Wallos/issues/610)) ([4bd40f1](https://github.com/ellite/Wallos/commit/4bd40f1c561e979322375b95aeccccd18c4780fd))

## [2.36.0](https://github.com/ellite/Wallos/compare/v2.35.0...v2.36.0) (2024-11-03)


### Features

* add hint for mobile swipe action ([#608](https://github.com/ellite/Wallos/issues/608)) ([49666f8](https://github.com/ellite/Wallos/commit/49666f867cdbaa4d4c0c1551d0b4b3023830606a))

## [2.35.0](https://github.com/ellite/Wallos/compare/v2.34.0...v2.35.0) (2024-11-01)


### Features

* new menu icons ([28444ab](https://github.com/ellite/Wallos/commit/28444abef1cee338e41e57cbf6f13666b917bbde))
* swipe subscription for actions on the experimental mobile navigation ([28444ab](https://github.com/ellite/Wallos/commit/28444abef1cee338e41e57cbf6f13666b917bbde))

## [2.34.0](https://github.com/ellite/Wallos/compare/v2.33.1...v2.34.0) (2024-10-31)


### Features

* link version update banner to github release ([f007adf](https://github.com/ellite/Wallos/commit/f007adf9658eb1fd095c2716e4146130535f6cb7))
* only show filters that are actually used ([f007adf](https://github.com/ellite/Wallos/commit/f007adf9658eb1fd095c2716e4146130535f6cb7))


### Bug Fixes

* filters for categories and payment method respect order from settings ([f007adf](https://github.com/ellite/Wallos/commit/f007adf9658eb1fd095c2716e4146130535f6cb7))

## [2.33.1](https://github.com/ellite/Wallos/compare/v2.33.0...v2.33.1) (2024-10-30)


### Bug Fixes

* improve localization ([6480f87](https://github.com/ellite/Wallos/commit/6480f8744094d5ce0f05d7d155925540ac73b156))
* layout issue on the settings page ([#598](https://github.com/ellite/Wallos/issues/598)) ([6480f87](https://github.com/ellite/Wallos/commit/6480f8744094d5ce0f05d7d155925540ac73b156))

## [2.33.0](https://github.com/ellite/Wallos/compare/v2.32.0...v2.33.0) (2024-10-29)


### Features

* replacement for disabled subscriptions, to more accurately calculate savings ([5c92528](https://github.com/ellite/Wallos/commit/5c9252880837a7886c903ddc7ae92c8fed29b452))

## [2.32.0](https://github.com/ellite/Wallos/compare/v2.31.1...v2.32.0) (2024-10-27)


### Features

* settings to allow to ignore certificates for some notification methods ([2a0e665](https://github.com/ellite/Wallos/commit/2a0e665e77eca804fa70dafc1a3a0010eb9da270))

## [2.31.1](https://github.com/ellite/Wallos/compare/v2.31.0...v2.31.1) (2024-10-25)


### Bug Fixes

* add missing {{days_until}} variable to string version of the webhook ([ebc7b83](https://github.com/ellite/Wallos/commit/ebc7b83e9a0a32aecf3b1aa933408bf9b6baea3a))
* display actual error message when email test fails ([ebc7b83](https://github.com/ellite/Wallos/commit/ebc7b83e9a0a32aecf3b1aa933408bf9b6baea3a))

## [2.31.0](https://github.com/ellite/Wallos/compare/v2.30.1...v2.31.0) (2024-10-22)


### Features

* handle webhook payload as string if it is not a json object ([#583](https://github.com/ellite/Wallos/issues/583)) ([ee834d6](https://github.com/ellite/Wallos/commit/ee834d6198fa3315facd23a734655adf391bb736))

## [2.30.1](https://github.com/ellite/Wallos/compare/v2.30.0...v2.30.1) (2024-10-14)


### Bug Fixes

* verify correct path before creating logos folder ([782ebcd](https://github.com/ellite/Wallos/commit/782ebcd64fc947ea82eabaac6bc26a32676271a1))

## [2.30.0](https://github.com/ellite/Wallos/compare/v2.29.2...v2.30.0) (2024-10-13)


### Features

* add vietnamese translation ([#573](https://github.com/ellite/Wallos/issues/573)) ([45ff10f](https://github.com/ellite/Wallos/commit/45ff10f953f4af681252ed4d77c32b375f9c396c))

## [2.29.2](https://github.com/ellite/Wallos/compare/v2.29.1...v2.29.2) (2024-10-11)


### Bug Fixes

* xss issue on the dashboard ([#568](https://github.com/ellite/Wallos/issues/568)) ([e642129](https://github.com/ellite/Wallos/commit/e6421296aa708b02c468b10e3c9d0f28012c1282))

## [2.29.1](https://github.com/ellite/Wallos/compare/v2.29.0...v2.29.1) (2024-10-11)


### Bug Fixes

* mysql injection vulnerability ([3d6a8c3](https://github.com/ellite/Wallos/commit/3d6a8c340843230eff97b459e85efbea55aac01f))
* new profile page not being cached by service worker ([3d6a8c3](https://github.com/ellite/Wallos/commit/3d6a8c340843230eff97b459e85efbea55aac01f))

## [2.29.0](https://github.com/ellite/Wallos/compare/v2.28.0...v2.29.0) (2024-10-09)


### Features

* add url and notes as variables for the notifications webhook ([790defb](https://github.com/ellite/Wallos/commit/790defb2b1d1cd3d8c93738155edb19f96d0aa2a))


### Bug Fixes

* bug when looping multiple subscriptions on the notifications webhook ([790defb](https://github.com/ellite/Wallos/commit/790defb2b1d1cd3d8c93738155edb19f96d0aa2a))

## [2.28.0](https://github.com/ellite/Wallos/compare/v2.27.3...v2.28.0) (2024-10-07)


### Features

* get admin setting api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get categories endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get currencies endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get fixer api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get household api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get notifications api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get payment methods api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get settings api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get subscriptions api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))
* get user api endpoint ([07d456a](https://github.com/ellite/Wallos/commit/07d456a9c3d9cc3eb9ae80edb666caa103cababe))

## [2.27.3](https://github.com/ellite/Wallos/compare/v2.27.2...v2.27.3) (2024-10-05)


### Bug Fixes

* missing folders on baremetal installation ([#554](https://github.com/ellite/Wallos/issues/554)) ([03f34d1](https://github.com/ellite/Wallos/commit/03f34d1aee3f74c3bf9c53c04c1494106be4bb47))
* missing fonts ([03f34d1](https://github.com/ellite/Wallos/commit/03f34d1aee3f74c3bf9c53c04c1494106be4bb47))

## [2.27.2](https://github.com/ellite/Wallos/compare/v2.27.1...v2.27.2) (2024-10-04)


### Bug Fixes

* bump version ([#546](https://github.com/ellite/Wallos/issues/546)) ([c5460bd](https://github.com/ellite/Wallos/commit/c5460bd79bdd056e788774ac52cfd4262eada5e7))

## [2.27.1](https://github.com/ellite/Wallos/compare/v2.27.0...v2.27.1) (2024-10-04)


### Bug Fixes

* add missing assets to the service worker ([#542](https://github.com/ellite/Wallos/issues/542)) ([0251da2](https://github.com/ellite/Wallos/commit/0251da23f4254420a471fcd4c4951d0d0b1bb4df))

## [2.27.0](https://github.com/ellite/Wallos/compare/v2.26.0...v2.27.0) (2024-10-04)


### Features

* api endpoint to calculate monthly cost ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))
* fisrt api endpoint ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))
* redesigned experimental mobile navigation menu ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))
* split settings page into settings and profile page ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))
* user has api key available on profile page ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))


### Bug Fixes

* small fixes and typos ([a173d27](https://github.com/ellite/Wallos/commit/a173d2765fd2a1a641f32fbea198775b1bdc0b00))

## [2.26.0](https://github.com/ellite/Wallos/compare/v2.25.0...v2.26.0) (2024-09-29)


### Features

* add mobile menu navigation to experimental settings ([1dbba18](https://github.com/ellite/Wallos/commit/1dbba18446ac53568492af9d2aee3f90db7168ca))
* use browsers locale to set dates on the dashboard ([1dbba18](https://github.com/ellite/Wallos/commit/1dbba18446ac53568492af9d2aee3f90db7168ca))

## [2.25.0](https://github.com/ellite/Wallos/compare/v2.24.1...v2.25.0) (2024-09-28)


### Features

* add 2fa support ([#525](https://github.com/ellite/Wallos/issues/525)) ([2f16ab3](https://github.com/ellite/Wallos/commit/2f16ab3fdf89b8ba6b1010510d8b169aad425f38))

## [2.24.1](https://github.com/ellite/Wallos/compare/v2.24.0...v2.24.1) (2024-09-23)


### Bug Fixes

* small layout issue on the settings page ([0623ceb](https://github.com/ellite/Wallos/commit/0623cebe67182b493770615c518977907e11d359))

## [2.24.0](https://github.com/ellite/Wallos/compare/v2.23.2...v2.24.0) (2024-09-18)


### Features

* add button to clean up search field ([da3ee78](https://github.com/ellite/Wallos/commit/da3ee782f13c1eaa98a85de5dbe33714d173a323))


### Bug Fixes

* cases where theme and sort cookies could be missing ([da3ee78](https://github.com/ellite/Wallos/commit/da3ee782f13c1eaa98a85de5dbe33714d173a323))
* position of dropdown on rtl layout ([da3ee78](https://github.com/ellite/Wallos/commit/da3ee782f13c1eaa98a85de5dbe33714d173a323))

## [2.23.2](https://github.com/ellite/Wallos/compare/v2.23.1...v2.23.2) (2024-09-04)


### Bug Fixes

* sort order after edit subscription in case the cookie is missing ([87809fe](https://github.com/ellite/Wallos/commit/87809fea71b92c7518173fedd189d7e76ce11bfb))

## [2.23.1](https://github.com/ellite/Wallos/compare/v2.23.0...v2.23.1) (2024-09-01)


### Bug Fixes

* warning on top of dashboard page ([#512](https://github.com/ellite/Wallos/issues/512)) ([9056722](https://github.com/ellite/Wallos/commit/905672243b75e6b3d367d439bdbbb37d1b5ae0fa))

## [2.23.0](https://github.com/ellite/Wallos/compare/v2.22.1...v2.23.0) (2024-09-01)


### Features

* add multi email recipients ([fed0192](https://github.com/ellite/Wallos/commit/fed0192394e77409dae04d4ab3cdda0ba0c578a4))
* add option for also showing the original price on the dashboard ([fed0192](https://github.com/ellite/Wallos/commit/fed0192394e77409dae04d4ab3cdda0ba0c578a4))
* open edit form after cloning subscription ([fed0192](https://github.com/ellite/Wallos/commit/fed0192394e77409dae04d4ab3cdda0ba0c578a4))
* select multiple filters on the dashboard ([fed0192](https://github.com/ellite/Wallos/commit/fed0192394e77409dae04d4ab3cdda0ba0c578a4))


### Bug Fixes

* export.php csv header typo ([#499](https://github.com/ellite/Wallos/issues/499)) ([6e96c5d](https://github.com/ellite/Wallos/commit/6e96c5d4b0c7264ab37a85e9a8b8062f96f69c5c))
* typo on export subscriptions to csv ([fed0192](https://github.com/ellite/Wallos/commit/fed0192394e77409dae04d4ab3cdda0ba0c578a4))

## [2.22.1](https://github.com/ellite/Wallos/compare/v2.22.0...v2.22.1) (2024-08-11)


### Bug Fixes

* inline items in subscription form out of place ([#489](https://github.com/ellite/Wallos/issues/489)) ([3f33ba0](https://github.com/ellite/Wallos/commit/3f33ba0310af0c903db9bef1dd6668146219142c))

## [2.22.0](https://github.com/ellite/Wallos/compare/v2.21.3...v2.22.0) (2024-08-09)


### Features

* admin can manually trigger cronjobs ([1946ac9](https://github.com/ellite/Wallos/commit/1946ac9855696892b9a0790d46623614aa9aab2c))


### Bug Fixes

* only allow the system and admin to run the cronjobs ([1946ac9](https://github.com/ellite/Wallos/commit/1946ac9855696892b9a0790d46623614aa9aab2c))
* reduce size of the log files of the cronjobs ([1946ac9](https://github.com/ellite/Wallos/commit/1946ac9855696892b9a0790d46623614aa9aab2c))

## [2.21.3](https://github.com/ellite/Wallos/compare/v2.21.2...v2.21.3) (2024-08-08)


### Bug Fixes

* broken avatar upload when using the french language ([cf0d5d3](https://github.com/ellite/Wallos/commit/cf0d5d3df30909a0de7ef84aae2601d805617f90))
* more deprecation warnings on image uploads ([cf0d5d3](https://github.com/ellite/Wallos/commit/cf0d5d3df30909a0de7ef84aae2601d805617f90))

## [2.21.2](https://github.com/ellite/Wallos/compare/v2.21.1...v2.21.2) (2024-08-07)


### Bug Fixes

* add samesite directive to cookies ([8b0325c](https://github.com/ellite/Wallos/commit/8b0325c7d3c672754de220efd52b9ba9de8a9868))
* service worker precaching logout.php causes user to be logged out ([8b0325c](https://github.com/ellite/Wallos/commit/8b0325c7d3c672754de220efd52b9ba9de8a9868))
* sort by price ([8b0325c](https://github.com/ellite/Wallos/commit/8b0325c7d3c672754de220efd52b9ba9de8a9868))

## [2.21.1](https://github.com/ellite/Wallos/compare/v2.21.0...v2.21.1) (2024-08-06)


### Bug Fixes

* deprecation message for null value ([#479](https://github.com/ellite/Wallos/issues/479)) ([0274b1d](https://github.com/ellite/Wallos/commit/0274b1d5257f8f1c4156e2a342df6acf177ad726))

## [2.21.0](https://github.com/ellite/Wallos/compare/v2.20.1...v2.21.0) (2024-08-06)


### Features

* add option to list disabled subscriptions at the bottom ([3281f0c](https://github.com/ellite/Wallos/commit/3281f0ce35fbea237e21221d3a9026ed96ad84e5))
* notification for wallos version updates ([3281f0c](https://github.com/ellite/Wallos/commit/3281f0ce35fbea237e21221d3a9026ed96ad84e5))

## [2.20.1](https://github.com/ellite/Wallos/compare/v2.20.0...v2.20.1) (2024-07-29)


### Bug Fixes

* allow usernames with capital letters ([f241ba2](https://github.com/ellite/Wallos/commit/f241ba23018ee910ab859b2ce860b4c0678d6402))
* use 2 decimal places for price on the calendar ([f241ba2](https://github.com/ellite/Wallos/commit/f241ba23018ee910ab859b2ce860b4c0678d6402))
* use 2 decimal places for price when exporting ical in the calendar ([f241ba2](https://github.com/ellite/Wallos/commit/f241ba23018ee910ab859b2ce860b4c0678d6402))

## [2.20.0](https://github.com/ellite/Wallos/compare/v2.19.3...v2.20.0) (2024-07-19)


### Features

* export subscriptions as csv ([8f1e155](https://github.com/ellite/Wallos/commit/8f1e1554787c6e3ffaf7e73369a66794c0636713))
* export subscriptions as json ([8f1e155](https://github.com/ellite/Wallos/commit/8f1e1554787c6e3ffaf7e73369a66794c0636713))
* user can delete their own account ([8f1e155](https://github.com/ellite/Wallos/commit/8f1e1554787c6e3ffaf7e73369a66794c0636713))

## [2.19.3](https://github.com/ellite/Wallos/compare/v2.19.2...v2.19.3) (2024-07-15)


### Bug Fixes

* delete button on subscription form ([#460](https://github.com/ellite/Wallos/issues/460)) ([8cb4355](https://github.com/ellite/Wallos/commit/8cb43553fd2d3328fe9b1f7c5986e040071844c0))

## [2.19.2](https://github.com/ellite/Wallos/compare/v2.19.1...v2.19.2) (2024-07-15)


### Bug Fixes

* test ntfy without custom headers ([#456](https://github.com/ellite/Wallos/issues/456)) ([8fcfc92](https://github.com/ellite/Wallos/commit/8fcfc9264726ec1ded81ca2c51daa65ae9f4e7d8))

## [2.19.1](https://github.com/ellite/Wallos/compare/v2.19.0...v2.19.1) (2024-07-14)


### Bug Fixes

* unset sortOrder var ([a1fab4d](https://github.com/ellite/Wallos/commit/a1fab4dd1067f80054a2c52710edb859dba47127))

## [2.19.0](https://github.com/ellite/Wallos/compare/v2.18.0...v2.19.0) (2024-07-14)


### Features

* add alphanumeric sort order for subscriptions ([#449](https://github.com/ellite/Wallos/issues/449)) ([775e6ee](https://github.com/ellite/Wallos/commit/775e6ee39457edef420d5c36fb310a75fd47bff6))

## [2.18.0](https://github.com/ellite/Wallos/compare/v2.17.0...v2.18.0) (2024-07-14)


### Features

* disable display options checkbox when fixer key is not set ([5f10525](https://github.com/ellite/Wallos/commit/5f1052584b5ece93ebdcb5bce32210e2643a9f26))
* display error message on the statistics page when the fixer key is needed but is missing ([5f10525](https://github.com/ellite/Wallos/commit/5f1052584b5ece93ebdcb5bce32210e2643a9f26))

## [2.17.0](https://github.com/ellite/Wallos/compare/v2.16.1...v2.17.0) (2024-07-11)


### Features

* add filter and sort dashboard by subscription state ([afff992](https://github.com/ellite/Wallos/commit/afff992878287fdc51229297c455d1f69216c36e))


### Bug Fixes

* use the same font for inputs ([a539058](https://github.com/ellite/Wallos/commit/a5390580259105f14154b0d7ce1eb13631c471b1))

## [2.16.1](https://github.com/ellite/Wallos/compare/v2.16.0...v2.16.1) (2024-07-10)


### Bug Fixes

* error when logos folder is empty ([#439](https://github.com/ellite/Wallos/issues/439)) ([e2e5061](https://github.com/ellite/Wallos/commit/e2e5061d1506652384ceed018aa4330b8548b792))

## [2.16.0](https://github.com/ellite/Wallos/compare/v2.15.0...v2.16.0) (2024-07-10)


### Features

* add calendar to pwa shortcuts ([21ebf29](https://github.com/ellite/Wallos/commit/21ebf29f11405ab24b1b0ffd16eb667de4dfc189))
* change apple touch icon ([21ebf29](https://github.com/ellite/Wallos/commit/21ebf29f11405ab24b1b0ffd16eb667de4dfc189))

## [2.15.0](https://github.com/ellite/Wallos/compare/v2.14.2...v2.15.0) (2024-07-09)


### Features

* add maintenance tasks to admin page ([9f7f47b](https://github.com/ellite/Wallos/commit/9f7f47b5d1be2697c2c612bfddb6119c63a3d517))
* add support to upload svg logos ([9f7f47b](https://github.com/ellite/Wallos/commit/9f7f47b5d1be2697c2c612bfddb6119c63a3d517))

## [2.14.2](https://github.com/ellite/Wallos/compare/v2.14.1...v2.14.2) (2024-07-08)


### Bug Fixes

* broken subscription update query ([#431](https://github.com/ellite/Wallos/issues/431)) ([b00a985](https://github.com/ellite/Wallos/commit/b00a9855453663aeb2f1f4b7f0db3aca3994b12b))

## [2.14.1](https://github.com/ellite/Wallos/compare/v2.14.0...v2.14.1) (2024-07-05)


### Bug Fixes

* dashboard scrolling to top when opening a subscription ([#427](https://github.com/ellite/Wallos/issues/427)) ([cb03af8](https://github.com/ellite/Wallos/commit/cb03af8e46fb5ec5138ed7ef729f4b56a23d2b37))

## [2.14.0](https://github.com/ellite/Wallos/compare/v2.13.0...v2.14.0) (2024-07-05)


### Features

* add cancelation reminders ([#425](https://github.com/ellite/Wallos/issues/425)) ([c393146](https://github.com/ellite/Wallos/commit/c393146d9e3d494943de32ecd86983335358cf88))

## [2.13.0](https://github.com/ellite/Wallos/compare/v2.12.0...v2.13.0) (2024-07-04)


### Features

* uniformize layout and styles (+ checkboxes and radios) ([#423](https://github.com/ellite/Wallos/issues/423)) ([c166c7e](https://github.com/ellite/Wallos/commit/c166c7e84c06ceba5ab21341c8d56bd1aaf042ec))

## [2.12.0](https://github.com/ellite/Wallos/compare/v2.11.2...v2.12.0) (2024-07-03)


### Features

* ability to add custom css styles ([50bd104](https://github.com/ellite/Wallos/commit/50bd104b5b990605f457b540bec95eff5034473d))
* cache logos for offline use ([50bd104](https://github.com/ellite/Wallos/commit/50bd104b5b990605f457b540bec95eff5034473d))
* more uniform and aligned styles on the settings page ([50bd104](https://github.com/ellite/Wallos/commit/50bd104b5b990605f457b540bec95eff5034473d))
* rework styles of theme section on settings page ([50bd104](https://github.com/ellite/Wallos/commit/50bd104b5b990605f457b540bec95eff5034473d))


### Bug Fixes

* don't allow saving main and accent colors if they're the same ([50bd104](https://github.com/ellite/Wallos/commit/50bd104b5b990605f457b540bec95eff5034473d))

## [2.11.2](https://github.com/ellite/Wallos/compare/v2.11.1...v2.11.2) (2024-07-02)


### Bug Fixes

* menus checkmark position ([#419](https://github.com/ellite/Wallos/issues/419)) ([4da5d47](https://github.com/ellite/Wallos/commit/4da5d47e3ce8b8564921c07e7b785a367d378d6b))

## [2.11.1](https://github.com/ellite/Wallos/compare/v2.11.0...v2.11.1) (2024-06-30)


### Bug Fixes

* syntax error on svg logo ([#417](https://github.com/ellite/Wallos/issues/417)) ([b82f750](https://github.com/ellite/Wallos/commit/b82f750c8e844012a8a12e33f01719f42199e7ce))

## [2.11.0](https://github.com/ellite/Wallos/compare/v2.10.0...v2.11.0) (2024-06-30)


### Features

* theming engine custom colors now affect icons as well ([83e2066](https://github.com/ellite/Wallos/commit/83e2066e7bee99a152cc3c22f5b1dd9c9866c9fd))

## [2.10.0](https://github.com/ellite/Wallos/compare/v2.9.0...v2.10.0) (2024-06-27)


### Features

* add purple theme ([4d74c04](https://github.com/ellite/Wallos/commit/4d74c04f0e5bab5e1ece7a4a666f14d4a221fba6))


### Bug Fixes

* file name on ics export for subscriptions with non-ascii characters ([4d74c04](https://github.com/ellite/Wallos/commit/4d74c04f0e5bab5e1ece7a4a666f14d4a221fba6))

## [2.9.0](https://github.com/ellite/Wallos/compare/v2.8.0...v2.9.0) (2024-06-26)


### Features

* create users from the admin page ([#409](https://github.com/ellite/Wallos/issues/409)) ([6d2ffa6](https://github.com/ellite/Wallos/commit/6d2ffa6312b05f308117f2686681e2fcfaf734ec))

## [2.8.0](https://github.com/ellite/Wallos/compare/v2.7.0...v2.8.0) (2024-06-26)


### Features

* also show previous payments on the calendar for the current month ([c2e85d6](https://github.com/ellite/Wallos/commit/c2e85d6e109d9d07cc2fdbcb09b51564d1f73341))
* support automatic dark mode ([c2e85d6](https://github.com/ellite/Wallos/commit/c2e85d6e109d9d07cc2fdbcb09b51564d1f73341))


### Bug Fixes

* not every payment cycle was shown on the calendar ([c2e85d6](https://github.com/ellite/Wallos/commit/c2e85d6e109d9d07cc2fdbcb09b51564d1f73341))

## [2.7.0](https://github.com/ellite/Wallos/compare/v2.6.1...v2.7.0) (2024-06-25)


### Features

* export subscription as ics from the calendar view ([#404](https://github.com/ellite/Wallos/issues/404)) ([f1360f7](https://github.com/ellite/Wallos/commit/f1360f7d468ef5ae7e974ec1f9bb77831ea322bb))

## [2.6.1](https://github.com/ellite/Wallos/compare/v2.6.0...v2.6.1) (2024-06-25)


### Bug Fixes

* load php calendar extension ([#402](https://github.com/ellite/Wallos/issues/402)) ([c02ac77](https://github.com/ellite/Wallos/commit/c02ac770d7ac9fad1baec526b5d7dd71deaba59b))

## [2.6.0](https://github.com/ellite/Wallos/compare/v2.5.2...v2.6.0) (2024-06-25)


### Features

* add calendar view ([#399](https://github.com/ellite/Wallos/issues/399)) ([369f1a2](https://github.com/ellite/Wallos/commit/369f1a2bdcd9bdf3996b3dc8de8921f8954a069d))

## [2.5.2](https://github.com/ellite/Wallos/compare/v2.5.1...v2.5.2) (2024-06-24)


### Bug Fixes

* add ability to run container as an arbitrary user ([#396](https://github.com/ellite/Wallos/issues/396)) ([86fe2f3](https://github.com/ellite/Wallos/commit/86fe2f3ebb9c38ac34eaccd144a9550b7b314138))

## [2.5.1](https://github.com/ellite/Wallos/compare/v2.5.0...v2.5.1) (2024-06-21)


### Bug Fixes

* ntfy notifications ([#394](https://github.com/ellite/Wallos/issues/394)) ([17722c3](https://github.com/ellite/Wallos/commit/17722c31e31eec035d8896566e9eb5596951d022))

## [2.5.0](https://github.com/ellite/Wallos/compare/v2.4.2...v2.5.0) (2024-06-21)


### Features

* add option to clone subscription ([8304ed7](https://github.com/ellite/Wallos/commit/8304ed7b54f50ed7fa5ab520ff4d8d54f3ef34df))
* edit and delete options now available directly on the subscription list ([8304ed7](https://github.com/ellite/Wallos/commit/8304ed7b54f50ed7fa5ab520ff4d8d54f3ef34df))


### Bug Fixes

* typo on webhook payload ([8304ed7](https://github.com/ellite/Wallos/commit/8304ed7b54f50ed7fa5ab520ff4d8d54f3ef34df))

## [2.4.2](https://github.com/ellite/Wallos/compare/v2.4.1...v2.4.2) (2024-06-10)


### Bug Fixes

* update exchange cron only working for one user ([#384](https://github.com/ellite/Wallos/issues/384)) ([815eea7](https://github.com/ellite/Wallos/commit/815eea7e7be37e068e6173c229eb285ed8b7c30d))

## [2.4.1](https://github.com/ellite/Wallos/compare/v2.4.0...v2.4.1) (2024-06-09)


### Bug Fixes

* cronjob exchange update would not work with apilayer ([#381](https://github.com/ellite/Wallos/issues/381)) ([b0b4b7a](https://github.com/ellite/Wallos/commit/b0b4b7a65cd479e7532e72e826d3c01aead403c3))

## [2.4.0](https://github.com/ellite/Wallos/compare/v2.3.0...v2.4.0) (2024-06-07)


### Features

* add hability to disable login ([#378](https://github.com/ellite/Wallos/issues/378)) ([092be22](https://github.com/ellite/Wallos/commit/092be22183359f714fc9638d9013b742da828ed6))

## [2.3.0](https://github.com/ellite/Wallos/compare/v2.2.0...v2.3.0) (2024-06-05)


### Features

* add ntfy as notification method ([#377](https://github.com/ellite/Wallos/issues/377)) ([65edf09](https://github.com/ellite/Wallos/commit/65edf0963b73deff0f0f7f04427e69ce335bd776))


### Bug Fixes

* custom headers for webhook notifications ([#375](https://github.com/ellite/Wallos/issues/375)) ([7217088](https://github.com/ellite/Wallos/commit/7217088bb0732735a65322bce136d7d556b1acf3))

## [2.2.0](https://github.com/ellite/Wallos/compare/v2.1.0...v2.2.0) (2024-06-04)


### Features

* change filename of backup file ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))
* frequency is now up to 366 ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))


### Bug Fixes

* add webp support to gd on the container ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))
* translate: "no category" ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))
* trim fixer api key ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))
* update slovanian translations ([fa99a73](https://github.com/ellite/Wallos/commit/fa99a735cd23918bab95baaf13b7a3142946d4b2))

## [2.1.0](https://github.com/ellite/Wallos/compare/v2.0.0...v2.1.0) (2024-05-27)


### Features

* add slovenian translation ([03ceb8a](https://github.com/ellite/Wallos/commit/03ceb8a6e64c8cd4deb4019668fbf98acb57c5fe))


### Bug Fixes

* currency conversion failing on the statistics page ([03ceb8a](https://github.com/ellite/Wallos/commit/03ceb8a6e64c8cd4deb4019668fbf98acb57c5fe))

## [2.0.0](https://github.com/ellite/Wallos/compare/v1.29.1...v2.0.0) (2024-05-26)


### ⚠ BREAKING CHANGES

* allow registration of multiple users ([#340](https://github.com/ellite/Wallos/issues/340))

### Features

* add reset password functionality ([e1006e5](https://github.com/ellite/Wallos/commit/e1006e582388a7fab204f25c100347607b863e4e))
* administration area ([e1006e5](https://github.com/ellite/Wallos/commit/e1006e582388a7fab204f25c100347607b863e4e))
* allow registration of multiple users ([#340](https://github.com/ellite/Wallos/issues/340)) ([e1006e5](https://github.com/ellite/Wallos/commit/e1006e582388a7fab204f25c100347607b863e4e))

## [1.29.1](https://github.com/ellite/Wallos/compare/v1.29.0...v1.29.1) (2024-05-20)


### Bug Fixes

* calling htmlspecialchars_decode on null objects ([#338](https://github.com/ellite/Wallos/issues/338)) ([5050a28](https://github.com/ellite/Wallos/commit/5050a28f0e64e8c1eefb4f7cca8f6f6e473177e3))

## [1.29.0](https://github.com/ellite/Wallos/compare/v1.28.0...v1.29.0) (2024-05-20)


### Features

* subscriptions have personalized notification times ([#334](https://github.com/ellite/Wallos/issues/334)) ([c7146df](https://github.com/ellite/Wallos/commit/c7146dfd08c2a60d4ff6f7ac1f7cf5830fe28d9c))

## [1.28.0](https://github.com/ellite/Wallos/compare/v1.27.2...v1.28.0) (2024-05-17)


### Features

* add monthly budget field and statistics ([#329](https://github.com/ellite/Wallos/issues/329)) ([b622434](https://github.com/ellite/Wallos/commit/b622434ca0791d5c8026d641e1b32f8a2f0f42b8))

## [1.27.2](https://github.com/ellite/Wallos/compare/v1.27.1...v1.27.2) (2024-05-17)


### Bug Fixes

* duplicated messages on discord notifications ([d44b40b](https://github.com/ellite/Wallos/commit/d44b40b0ce80e91821fe7441c85e0d8794680618))
* possible division by 0 on statistics page ([d44b40b](https://github.com/ellite/Wallos/commit/d44b40b0ce80e91821fe7441c85e0d8794680618))

## [1.27.1](https://github.com/ellite/Wallos/compare/v1.27.0...v1.27.1) (2024-05-13)


### Bug Fixes

* import of translations for cronjobs was missing ([#321](https://github.com/ellite/Wallos/issues/321)) ([a524419](https://github.com/ellite/Wallos/commit/a524419e0a468147a2094dba81689dd643a0108b))

## [1.27.0](https://github.com/ellite/Wallos/compare/v1.26.2...v1.27.0) (2024-05-11)


### Features

* add korean translation ([#314](https://github.com/ellite/Wallos/issues/314)) ([bc40320](https://github.com/ellite/Wallos/commit/bc403206905b39c3aa88f3eb51e59b41e2a5e24e))

## [1.26.2](https://github.com/ellite/Wallos/compare/v1.26.1...v1.26.2) (2024-05-09)


### Bug Fixes

* russian translations ([#309](https://github.com/ellite/Wallos/issues/309)) ([8f890fc](https://github.com/ellite/Wallos/commit/8f890fc5d3a62a91feec50564179b3241ed538bf))

## [1.26.1](https://github.com/ellite/Wallos/compare/v1.26.0...v1.26.1) (2024-05-09)


### Bug Fixes

* background removal experimental setting ([#307](https://github.com/ellite/Wallos/issues/307)) ([bb5ee2e](https://github.com/ellite/Wallos/commit/bb5ee2e64c11b1415da3aa50119dfaa3783be37f))

## [1.26.0](https://github.com/ellite/Wallos/compare/v1.25.1...v1.26.0) (2024-05-08)


### Features

* add russian translation ([#305](https://github.com/ellite/Wallos/issues/305)) ([ae04d50](https://github.com/ellite/Wallos/commit/ae04d50329c1fb0117e186f89fef38b495cbbe9c))

## [1.25.1](https://github.com/ellite/Wallos/compare/v1.25.0...v1.25.1) (2024-05-07)


### Bug Fixes

* broken discord form ([#302](https://github.com/ellite/Wallos/issues/302)) ([b435d6a](https://github.com/ellite/Wallos/commit/b435d6a5cf6f80404c487b519334b2854aab9713))

## [1.25.0](https://github.com/ellite/Wallos/compare/v1.24.0...v1.25.0) (2024-05-06)


### Features

* add discord and pushover as notification agents ([#300](https://github.com/ellite/Wallos/issues/300)) ([8994829](https://github.com/ellite/Wallos/commit/899482982e7e200f5a7081ed6285475e5cb2a37d))


### Bug Fixes

* most error messages of the notifications endpoints would not reach the frontend ([8994829](https://github.com/ellite/Wallos/commit/899482982e7e200f5a7081ed6285475e5cb2a37d))

## [1.24.0](https://github.com/ellite/Wallos/compare/v1.23.0...v1.24.0) (2024-05-05)


### Features

* add new notification methods (telegram, webhooks, gotify) ([#295](https://github.com/ellite/Wallos/issues/295)) ([a408031](https://github.com/ellite/Wallos/commit/a408031ef8711bf87e9f8db35f52c498f250b235))

## [1.23.0](https://github.com/ellite/Wallos/compare/v1.22.0...v1.23.0) (2024-04-26)


### Features

* backup and restore ([#288](https://github.com/ellite/Wallos/issues/288)) ([7b509d2](https://github.com/ellite/Wallos/commit/7b509d2b3d769e14a9cb4fd183395dcecc9d993b))

## [1.22.0](https://github.com/ellite/Wallos/compare/v1.21.1...v1.22.0) (2024-04-20)


### Features

* option to hide disabled subscriptions ([#286](https://github.com/ellite/Wallos/issues/286)) ([b80ab4b](https://github.com/ellite/Wallos/commit/b80ab4bdc662c3e80a2fd42b8b286b69beac441c))

## [1.21.1](https://github.com/ellite/Wallos/compare/v1.21.0...v1.21.1) (2024-04-19)


### Bug Fixes

* small layout issues ([769f8a0](https://github.com/ellite/Wallos/commit/769f8a0587941bffd0d7463b7e7ffeb38a70e301))

## [1.21.0](https://github.com/ellite/Wallos/compare/v1.20.2...v1.21.0) (2024-04-19)


### Features

* add italian translation ([70e4234](https://github.com/ellite/Wallos/commit/70e42349caee5d6647b6b704643fe2b5e26dff4e))
* add themes and custom color options ([70e4234](https://github.com/ellite/Wallos/commit/70e42349caee5d6647b6b704643fe2b5e26dff4e))

## [1.20.2](https://github.com/ellite/Wallos/compare/v1.20.1...v1.20.2) (2024-04-11)


### Bug Fixes

* encoding for url and notes ([#273](https://github.com/ellite/Wallos/issues/273)) ([ad86eb5](https://github.com/ellite/Wallos/commit/ad86eb5b9c6e60004de2795170032d62b33ddcfb))

## [1.20.1](https://github.com/ellite/Wallos/compare/v1.20.0...v1.20.1) (2024-04-09)


### Bug Fixes

* special chars in subscriptions ([#271](https://github.com/ellite/Wallos/issues/271)) ([2683a7c](https://github.com/ellite/Wallos/commit/2683a7c4ba3c3575347d48f2c97b92b2ff0cc9f9))

## [1.20.0](https://github.com/ellite/Wallos/compare/v1.19.0...v1.20.0) (2024-04-07)


### Features

* add serbian translation ([#268](https://github.com/ellite/Wallos/issues/268)) ([55089c0](https://github.com/ellite/Wallos/commit/55089c0715ca315feb6a8795b07d9c36167494de))

## [1.19.0](https://github.com/ellite/Wallos/compare/v1.18.3...v1.19.0) (2024-04-03)


### Features

* add polish translation ([#263](https://github.com/ellite/Wallos/issues/263)) ([c752761](https://github.com/ellite/Wallos/commit/c7527610fafa49b18076971befa246b2730b79c4))

## [1.18.3](https://github.com/ellite/Wallos/compare/v1.18.2...v1.18.3) (2024-03-30)


### Bug Fixes

* on initial registration page, logo can be cut off ([#258](https://github.com/ellite/Wallos/issues/258)) ([dde8695](https://github.com/ellite/Wallos/commit/dde8695fb555f483ef8bc8f24db2a610301bab16))

## [1.18.2](https://github.com/ellite/Wallos/compare/v1.18.1...v1.18.2) (2024-03-28)


### Bug Fixes

* small icon size for payment icons ([#253](https://github.com/ellite/Wallos/issues/253)) ([8998e23](https://github.com/ellite/Wallos/commit/8998e23d370165ca158600550dbf0eb8c07d4bac))

## [1.18.1](https://github.com/ellite/Wallos/compare/v1.18.0...v1.18.1) (2024-03-25)


### Bug Fixes

* disabled inputs on dark theme ([#250](https://github.com/ellite/Wallos/issues/250)) ([11f0e7c](https://github.com/ellite/Wallos/commit/11f0e7ce63f37adb922e530a54f3e5cc9f640eee))

## [1.18.0](https://github.com/ellite/Wallos/compare/v1.17.3...v1.18.0) (2024-03-24)


### Features

* add custom avatar functionality ([#248](https://github.com/ellite/Wallos/issues/248)) ([1dbebd3](https://github.com/ellite/Wallos/commit/1dbebd3918ef6f27961f4e70b6ad007133f8ff93))

## [1.17.3](https://github.com/ellite/Wallos/compare/v1.17.2...v1.17.3) (2024-03-20)


### Bug Fixes

* next payment date not updating for disabled subscriptions ([#243](https://github.com/ellite/Wallos/issues/243)) ([75a5672](https://github.com/ellite/Wallos/commit/75a5672de32a59cc53c3c76a08793e6a33cce828))

## [1.17.2](https://github.com/ellite/Wallos/compare/v1.17.1...v1.17.2) (2024-03-18)


### Bug Fixes

* pwa not loading static files when offline ([#241](https://github.com/ellite/Wallos/issues/241)) ([4e3376d](https://github.com/ellite/Wallos/commit/4e3376df93ea7c2b3e184b2670ebe77fe9b15d6a))

## [1.17.1](https://github.com/ellite/Wallos/compare/v1.17.0...v1.17.1) (2024-03-18)


### Bug Fixes

* cronjobs running twice ([#239](https://github.com/ellite/Wallos/issues/239)) ([00cbf8d](https://github.com/ellite/Wallos/commit/00cbf8d9e3feac87292630f8db4571a99b542db4))

## [1.17.0](https://github.com/ellite/Wallos/compare/v1.16.3...v1.17.0) (2024-03-17)


### Features

* allow selecting tls or ssl for email notifications ([#237](https://github.com/ellite/Wallos/issues/237)) ([2462435](https://github.com/ellite/Wallos/commit/246243574328ead6d95d45b81b055761b01040a7))

## [1.16.3](https://github.com/ellite/Wallos/compare/v1.16.2...v1.16.3) (2024-03-17)


### Bug Fixes

* allow redirects on logo search ([ae73db7](https://github.com/ellite/Wallos/commit/ae73db77907786993f52f7273145dafa660c4d36))
* rename category after adding and sort order of categories ([ae73db7](https://github.com/ellite/Wallos/commit/ae73db77907786993f52f7273145dafa660c4d36))

## [1.16.2](https://github.com/ellite/Wallos/compare/v1.16.1...v1.16.2) (2024-03-13)


### Bug Fixes

* wrong folder for payment method logos ([#227](https://github.com/ellite/Wallos/issues/227)) ([f6c1ff2](https://github.com/ellite/Wallos/commit/f6c1ff2a6be6545c6c179722235db3cd724127fd))

## [1.16.1](https://github.com/ellite/Wallos/compare/v1.16.0...v1.16.1) (2024-03-12)


### Bug Fixes

* confusing wording for billing cycle ([94ad0cb](https://github.com/ellite/Wallos/commit/94ad0cb553d7f05b15e9ab27fbf4c26955fc3ff1))

## [1.16.0](https://github.com/ellite/Wallos/compare/v1.15.3...v1.16.0) (2024-03-10)


### Features

* allow sorting payment methods ([#217](https://github.com/ellite/Wallos/issues/217)) ([aef2d13](https://github.com/ellite/Wallos/commit/aef2d134c22f7dc95821ff711f7bca56228bfed6))
* don't allow to change currency code if in use ([aef2d13](https://github.com/ellite/Wallos/commit/aef2d134c22f7dc95821ff711f7bca56228bfed6))

## [1.15.3](https://github.com/ellite/Wallos/compare/v1.15.2...v1.15.3) (2024-03-10)


### Bug Fixes

* sql injection vulnerability when using filters ([#214](https://github.com/ellite/Wallos/issues/214)) ([cbdc188](https://github.com/ellite/Wallos/commit/cbdc188e5e7a2c357f5b0bcaeaf2e886cd2555e3))

## [1.15.2](https://github.com/ellite/Wallos/compare/v1.15.1...v1.15.2) (2024-03-09)


### Bug Fixes

* undefined var on the statistics page ([#211](https://github.com/ellite/Wallos/issues/211)) ([8b7a7b9](https://github.com/ellite/Wallos/commit/8b7a7b94e3ae9177be6d067d8fee0a05aa428f4a))

## [1.15.1](https://github.com/ellite/Wallos/compare/v1.15.0...v1.15.1) (2024-03-09)


### Bug Fixes

* undefined var if sort cookie is not set ([#207](https://github.com/ellite/Wallos/issues/207)) ([288c106](https://github.com/ellite/Wallos/commit/288c10624592aa04cc76cb8ae066331d65964650))

## [1.15.0](https://github.com/ellite/Wallos/compare/v1.14.1...v1.15.0) (2024-03-09)


### Features

* filters on the subscriptions page ([a396285](https://github.com/ellite/Wallos/commit/a396285b76cd87e598495f311a81dc68a7f66d36))
* search subscriptions by name ([a396285](https://github.com/ellite/Wallos/commit/a396285b76cd87e598495f311a81dc68a7f66d36))

## [1.14.1](https://github.com/ellite/Wallos/compare/v1.14.0...v1.14.1) (2024-03-08)


### Bug Fixes

* wrong message when deleting payment methods ([#202](https://github.com/ellite/Wallos/issues/202)) ([93a3d18](https://github.com/ellite/Wallos/commit/93a3d189794985c1d8cfd5558c482f66e79405a8))

## [1.14.0](https://github.com/ellite/Wallos/compare/v1.13.0...v1.14.0) (2024-03-08)


### Features

* add brazilian portuguese to available languages ([#198](https://github.com/ellite/Wallos/issues/198)) ([3ea9d98](https://github.com/ellite/Wallos/commit/3ea9d98da79e9b13ab9d93a56b89062ac19c31d7))

## [1.13.0](https://github.com/ellite/Wallos/compare/v1.12.1...v1.13.0) (2024-03-07)


### Features

* show name of most expensive subscription on statistics ([#194](https://github.com/ellite/Wallos/issues/194)) ([ede08b1](https://github.com/ellite/Wallos/commit/ede08b1f6ae2d52ac0f8e1aaa77edc1924f529ce))

## [1.12.1](https://github.com/ellite/Wallos/compare/v1.12.0...v1.12.1) (2024-03-06)


### Bug Fixes

* broken chinese language file ([#192](https://github.com/ellite/Wallos/issues/192)) ([94c1a91](https://github.com/ellite/Wallos/commit/94c1a91387ca05fad3a50e5f318d8439c7608cbe))

## [1.12.0](https://github.com/ellite/Wallos/compare/v1.11.3...v1.12.0) (2024-03-05)


### Features

* add filters to statistics page ([83234ab](https://github.com/ellite/Wallos/commit/83234ab8cd184f4693a148dc55bddef300c49e71))
* allow deletion of the default payment methods ([83234ab](https://github.com/ellite/Wallos/commit/83234ab8cd184f4693a148dc55bddef300c49e71))
* allow renaming / translation of payment methods ([83234ab](https://github.com/ellite/Wallos/commit/83234ab8cd184f4693a148dc55bddef300c49e71))
* allow sorting of categories in settings ([83234ab](https://github.com/ellite/Wallos/commit/83234ab8cd184f4693a148dc55bddef300c49e71))

## [1.11.3](https://github.com/ellite/Wallos/compare/v1.11.2...v1.11.3) (2024-03-02)


### Bug Fixes

* redirects with the service worker ([#183](https://github.com/ellite/Wallos/issues/183)) ([940bbbe](https://github.com/ellite/Wallos/commit/940bbbea9071a7c2687a3340bb8e9d6f4f884cc1))

## [1.11.2](https://github.com/ellite/Wallos/compare/v1.11.1...v1.11.2) (2024-03-02)


### Bug Fixes

* file upload bypass vulnerability ([#181](https://github.com/ellite/Wallos/issues/181)) ([0f7853f](https://github.com/ellite/Wallos/commit/0f7853f961ba2f68f8dcd358acaad6c6eb7980e6))

## [1.11.1](https://github.com/ellite/Wallos/compare/v1.11.0...v1.11.1) (2024-03-01)


### Bug Fixes

* security issue with image upload ([#175](https://github.com/ellite/Wallos/issues/175)) ([7b5e166](https://github.com/ellite/Wallos/commit/7b5e166e289f32b1b3451614b16e1f4c0b9d6f2a))

## [1.11.0](https://github.com/ellite/Wallos/compare/v1.10.0...v1.11.0) (2024-03-01)


### Features

* added custom payment methods ([#173](https://github.com/ellite/Wallos/issues/173)) ([e739622](https://github.com/ellite/Wallos/commit/e73962260678caf0843b6302f7fbb7d49469a1a9))

## [1.10.0](https://github.com/ellite/Wallos/compare/v1.9.1...v1.10.0) (2024-02-29)


### Features

* use brave search for the logos if google fails ([#169](https://github.com/ellite/Wallos/issues/169)) ([fff783e](https://github.com/ellite/Wallos/commit/fff783e4e87f04199817c7cb3b4bd28760d2b5f3))

## [1.9.1](https://github.com/ellite/Wallos/compare/v1.9.0...v1.9.1) (2024-02-28)


### Bug Fixes

* move display settings to the bottom ([ec25d4b](https://github.com/ellite/Wallos/commit/ec25d4bc5a35f68ff15d456ae6a1d3e98d124f5f))
* reorder subscription form ([ec25d4b](https://github.com/ellite/Wallos/commit/ec25d4bc5a35f68ff15d456ae6a1d3e98d124f5f))
* show email field on adding household member ([ec25d4b](https://github.com/ellite/Wallos/commit/ec25d4bc5a35f68ff15d456ae6a1d3e98d124f5f))

## [1.9.0](https://github.com/ellite/Wallos/compare/v1.8.3...v1.9.0) (2024-02-27)


### Features

* enable progressive web app ([a2a315e](https://github.com/ellite/Wallos/commit/a2a315e34dca2562bc11793cc5841c2082e811a9))


### Bug Fixes

* update packages to fix vulnerabilities ([a2a315e](https://github.com/ellite/Wallos/commit/a2a315e34dca2562bc11793cc5841c2082e811a9))

## [1.8.3](https://github.com/ellite/Wallos/compare/v1.8.2...v1.8.3) (2024-02-26)


### Bug Fixes

* remove service worker ([#157](https://github.com/ellite/Wallos/issues/157)) ([5ccadce](https://github.com/ellite/Wallos/commit/5ccadce2f139e5873889badc51a67bfaef8a9304))

## [1.8.2](https://github.com/ellite/Wallos/compare/v1.8.1...v1.8.2) (2024-02-26)


### Bug Fixes

* service worker redirect not set to follow ([3640b54](https://github.com/ellite/Wallos/commit/3640b547ee3ca28e7b872b9e2dbbcd1d31c54953))

## [1.8.1](https://github.com/ellite/Wallos/compare/v1.8.0...v1.8.1) (2024-02-26)


### Bug Fixes

* service worker has redirections ([4aca7bc](https://github.com/ellite/Wallos/commit/4aca7bcb3cdbb77958db8783c4f088df131db645))

## [1.8.0](https://github.com/ellite/Wallos/compare/v1.7.0...v1.8.0) (2024-02-26)


### Features

* convert wallos into a progressive web app ([#151](https://github.com/ellite/Wallos/issues/151)) ([19e2058](https://github.com/ellite/Wallos/commit/19e205897617ee894d8802f7e73fef46be386c30))


### Bug Fixes

* improve traditional chinese translations ([19e2058](https://github.com/ellite/Wallos/commit/19e205897617ee894d8802f7e73fef46be386c30))

## [1.7.0](https://github.com/ellite/Wallos/compare/v1.6.0...v1.7.0) (2024-02-25)


### Features

* add email for notifications to household members ([26363dd](https://github.com/ellite/Wallos/commit/26363dd5f364b5494c526a9769626b03bba45273))

## [1.6.0](https://github.com/ellite/Wallos/compare/v1.5.0...v1.6.0) (2024-02-24)


### Features

* add stats about inactive subscriptions ([#146](https://github.com/ellite/Wallos/issues/146)) ([ccac17a](https://github.com/ellite/Wallos/commit/ccac17a6f222cb1ee022fd30b7a1d34306dd0de2))
* sort disabled subscription at the bottom ([ccac17a](https://github.com/ellite/Wallos/commit/ccac17a6f222cb1ee022fd30b7a1d34306dd0de2))

## [1.5.0](https://github.com/ellite/Wallos/compare/v1.4.1...v1.5.0) (2024-02-23)


### Features

* allow to disable subscriptions ([#144](https://github.com/ellite/Wallos/issues/144)) ([50056d9](https://github.com/ellite/Wallos/commit/50056d9f03a46c166650474b3877b55a24873bb9))

## [1.4.1](https://github.com/ellite/Wallos/compare/v1.4.0...v1.4.1) (2024-02-22)


### Bug Fixes

* bug on saving fixer api key ([#142](https://github.com/ellite/Wallos/issues/142)) ([866eb28](https://github.com/ellite/Wallos/commit/866eb28e88495e851336b5e224274a823ff4173d))

## [1.4.0](https://github.com/ellite/Wallos/compare/v1.3.1...v1.4.0) (2024-02-21)


### Features

* persist display and experimental settings on the db ([f0a6f1a](https://github.com/ellite/Wallos/commit/f0a6f1a2f18b329c9f784a9f1953cd0e7616e1c6))
* small styles changed ([f0a6f1a](https://github.com/ellite/Wallos/commit/f0a6f1a2f18b329c9f784a9f1953cd0e7616e1c6))

## [1.3.1](https://github.com/ellite/Wallos/compare/v1.3.0...v1.3.1) (2024-02-20)


### Bug Fixes

* missing authentication check ([#133](https://github.com/ellite/Wallos/issues/133)) ([b887d3a](https://github.com/ellite/Wallos/commit/b887d3a0503585dadde4b1b59b023c981b0f7f66))

## [1.3.0](https://github.com/ellite/Wallos/compare/v1.2.0...v1.3.0) (2024-02-19)


### Features

* add apilayer as provider for fixer api ([0f19dd6](https://github.com/ellite/Wallos/commit/0f19dd688fe3a2156e7d26d1bf1e1f8b30ce79ad))
* add apilayer as provider for fixer api ([#127](https://github.com/ellite/Wallos/issues/127)) ([0f19dd6](https://github.com/ellite/Wallos/commit/0f19dd688fe3a2156e7d26d1bf1e1f8b30ce79ad))
* update exchange rate when saving api key ([0f19dd6](https://github.com/ellite/Wallos/commit/0f19dd688fe3a2156e7d26d1bf1e1f8b30ce79ad))

## [1.2.0](https://github.com/ellite/Wallos/compare/v1.1.0...v1.2.0) (2024-02-19)


### Features

* enable deployment in subdirectory ([e2af9af](https://github.com/ellite/Wallos/commit/e2af9afc32bfc248f594336c50d44ad6f36f197e))

## [1.1.0](https://github.com/ellite/Wallos/compare/v1.0.1...v1.1.0) (2024-02-18)


### Features

* new statistics per payment method ([#124](https://github.com/ellite/Wallos/issues/124)) ([6200fa5](https://github.com/ellite/Wallos/commit/6200fa5e87d3f60853c3d8b95f5d676e39b378f4))

## [1.0.1](https://github.com/ellite/Wallos/compare/v1.0.0...v1.0.1) (2024-02-18)


### Bug Fixes

* show translated no category when sorting by category ([#122](https://github.com/ellite/Wallos/issues/122)) ([330c061](https://github.com/ellite/Wallos/commit/330c061b74ad1580173f3d3bc7b14048492e22d2))

## 1.0.0 (2024-02-15)


### Features

* add workflow for building and publishing docker images ([970c96a](https://github.com/ellite/Wallos/commit/970c96a8c904809544c944071986be2a684daf50))
* specify image stability type when triggering build ([5b22cfd](https://github.com/ellite/Wallos/commit/5b22cfd87a94a865f53b282964961862bbea1861))


### Bug Fixes

* Currency not preselected on registration ([fc56cf6](https://github.com/ellite/Wallos/commit/fc56cf69ef22a07978022265b2e8344dc293eb14))
* Language sort order ([884a8e5](https://github.com/ellite/Wallos/commit/884a8e569339ddbcb89af4634c0c845b053affbb))
