# ADR-0004: Currency metadata comes from ISO 4217, exchange rates from the provider

## Status

Proposed, 2026-09-04. Answers the open half of [#133](https://github.com/thorstenhornung1/Wallos/issues/133).

## Context

A currency in Wallos is three free-text fields. An invented code is accepted
without a word, sits at the rate 1 it was seeded with, and goes into every total
on the dashboard, the statistics page, the calendar and every notification —
indistinguishable on screen from a correct number. That is #133, and it was
found by typing "Lunarium" into the form.

The obvious fix is to take the list off the user. The question is where the list
comes from, and the provider is the tempting answer for the wrong reason.

`/symbols` tells us what fixer will price. It does not tell us what a currency
is. Fixer prices history back to 1999, so its catalogue carries BYR beside BYN,
and HRK, LTL, LVL, VEF, ZMK — withdrawn currencies, listed because somebody may
want a 2003 rate. It also carries precious metals and Bitcoin. Treating that
list as "currencies a person may choose" would replace one wrong answer with
another and call it validation.

So there are two questions and they have two sources.

## Decision

**Three questions, kept apart:**

| question | source |
|---|---|
| What is this currency? | ISO 4217, published by SIX |
| Can we get a rate for it? | the provider's `/symbols` |
| May the user pick it here? | Wallos |

**ISO 4217 metadata is generated at build time and committed**, at
`resources/currencies/currencies.json`: code, name, numeric code, minor units,
and an `isoStatus` of `active` or `historical` with the withdrawal date where
the source gives one. A weekly GitHub Action downloads List One and List Three,
regenerates, validates, and — on a change — **opens a pull request rather than
committing to main**. A running installation never contacts SIX; a SIX outage
cannot affect installation, startup, currency selection or rate retrieval.

**`isoStatus` and `fixerSupported` are separate properties**, and every
combination is meaningful:

* active and supported — the ordinary case, and the default selection.
* active but unsupported — a valid currency whose rates we cannot fetch. Say so
  in the selector rather than hiding it.
* historical but supported — useful for old records and historical rates.
* neither — `isoStatus: null`, which is where Bitcoin and the metals go. **Not
  labelled as an ISO currency, and not removed either.**

That last row is the point of the whole separation, and it is what makes the
answer serve two audiences at once. A household or a shared flat gets a list of
currently valid currencies and correct totals without knowing any of this
exists. Someone tracking a crypto subscription keeps their symbol, because
`fixerSupported` is its own axis and nothing in the design requires an entry to
be an ISO currency to be selectable. The default is narrow; the door is not
locked.

**Stored codes never become invalid.** A subscription in BGN stays readable and
displayable when ISO withdraws BGN; the code simply leaves the picker for new
records. No destructive migration, and no automatic conversion of stored amounts
when one currency replaces another — a redenomination has a ratio, a transition
period and often simultaneous circulation, and none of that belongs in a
metadata registry.

**No database change in the first implementation.** The registry is static
application metadata and records keep storing the code alone. That avoids a
migration, a synchronisation state, and a stale copy in every installation's
database — three things this fork has spent real time on already.

## Consequences

**It can travel upstream, and that is unusual here.** A static JSON file, a
small `CurrencyRegistry` class and a workflow depend on nothing this fork
invented — no database boundary, no instance configuration, no role model. That
puts it in a different category from PostgreSQL and declarative configuration,
which are the #32 conversation precisely because they cannot be cherry-picked.
This one could be proposed on its own merits, and it answers a complaint any
Wallos installation can reproduce in ten seconds.

**It does not fix #135.** Validation stops new bad codes from being entered; it
does not stop the refusal cache from generalising a symbol-level refusal as if
it belonged to the credential. A provider dropping support for a code, or a row
that predates validation, reaches the same place. The two are complementary and
neither is the other's excuse.

**What must be checked before implementing**, and is not settled here: whether
the SIX dataset may be redistributed in a repository under this project's
licence. The design assumes a committed snapshot, and that assumption is load
bearing — if redistribution is not permitted, the shape changes.

**The failure mode is deliberately loud.** The generation job fails rather than
overwrites when the download fails, the XML will not parse, required fields
disappear, or the output fails its schema — and warns when more than a tenth of
the catalogue changes at once, which is what a parser breaking looks like from
the outside. An empty or half-populated registry must never be the quiet
outcome of an upstream format change.

## The first implementation

Required: List One, the generated file, the weekly action, the pull request on
change, `CurrencyRegistry`, the `isoStatus`/`fixerSupported` distinction, active
currencies as the default selection, and a safe fallback for codes already
stored that the registry does not know.

Recommended, not required: List Three and withdrawal dates, and a CI report
comparing the registry with `/symbols` — active currencies the provider will not
price, historical ones it still will, symbols with no ISO entry. **Informational
only.** No build fails because fixer and ISO disagree; they answer different
questions, which is the premise of this entire decision.

Later: localised names through CLDR with the ISO name as the fallback and the
code as the last resort, a historical-currency filter in the interface, and any
richer lifecycle display. None of it is needed to stop an invented currency from
quietly costing somebody the right answer.

## Related

`dev/currency-symbols.php` (5.10.0) already asks the provider which stored codes
it will not price. That is the diagnostic half and it needs no registry — it is
what to run today when rates look wrong, and it is how the gap this ADR closes
became visible in the first place.
