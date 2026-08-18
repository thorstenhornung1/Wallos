# ADR-0002: Cost allocation succeeds `payer_user_id`, and stage F carries the migration that makes it true

## Status

Proposed. Amends issues #55, #65 and #74, and adds a migration to stage F.

## Context

Issue #57 already establishes that `subscriptions.payer_user_id` is a foreign key
to `household(id)` rather than to `user(id)`, and warns the migration not to
believe the name. What the specification never states is what the column *means*,
and the code answers that unambiguously.

`includes/stats_calculations.php:195` accumulates `$memberCost[$payerId]['cost']
+= $price`, and `stats.php` renders the result as the member split chart.
`payer_user_id` is not a record of which card was charged — `payment_method_id`
is that record. It is a record of *who carries the cost*, expressed as one person
at one hundred percent. It is already a `subscription_cost_allocations` row, in
degenerate form, and it has been one since multi-user support landed.

Stage E gives the payment side a migration: #72 turns each subscription's
`payment_method_id` into one payment allocation for the full price. Stage F gives
the cost side none. Its three sub-issues create the table (#74), the workspace
default (#75) and the advance display (#76), and not one of them backfills
anything. So on the day stage F ships, `subscription_cost_allocations` is empty
for every subscription that existed beforehand, while `payer_user_id` still holds
the attribution for all of them.

That is two records of one fact with no rule about which wins, and both
resolutions are bad. If statistics move to the allocation table, the member split
chart goes blank on every existing installation and the per-person history
disappears — issue #50 promises that "existing payer attribution survives", and
stage F is quietly where that promise stops being kept. If statistics keep
reading `payer_user_id`, a family who splits Netflix fifty-fifty sees the split on
the subscription and Alice at one hundred percent in the chart, with nothing to
indicate which number to believe.

A second obstacle stands in the way of the obvious fix. Issue #74 constrains
allocations to "every person a member of the subscription's workspace". Members
are accounts, recorded in `workspace_memberships`; people are not, and are
recorded in `workspace_people`. Nearly every person the stage-A migration creates
from `household` has no account. Read literally, #74 forbids the very backfill
that would preserve the attribution, and it also forbids a family recording that
a child carries a share — which is the case the person-versus-user distinction was
introduced to serve.

There is a third problem that only shows up in the data. `payer_user_id` is
written without validation: `endpoints/subscription/add.php:234` binds
`$_POST["payer_user_id"]` straight through with no check that the row belongs to
the caller, while the REST API at `api/subscriptions/set_subscriptions.php:472`
does check. A production database can therefore contain a subscription owned by
one account whose payer is another account's household row. A backfill that
trusts the id would place a stranger's name inside a workspace the migration was
supposed to isolate, and it would do so silently, because both values are small
integers.

## Decision

**`subscription_cost_allocations` becomes the sole record of who carries a cost,
and stage F backfills it.** One row per subscription — the migrated person, the
full price — after which nothing reads `payer_user_id`. The column is dropped in a
later, separate step once the audit confirms nothing reads it, which is the same
discipline #60 applies to `subscriptions.user_id` and for the same reason: doing
both at once makes the change unverifiable.

**Issue #74's constraint reads: every `person_id` must be a `workspace_people` row
of the subscription's workspace.** Whether that person also holds a membership is
irrelevant to whether they carry a cost. Conflating the two is precisely the
conflation the workspace-person concept exists to prevent.

**The backfill maps a payer only when `household.user_id` equals
`subscriptions.user_id`.** Anything else — a payer belonging to another account, a
payer id that matches no household row — is left unallocated and counted in the
migration's report. This is the one place where doing nothing is strictly better
than doing something plausible.

**A subscription with no usable payer gets no allocation row, not a guessed one,
and the reading code treats absence as unattributed rather than as zero.** Issue
#74 is right that zero and absence are different facts; the migration must not
manufacture either. Note that
`endpoints/cronjobs/sendnotifications.php:307` already invents a payer with
`array_key_first($household)` when none exists. That fallback is acceptable as a
display choice inside one email. It must never become a stored fact.

**Every migration in this milestone runs inside a transaction and verifies itself
before committing.** `includes/run_migrations.php` records a migration as complete
unconditionally once `require_once` returns, and no migration inspects the return
value of the statement it just ran, so a statement that fails part-way through
leaves a schema that is partly changed, marked done, and never retried. In
earlier milestones the worst outcome of that was a missing column. Here it is
subscriptions with a NULL `workspace_id`, which every query in stage C filters
away — indistinguishable from deletion to the person looking at the screen. The
boundary already exposes `beginTransaction()`, `commit()` and `rollBack()`; this
milestone is the reason to start using them.

## Consequences

### Positive

One fact in one place. The member split chart keeps working across the upgrade
instead of resetting to empty, and the concept the code has computed all along
finally has the name it deserved.

The invariant `SUM(amount) = price` holds for existing data from the moment the
table exists, rather than holding only for rows created after stage F. An
invariant that is true of some rows is not an invariant.

### Negative

Stage F grows a migration and stops being the small stage the specification
implies. Since stage F is the stage that changes what money means, that weight
belongs there and not somewhere more convenient.

Retiring `payer_user_id` touches roughly thirty call sites: the sort allow-lists
and group headers in `subscriptions.php` and `endpoints/subscriptions/get.php`,
the member filter in `includes/filters_menu.php`, the per-member aggregation in
`includes/stats_calculations.php`, the payer name in the iCal feed and the
calendar export, the row export, the grouping key in both notification crons, and
the payer name interpolated into the AI prompt. The audit that #65 asks for is
scoped to `user_id`; it must cover `payer_user_id` as well, or the inventory that
makes stage C reviewable will not make stage F reviewable.

### Neutral

Sorting and filtering "by member" lose their single value per subscription.
Sorting by a set is not defined, so the group-header behaviour in
`includes/list_subscriptions.php:195` has no successor. Filtering does survive in
a well-defined form — "subscriptions in which this person carries any share" —
and that is the replacement to build.

## Alternatives considered

**Keep `payer_user_id` as a denormalised summary and the allocation table as the
detail, synchronised on write.** Rejected because three write paths already exist
— the form endpoint, the REST API and `endpoints/subscription/clone.php` — and
`clone.php:35` already copies the column verbatim while copying nothing else
about the subscription's relationships. Two writable representations of one fact
diverge on the first path that forgets, and this codebase has already shown which
path that is.

**Backfill in stage A next to the person map.** Rejected because the allocation
table does not exist in stage A. Creating it early to fill it later means stage A
ships an invariant that nothing enforces and no interface can violate, which is
the least reviewable form a constraint can take.

**Leave existing subscriptions unallocated and let users fill them in.** Rejected
because it is a silent one-way loss. Nothing in the interface would say that the
old attribution existed, so nobody would know there was anything to restore.

## References

- Issues #50 (the survival promise), #54 and #72 (the payment-side migration this
  one mirrors), #55, #65, #74, #75
- `includes/stats_calculations.php:81,178,195` — the existing per-person cost
  computation
- `endpoints/subscription/add.php:234` versus
  `api/subscriptions/set_subscriptions.php:472` — the unvalidated and validated
  write paths for the same column
- `endpoints/cronjobs/sendnotifications.php:307` — the invented payer fallback
- `includes/run_migrations.php` — the runner that records success unconditionally
