# ADR-0003: Shared data outlives the account that created it

## Status

Proposed. Fills a gap: no issue between #50 and #77 mentions account deletion,
workspace deletion, or the removal of a person who carries a cost.

## Context

Deletion is the operation this milestone changes most and discusses least.

Today `endpoints/settings/deleteaccount.php` and `endpoints/admin/deleteuser.php`
both run `DELETE FROM subscriptions WHERE user_id = :userId`. That is correct
while `user_id` *is* the ownership boundary. Issue #60 deliberately keeps
`user_id` on `subscriptions` after `workspace_id` arrives, and is right to —
removing both in one step would make the migration impossible to verify. But the
combination becomes a data-loss bug the moment stage B lets two people share a
workspace. Bob joins the Müller family workspace, adds four subscriptions, then
closes his account. Those four rows carry `user_id = Bob` and
`workspace_id = Müller`, and they are deleted out of Alice's workspace by a code
path that neither Alice nor Bob was shown. An instance administrator removing a
user does the same thing to every workspace that user contributed to.

This is not a hypothetical class of failure in this codebase. The live
development database holds 36 `household` rows, 48 `currencies` rows and 48
`categories` rows whose `user_id` matches no `user` row. Twelve tables carrying
`user_id` are missed by both deletion endpoints, and `login_tokens` is missed by
the self-service one, so a self-deleted account keeps a valid remember-me token.
Deletion here is a hand-maintained list of statements duplicated across two
files.

**Corrected 2026-09-03.** This used to continue "`PRAGMA foreign_keys` is never
switched on anywhere in the project, so no declared cascade has ever fired and
every referential rule that exists is application code". Milestone K turned
enforcement on at every connection (`includes/database/sqlite/database.php:44`)
and migration 000072 repairs the orphans the unenforced years produced, so the
second half is out of date.

What replaces it is more specific. Exactly three of the declared keys carry
`ON DELETE CASCADE` — `login_tokens`, `user_roles` and `oidc_sessions`, on both
backends — and the rest carry no `ON DELETE` clause at all. With enforcement on,
that means removing a `user` row cascades those three and is *refused* wherever
any other child row still exists. The old order, which deleted the `user` row
first, would now fail on its first statement for any account that owns
anything.

Which is why deletion in this fork no longer lives in those two files:
`includes/user_deletion.php` derives the tables from the schema through
`tablesWithColumn('user_id')`, orders them so the `user` row goes last, and runs
the whole thing in a transaction. The premise this ADR was written against is
gone, and the mechanism it worried about — a hand-maintained list duplicated
across two files — is gone with it.

The milestone opens three lifecycle questions and answers none of them. They have
to be decided together, because they share one mechanism and one failure mode:
removing a row that a sum depends on.

## Decision

**Deleting an account never deletes rows a workspace owns.** Closing an account
removes the account, its private workspace and that workspace's contents. In
every *shared* workspace it removes the membership and clears `linked_user_id` on
the person, leaving the person, the subscriptions, the allocations and the
history in place. A subscription in a shared workspace has no personal owner to
be deleted along with, which is the whole meaning of "shared". The same rule
applies to administrative deletion, which today is the more dangerous of the two
because the person clicking is not the person losing anything.

**A workspace person referenced by a cost allocation, a beneficiary row or a past
membership is retired, not deleted.** `workspace_people` gains the `status`
column that #50 already gives `workspace_memberships`. Deleting the row would
remove a term from a sum that must equal the price, and issue #71 is explicit
about why that is the worst available outcome: a total that is short is invisible
until somebody reconciles a statement. Retiring keeps the sum intact and removes
the person from every picker. This is not a new idea in the codebase —
`endpoints/household/household.php:89` already refuses to delete a member who is
in use. Retirement is that guard made non-blocking instead of a dead end.

**A shared workspace may be deleted only by an admin, only when it holds no
subscriptions, and a private workspace never.** Deleting a populated workspace is
the single operation in this milestone that would destroy several people's
records on one person's click, and no confirmation dialog makes that acceptable.
Emptying it first is work, and the work is the point: it happens subscription by
subscription, visibly, to someone who can still change their mind.

**A personal payment source whose owning account is deleted is retired in place.**
Allocations referencing it keep their amounts and render the name marked as gone.
Rewriting a historical allocation onto a surviving source would be recording a
payment that did not happen.

**An account may not be closed while it is the last admin of a shared workspace**
that still holds subscriptions, for the same reason a member may not leave in
that position under #51. `wallos_is_last_admin()` already establishes this shape
for the instance; the workspace equivalent belongs beside the helpers in #58.

**Because foreign keys are not enforced, each rule above is application code with
a test, and the two copied deletion lists become one function.** Adding a third
copy for workspaces would guarantee that the next table added is missed by at
least one of them.

## Consequences

### Positive

No single click destroys another member's records. Issues #51 and #64 already
promise that cost history stays intact when someone leaves or is removed; this
extends the same promise to the two cases they do not name, which are the two
cases where the data actually disappears.

The accounting invariants introduced in stages E and F stay true over time rather
than only at the moment of writing. An invariant that any deletion can break is a
validation rule, not an invariant.

### Negative

Rows accumulate. A workspace that has run for years holds retired people and
retired sources that nobody can remove. That is the standing cost of an
append-shaped accounting history, and it is smaller than the alternative, which
is arithmetic that silently stops adding up.

"Delete my account" stops meaning "remove everything about me from this
installation". What survives is a name in someone else's workspace with amounts
attached to it. That has to be stated in the interface at the moment of deletion
rather than discovered afterwards, and on a self-hosted instance shared with
family it is a genuine change in what the button promises.

### Neutral

The rules make the existing orphan problem visible rather than causing it. The
twelve tables missed by both deletion endpoints have to be fixed before the
workspace-aware deletion path is written, because that path will be built by
consolidating them.

## Alternatives considered

**Switch foreign key enforcement on and let cascades handle it.** Rejected for
now on two grounds. Practically, `PRAGMA foreign_keys = ON` has never been on in
this project; enabling it retroactively against 48 orphaned currency rows, and
against a `subscriptions` table whose declaration is missing a comma between two
foreign key clauses at `endpoints/cronjobs/createdatabase.php:45`, converts a
modelling improvement into an outage. More importantly it is the wrong semantic:
a cascade deletes the sum term, which is exactly the thing that must not happen.

**Refuse deletion while any reference exists, as `household` does today.**
Rejected because it turns a shared workspace into a trap. Nobody could ever leave
cleanly, and no account could be closed while any workspace still remembered it —
which, given that the point of the history is to be permanent, means never.

**Transfer ownership of orphaned rows to a surviving admin.** Rejected because it
answers "whose subscription is this" with a name that was never true, and once
written down it is indistinguishable from a real one.

## References

- Issues #51 and #64 (leaving and removal keep cost history), #60 (`user_id`
  stays on `subscriptions`), #71 (a short total is the worst outcome), #58 (where
  the last-admin helper belongs)
- `endpoints/settings/deleteaccount.php`, `endpoints/admin/deleteuser.php` — the
  two copied lists, 19 and 21 tables, neither transactional
- `endpoints/admin/deleteuser.php:117` — the comment stating that cascades cannot
  be relied on
- `endpoints/household/household.php:89` — today's refuse-while-in-use guard
- `includes/user_roles.php` — `wallos_is_last_admin()`, the shape the workspace
  rule follows
