# PostgreSQL, OIDC, declarative configuration

Written 2026-09-03, after four analyses of "where should this fork develop
next" produced a good answer to the wrong question. The right question was
stated in one line: **what we want is PostgreSQL, OIDC and declarative
configuration.**

Everything else in this repository — the defect hunting, the ratchets, the
gates, the upstream pull requests — is either a means to those three or it is
not worth doing. This document says which is which.

## Where each of the three actually stands

They are not one problem. They are three, and they are in very different
places.

**OIDC is nearly done and is the easy one.** It is built, and as of today it is
field-verified: the sign-in roundtrip, RP-initiated logout with `id_token_hint`
including the remember-me path (#123), and the role model refusing admin to a
provisioned account. What the fork added on top of upstream's basic OIDC — the
role model, back-channel logout, id-token persistence, discovery, BCP-47 — is
**incremental**. It travels in pieces, the same way the five prepared pull
requests do. It needs no architectural agreement, only the patience to send one
file at a time.

**PostgreSQL and declarative configuration are one problem wearing two hats,
and that problem is #32.** Issue #32 already names them exactly: Topic 1 is
instance and shared configuration, Topic 2 is optional PostgreSQL support. It
lists the ground rules, it lists the acceptance criteria, and **not one word of
it has been raised with the maintainer.** Both topics are marked "not portable"
in `docs/upstream-candidates.md` for the same reason: they are shapes upstream
does not have, and a shape cannot be cherry-picked.

So the keystone is a conversation, not a commit.

## The decision that unblocks everything, and why it is a conversation

There are only two futures in which this fork's PostgreSQL and configuration
work is worth anything.

**Either upstream takes them** — then production gets them by upgrading an
image, which is the stated route, and the fork can shrink back to what a fork
should be.

**Or production moves to the fork** — which was refused, and for a stated
reason: the fork is too experimental.

Both futures are gated on the same thing: **credibility**. The first needs the
maintainer to believe a three-thousand-line architectural proposal from this
fork is worth his evening. The second needs Thorsten to believe the fork is no
longer experimental. The work that produces both is identical, and it is
already under way.

That reframes every pull request sent so far. They are not charity and they are
not scratch-itching. **Each merged one is a deposit against the day #32 gets
opened.** Four are already in (`6809cba`, `a566380`, `fd96cdc`, `69b1e3f`), two
more merged silently within days (#1181, #1184), and one is open now (#1190).
The maintainer's behaviour is measured, not guessed: he merges small correct
things quickly and in silence, and he did not answer the one person who asked
about a large feature.

## What that means for the quality work — the measured answer

Four agents examined whether to build a third static gate: one that finds where
a function reports success although a write on the way was never checked. Three
have reported and they agree, which is worth recording because the proposal was
attractive and the answer is no.

**Measured, by full census rather than sample:**

* 110 files carry a literal success signal, not 112 — the difference is two
  test files, one comment-only match, and one file the grep missed.
* **15 of them are actually defective. 13,6 %.**
* **13 of those 15 are already in `dev/write-audit-baseline.txt`.** A third
  gate would name **two** files nobody has named.
* Cleaning up all 15: about one working day, 100–150 lines. Fourteen exist
  upstream, four byte-identical — clean cherry-picks.

**And the number the whole proposal rested on is wrong by a factor of three.**
`docs/next-steps.md` says 305 unchecked prepares, "nearly all of the remainder
carry a statement that changes data". Resolved properly — following the
variable assignment rather than looking at the first token inside `prepare(` —
it is **214 SELECTs and 94 writes**. The audit counts a `prepare()` given a
variable as a write, deliberately and correctly for a ratchet, and 282 of 459
call sites pass a variable. Conservative for a gate; useless as the basis for
an architectural decision, which is exactly what it was being used for.

**Two further findings that settle it:**

The ratchet has caught **zero** regressions in thirteen days. Its whole effect
landed in 36 hours, and it came from `--report` — the inventory — not from the
blocking gate. Meanwhile a reading pass on 2026-09-03 found seventeen new
candidates.

And a file-level counting gate **rewards the half fix**.
`upstream-fix/password-reset` repaired one half of its file and would have gone
out that way; a count-based gate falls silent once the file drops below its
baseline. That is the inverse of what it is built for, and it nearly happened.

### So instead

1. **Fix the measurement, not the gate.** Resolve the variable case in
   `write_audit_statement_writes()` (`dev/write-audit.php:211-223`). Fork-only
   file, no portability cost, and it turns "305, nearly all writes" into "94
   write sites in 66 files" — which is the number every later decision needs.
2. **Extend the existing ratchet by one form** — a discarded return value from
   a helper that writes. That catches the two files the current audit misses,
   without a second baseline of 95 correct files.
3. **Work the 15 files the way the last five went out**: one file, one test,
   one branch.

### Amended the same day: a third *column*, not a third gate

The paragraph that stood here said "no third gate" flatly. It was written while
the fourth analysis was still running, and that analysis came back with a
working prototype and measurements that change the answer in a specific,
bounded way. Recording the correction rather than editing the conclusion
silently, because the reasoning is the useful part.

**A file-level counting gate is still wrong** — all four analyses agree, and
the measured 77 % false positives and the half-fix problem stand.

**A path-sensitive rule is a different thing, and it works.** The prototype
pairs an unconsulted write with a later success signal only when the two lie on
a shared control path, decided lexically: two positions share a path when one
block path is a prefix of the other, and `} else {` closes one block and opens
another, so sibling branches never pair. Interrupts (`exit`, `die`, `return`,
`throw`, `break`, `continue`) cut the path, counted at the semicolon of their
statement rather than the keyword.

Measured on both trees: **80 hits upstream, 15 in the fork.** All 15 read by
hand, **all 15 real, no false positives** across 439 files. The gap between the
trees is the argument — the number measures the cleanup this fork actually did,
not noise. It also found something nobody had: `endpoints/subscription/renew.php:61`
runs its UPDATE, discards the result, and then runs the same UPDATE a second
time inside an `if`.

Two details are load-bearing and would be easy to get wrong. **An assignment
alone does not count as reading the result** — 40 of upstream's 80 hits are
`assigned-never-read`, almost all of them in the two deletion paths, so
accepting assignment as consultation loses exactly the case the rule exists
for. And a `$db->changes()` check on the write's own path must count as
consultation, or two correct fork files are reported.

So: **a third column in `dev/write-audit.php`, sharing its baseline mechanics —
not a second tool and not a second baseline.** That is what the measurement
analysis recommended as the cheaper alternative anyway; the two conclusions
agree, and the earlier wording obscured it.

**And a correction to the case that was made for it.** The claim that this form
produced the most expensive defects is only partly true. #87 and `enable_totp`
are exactly this rule. But #97, #101 and #116 are a different family entirely —
hardcoded HTTP 200, where PHP answers 200 by default and nobody contradicts it —
and #103's discarded value comes from a `require`, not a method call. Anyone
reading the new number as "the third form is covered" would be miscounting four
of the five issues.

## What gates are actually for here, since it is not finding

No gate in this repository has ever found the first instance of a defect class.
Against roughly 105 fork-side defects there are **six** gate finds, and of those
six, four are regressions of work in progress and two are extensions of a class
a human had already named.

Every one of the eight most expensive defects came from a person. But the four
activities that produced them are not luck; they are four repeatable
procedures:

* a security audit of code that already has passing tests
* instrumenting a channel that had no output, and reading what comes out
  (eleven cron failures in one night)
* **running the same code against a second backend** (twelve defects)
* a person actually operating the thing

The third is half-mechanical — the act is a CI job, the decision was a human's
— and it is the highest-yield single thing in the whole corpus. It is also, not
coincidentally, PostgreSQL.

What gates do here is **multiply and hold a floor**: seven found DOM-clobbering
sites became eighteen; one theme-cookie page became three; forty-three
discarded results were removed and have stayed removed. That is worth having.
It is not a search engine.

The highest-yield *new* gate, if one is ever built, is a **set comparison** —
derive two sets from the tree and require them equal, never from a written list.
All seven existing gates have that shape, and it is the only shape that has
found anything here nobody knew.

## The plan, in order

1. **Land the five prepared pull requests, one at a time.** #1190 is open.
   Each carries a regression test upstream's own harness runs — the largest
   lever this fork has, and until today never used. This is the deposit, and
   nothing else buys what it buys.
2. **Open #32, both topics, as two separate upstream discussions.** It is a
   conversation and it is the keystone for two of the three goals. It costs an
   evening of writing and it has been waiting since the beginning. Do not
   implement anything upstream-facing for either topic before it has an answer.
3. **Build `dev/shadow-migrate.sh`.** It is PostgreSQL's acceptance test: the
   upgrade path is recorded as untested in three independent places, because a
   fresh installation records the migrations as applied and therefore no CI run
   says anything about 73 migrations against a schema that grew. A nightly copy
   of the production database through the chain is the only planned machinery
   that would ever check it. Shape approved 2026-08-31; the script does not
   exist.
4. **Fix the write-audit measurement and work the 15 files.** One day, and it
   feeds step 1 with four byte-identical upstream candidates.
5. **OIDC continues in pieces.** It needs no permission and no architecture.

**Not now:** the third gate (measured above). Milestone J, which is parked and
whose ADRs rest on premises milestone K removed. Price history, which cannot
travel alone and whose one prior upstream inquiry went unanswered. Milestone C's
remaining integrations, which are literally Topic 1 of #32 and must not be built
before the topic is answered — four more things that cannot travel is the
opposite of the goal.
