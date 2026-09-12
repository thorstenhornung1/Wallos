# This fork and upstream

Rules derived from measuring the two trees, not from habit. The numbers are in
the "Where this stood" section at the end — last measured 2026-09-12, after the
5.7.1 merge; re-measure before trusting them.

## What the relationship actually is

Upstream is **SQLite only**. It has no database abstraction, no `tests/`, no
`dev/`. This fork added all three, plus PostgreSQL, OIDC work and container
hardening.

That single fact decides how defects are classified.

## 🔴 Inherited code is not automatically a fork defect — and not automatically upstream's

Three defects were found in the PostgreSQL test runs. Their origin split three
ways, and the split matters for where each one belongs:

| | Origin | Is it a defect upstream? |
| --- | --- | --- |
| Unquoted mixed-case SQL aliases | upstream code | **No.** SQLite preserves alias case. |
| `SQLite3` type hint in a signature | upstream code | **No.** Upstream's connection *is* a `SQLite3`. |
| `dev/*.sh` opening SQLite directly | fork-only file | **Yes**, ours alone. |

The first two are correct under their own assumptions. They became defects when
this fork changed the environment they run in. **The fork did not introduce
them; it exposed them.**

Practical consequence: do **not** file those upstream. An issue about PostgreSQL
behaviour in a project that has no PostgreSQL is noise. One was filed by mistake
against `ellite/Wallos` and had to be withdrawn.

The corollary is the working rule: **every merge from upstream can bring in new
code that silently assumes SQLite.** That is not upstream's fault and not
something to complain about — it is the cost of the fork, and it has to be paid
by a gate on our side.

## Commit discipline: keep portable fixes separable

A fix that would help upstream must be in its own commit, free of anything
PostgreSQL- or abstraction-specific. When the two are mixed, backporting stops
being a cherry-pick and becomes archaeology.

This held for the 5.8.1 security fixes and made the backport branch possible in
an afternoon:

* `includes/totp_state.php` and `includes/session_tokens.php` were written
  against the plain SQLite3 API. Zero abstraction calls. They apply to upstream
  unchanged.
* The commits touching them changed four files each, of which two were fork-only
  (`tests/`, `dev/db-audit-baseline.txt`) and trivially dropped.

It did **not** hold for `logout.php`: our version pulls in `includes/oidc/logout.php`,
so the backport had to be re-written by hand against upstream's file. Small this
time. It will not always be.

## Backporting: four tiers

1. **Send now, one PR per fix.** Security fixes that are backend-independent.
   Small, self-contained, no architecture decision required of the maintainer.
2. **Send soon.** Plain bug fixes with no PostgreSQL content.
3. **Ask before sending.** PostgreSQL support itself. A 129-file pull request
   arriving unannounced does not get read. Open an issue, state the scope
   honestly, and find out whether it is wanted *before* investing further.
4. **Keep here.** Dev tooling, container hardening, cron instrumentation —
   opinionated and specific to how we run it.

## Timing

The moment to backport is while the conflict surface is small, and the surface
grows with every upstream commit that touches one of the files this fork has
restructured — 191 of them today, up from 129 in August.

There is a second clock. A security fix that applies to upstream affects every
upstream user *now*. The TOTP replay guard needed no failure of any kind to be
exploited. Holding that back for tidiness would be the wrong trade.

## The gate that makes this sustainable

`dev/semgrep/sqlite-boundary.yml` exists for exactly the inherited-assumption
problem and is now switchable. Run it on every merge from upstream, not by hand
when someone remembers.

Two limits it has today, both worth knowing before relying on it:

* It declares `languages: [php]`, so PHP embedded in `php -r '...'` inside
  `dev/*.sh` is invisible to it. Five real violations lived there undetected.
  A plain grep over `dev/*.sh` covers that gap.
* Its own header records that the rules produced 1119 findings of which 1118
  were not violations, and that two rules were broken in ways that read as
  clean. A guard is only as good as its last calibration.

## Where this stood on 2026-09-12

```
common ancestor      52820e8  upstream 5.7.1 (2026-09-10), merged at b98cd3c
fork ahead           397 commits
upstream ahead       0 commits
files changed here   501   (298 new, 191 modified, 8 renamed, 4 deleted)
conflict surface     0 files today; 191 eventually
upstream tempo       33 commits in the nine days to 2026-09-10, in two bursts
```

Two things changed in the relationship, and both are worth stating plainly.

**Upstream has this fork's test harness.** `tests/bootstrap.php`, `tests/run.php`
and the fixture that runs the migration chain went upstream with #1165–#1168,
and 5.6.0 and 5.7.0 arrive carrying *upstream's own* cases written against it —
`logo_cleanup_test.php`, `pwa_manifest_test.php`, `notes_markdown_test.php`. The
harness is no longer something this fork offers; it is something the merge now
delivers work in. That cuts both ways: a case written against SQLite's API
arrives with every feature, and each one has to be read for PostgreSQL before it
can run here.

**Eleven of the thirty-three commits were ours.** The maintainer merges in
silence and in batches, and a batch that lands is our own code in his shape. The
merge cost is then mostly *reconciling two versions of one fix*, which is
cheaper than it sounds but never free: for each, decide which version stays, and
the answer is nearly always this tree's, because it is the one with the
transaction, the boundary and the test.

### The tempo is not monthly any more

Nine days produced 33 commits — 21 on one day, 6 on another, nothing in between.
Planning around "8-28 commits a month" is planning around an average that does
not describe the thing. Merge when a release lands, not on a calendar.

### What a merge now costs, measured on this one

Thirty-four conflicts, most of them our own fix meeting its upstream shape. The
expensive half was not the conflicts:

* **Two migration numbers collided.** Upstream's `000057`/`000058` are this
  fork's language canonicalisation and `user_roles` table. A migration is
  recorded by file name, so the incoming ones had to be renumbered to the end of
  the chain (`000087`/`000088`) *and* rewritten through the boundary — the
  originals ask SQLite's own table metadata.
* **Three of our gates were wrong**, and the merge is what showed it: two walked
  `.claude/`, which holds agent worktrees; the `ORDER BY` gate read a string
  concatenation and its own explanatory comment as defects; `portable_sql()`
  could only read a query written on one line, so upstream moving a query into a
  helper would have switched a portability check off silently.
* **One inherited SQLite assumption**, exactly as this page predicts:
  `deleteLogoFileIfUnused()` names one bound parameter three times in one
  statement. SQLite repeats a named parameter; PDO with native prepares does
  not. Not upstream's defect — upstream has no PostgreSQL — and found by reading
  the incoming code rather than by a gate, which is the gap worth closing next.
