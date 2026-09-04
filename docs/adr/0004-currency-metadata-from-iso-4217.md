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
| What is this currency? | ISO 4217, taken from CLDR |
| Can we get a rate for it? | the provider's `/symbols` |
| May the user pick it here? | Wallos |

**ISO 4217 metadata is generated at build time and committed**, at
`resources/currencies/currencies.json`: code, name, numeric code, minor units,
and an `isoStatus` of `active` or `historical`. A weekly GitHub Action
downloads, regenerates, validates, and — on a change — **opens a pull request
rather than committing to main**. A running installation never contacts the
source; an outage there cannot affect installation, startup, currency selection
or rate retrieval.

**The source is CLDR, not SIX** — decided 2026-09-04 after checking the
licence, and it is the one thing in this document that changed between draft
and decision.

SIX is the ISO 4217 maintenance agency and the authoritative source, and it
publishes the lists free of charge. It does not license them. The files carry
no copyright notice and no licence at all, the data page says only that SIX
makes them "available online and free of charge" — which describes what SIX
does, not what a downloader may do — and the site's terms of use say, verbatim
and read at the source rather than taken from a summary:

> The entire content of the SIX website is protected by copyright law.
> Consequently, presentations, brochures, flyers, graphics, texts, designs,
> charts, etc., may not be reproduced or reused in any way or used for
> commercial purposes.

Whether an XML data file falls under that "etc." is exactly where
interpretation begins, and nothing on the site resolves it. ISO's own position
pulls the other way — it has said publicly that the codes may be used free of
charge "in commercial and other applications" — but every permission anyone
states is about *use*, and committing a generated file into a public repository
is *redistribution*. Those are different acts and only the first one has an
answer.

The GitHub projects that mirror the SIX lists do not solve this; they relabel
it. The most widely used one states its own licence reasoning outright — "The
original site states no restriction on use" — which is a third party's legal
opinion about somebody else's data, resting on a premise the terms of use
contradict. Another checks the SIX XML in unchanged under an MIT header it
cannot grant.

**CLDR has what SIX lacks: an actual grant.** The Unicode licence names data
files separately from software and permits "use, copy, modify, merge, publish,
distribute", conditioned only on carrying the notice. It is OSI-approved. CLDR
gets its currency codes from SIX as well, so the factual chain is the same —
the difference is not sanitary, it is that an established consortium has
examined the question and issued a permission, where here we would be relying
on our own reading. That is a real difference and not a proof.

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

**What CLDR costs, stated rather than discovered later.** No ISO withdrawal
date — CLDR carries country-level usage periods instead, from which a global
date is a derivation rather than a source value. No numeric codes for
historical currencies; the LDML specification says so outright. Up to about
twelve months of lag, because CLDR has one annual release plus maintenance
releases while SIX publishes amendments continuously. And more generator
surface: three files instead of one, and a `DEFAULT` rule for minor units that
covers the codes the fractions table does not list — a quiet failure mode
exactly of the kind this design's loudness rules exist for, so the validation
must fail when `fractions` has no `DEFAULT` and when the validity data returns
implausibly few current codes.

Only the lag has a user-visible effect, and the design already absorbs it: a
code the registry does not know is a stated fallback, not an error, and
`fixerSupported` is a separate axis. If the lag ever matters, a small
hand-maintained overlay for freshly announced codes is a different act from
redistributing a database — three letters, a number and a date typed out.

**Still open, and worth doing anyway: ask SIX.** A written answer on whether
the lists may be redistributed in a GPL-3.0 project would restore the
authoritative source, and it would be worth having for every other project with
this question. Until there is one, CLDR carries it.

**And a warning about this entry's own evidence.** The quotations above were
read at their URLs. The wider licence analysis behind them — the Swiss position
on database rights, the EU directive's reach, whether the terms of use bind as
a contract independently of copyright — was researched, not adjudicated. If the
SIX route is ever taken, those are the points a lawyer looks at, and the list is
in the research notes rather than invented here.

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
