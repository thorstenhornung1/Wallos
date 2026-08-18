# ADR-0001: A personal payment source belongs to an account, not to a workspace person

## Status

Proposed. Amends issues #53, #68, #69, #70 and #76.

## Context

Milestone J introduces two kinds of identity and is careful to keep them apart. A
*user* is an account with a login, and there is one of them per human per
installation. A *workspace person* is someone relevant inside one workspace, and
issue #50 scopes that row to a single workspace on purpose. That scoping is the
right call: it is what lets Helena be a name in the Müller family's accounting
without existing anywhere else on the instance, and it is what stops one
workspace's list of people from becoming a directory of the installation, which
is the concern #62 exists to answer.

Issue #68 then anchors a payment source to that workspace-scoped row —
`payment_sources` carries `owner_person_id XOR owner_workspace_id`, and the
migration makes every existing payment method "a personal source owned by the
account's private person".

Issue #53 requires the opposite property of the same table. A source is made
available to other workspaces by reference and never by copy: "Alice's Visa, made
available to the family workspace, stays one row." The reasoning given is
correct — copies drift, and a rename in one place silently stops matching the
other.

Those two requirements cannot both hold. A single row shared into three
workspaces has an owner that exists in only one of them, so every rule that
resolves the owner has to cross a boundary the person model was built to enforce.
Three consequences follow, and none of them is visible from inside the issue that
causes it.

**The advance in #76 becomes uncomputable in the general case.** "If a personal
source paid more than its owner's share" requires comparing the source's owner
against the subscription's cost allocations. Those allocations name people in the
subscription's workspace; the owner names a person in whichever workspace the
source was created in, which for every migrated source is the owner's *private*
workspace. The only bridge between the two namespaces is
`workspace_people.linked_user_id`, and issue #59 deliberately leaves that NULL
whenever the migration was not certain. So the one sentence stage F promises to
print is the one this ownership model makes hardest to reach, and it fails
silently rather than visibly: an unlinked person yields no match, and no match is
indistinguishable from no advance.

**A source can have an owner who cannot own anything.** Issue #70 states that a
personal source stays editable only by its owner. `owner_person_id` permits that
owner to be an external person — someone with no account by definition. Such a
source is editable by nobody, forever, including by the admin who created it.

**A workspace admin can reach into workspaces they cannot see.** Issue #64 lets
an admin manage the external people of their workspace. If a person owns a source
that three other workspaces use, removing that person disturbs accounting in
workspaces the admin has no membership in and no way to inspect.

## Decision

`payment_sources` carries `owner_user_id XOR owner_workspace_id`.

A personal source belongs to the account that may edit it. A workspace-owned
source belongs to the workspace whose admins manage it. Both owners are stable
across workspace boundaries, which is exactly what "one row, shared by reference"
needs and what a workspace-scoped person cannot provide.

The XOR survives unchanged and for the reason #68 gives: an object owned by both
a person and a workspace has two sets of editing rules and no way to choose
between them. In SQLite this is expressible as a `CHECK` constraint rather than
left to convention, which matters here because `PRAGMA foreign_keys` is never
switched on in this codebase and no declared foreign key has ever been enforced.

The advance calculation in #76 becomes statable, including its failure mode:
resolve the source's `owner_user_id` to the person in the *subscription's*
workspace whose `linked_user_id` matches, and compare that person's cost
allocation against the amount the source paid. When no such person exists, Wallos
infers nothing — the same refusal it already makes for workspace-owned sources,
and for the same reason. It does not know, so it does not say.

A card belonging to someone without an account — the grandparent whose card the
family wants to record — is modelled as a workspace-owned source. This is not a
capability that is being removed, because under `owner_person_id` that source was
already editable by nobody; the person-shaped spelling only appeared to cover the
case.

## Consequences

### Positive

One row per card, genuinely, with an owner who exists everywhere the row is
visible. Editing rights resolve to an account that can log in and be asked.
Removing a person from a workspace can no longer damage another workspace's
records, because people no longer own anything outside their own workspace.

The migration in #68 gets simpler rather than harder: `payment_methods.user_id`
becomes `payment_sources.owner_user_id` directly, with no dependency on the
person id map that #57 produces. That removes one of the two id maps stage D
would otherwise have to carry across a stage boundary.

### Negative

Wallos loses the ability to write "this card is Helena's" when Helena has no
account. The honest reading is that Wallos does not know whose card it is either;
what it knows is which workspace manages it. If a label is wanted later it is a
display string on the source, not an ownership claim.

`workspace_people` is left with no inbound reference from outside subscription
accounting and membership. That is a narrower table than #50 sketches, and it
should be read as the model getting smaller rather than as something lost.

### Neutral

The access table `payment_source_workspace_access` is untouched: it grants use,
never editing, exactly as #70 says.

## Alternatives considered

**Keep `owner_person_id` and add a cross-workspace person identity.** Rejected
because it reintroduces the global person that the workspace-scoped design exists
to avoid, and it produces two identity concepts — user and global person — where
one already suffices for everything a source owner has to do.

**Keep `owner_person_id` and require `linked_user_id` on any person who owns a
source.** Rejected because it makes #59's deliberately cautious linking rule into
a blocker on an unrelated feature: a household member the migration declined to
link would arrive with sources nobody may edit. It also leaves the person
lifecycle hazard untouched.

## References

- Issues #53, #68, #69, #70 (payment sources), #76 (the advance), #59 (linking),
  #50 (workspace people)
- `includes/database/sqlite/database.php` — the connection constructor, the only
  place a `PRAGMA foreign_keys` would go, and where it is not
- `endpoints/cronjobs/createdatabase.php:45` — a foreign key clause missing its
  separating comma, unnoticed because enforcement has never been on
