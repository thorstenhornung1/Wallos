# This fork and upstream

Rules derived from measuring the two trees on 2026-08-20, not from habit. The
numbers are in the "Where this stood" section at the end; re-measure before
trusting them.

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
restructured — 129 of them today.

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

## Where this stood on 2026-08-20

```
common ancestor      5d5baf3  upstream 5.4.4 (2026-08-15)
fork ahead           90 commits
upstream ahead       0 commits
files changed here   254   (117 new, 129 modified, 8 renamed)
conflict surface     0 files today; 129 files eventually
upstream tempo       8-28 commits/month
```

Upstream standing at zero commits ahead is not a normal state and will not last.
