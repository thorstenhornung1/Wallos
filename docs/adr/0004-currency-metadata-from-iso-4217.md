# ADR-0004: Currency metadata comes from ISO 4217, exchange rates from the provider

## Status

Proposed, 2026-09-04. Answers the open half of [#133](https://github.com/thorstenhornung1/Wallos/issues/133).

Challenged 2026-09-04 by [#140](https://github.com/thorstenhornung1/Wallos/issues/140)
and upheld unchanged. See *The decision was challenged: Frankfurter* below. One
factual correction came out of it, marked where it applies.

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
* neither — `isoStatus: null`, which is where Bitcoin goes. **Not labelled as
  an ISO currency, and not removed either.**

This bullet read "Bitcoin and the metals" until 2026-09-04 and was wrong about
the metals. `XAU` 959, `XAG` 961, `XPT` 962 and `XPD` 964 are current ISO 4217
codes — verified in SIX's own current list, where they carry minor units
`N.A.` — and CLDR carries all four. They generate as `active`. Crypto is the
only genuine occupant of the null slot, which does not change what the slot is
for. The correction is recorded rather than quietly applied because the review
below is what found it.

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

These are the costs #140 offered to buy back, and the review below is why the
offer was declined. The short version: the source that appeared to carry
withdrawal dates does not carry withdrawal dates. It carries the date a rate
was last published, which agrees with ISO's withdrawal month in 6 of 36 cases.

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

## The decision was challenged: Frankfurter, 2026-09-04

[#140](https://github.com/thorstenhornung1/Wallos/issues/140) proposes
Frankfurter as a rate provider that needs no API key, and noticed something
that reads as a direct hit on the source chosen above:
`GET /v2/currencies?scope=all` returns numeric codes for historical currencies
*and* dates that look like withdrawal dates — the two things this document
accepted as CLDR's cost, two days after accepting them.

It was worth answering rather than waving off. Everything below was measured
against the live service or read at the URL given, on 2026-09-04.

**The decision stands. The source does not change and the mechanism does not
change.** Four reasons, and the first would settle it alone.

### 1. There is no licence on the data — and this time there are eighty-four of them

Frankfurter's repository carries an MIT licence at
`https://github.com/lineofflight/frankfurter/blob/main/LICENSE`, "Copyright (c)
Hakan Ensari", granting rights "to deal in **the Software**". The OpenAPI
document at `https://api.frankfurter.dev/v2/openapi.json` repeats that same MIT
in `info.license` and links to that same file. Both describe the program.
Neither says anything about the rates or the currency metadata the program
serves. A package registry showing "MIT" beside this project is showing the
code licence, and that is the exact substitution this document refused to make
for SIX.

The site is asked the question directly and answers it directly. Under
commercial use, `https://frankfurter.dev`:

> Yes, absolutely. See each provider's terms for details on the underlying
> data.

That is not a grant, it is a redirection, and it redirects to
`GET /v2/providers` — which returns **84 providers, of which 29 carry a
`terms_url` and 55 carry `terms_url: null`.** Counted from the response, not
estimated. Frankfurter cannot name a terms document for two thirds of the
sources its data comes from.

This document rejected SIX because one licence question could not be resolved
from public information. Here there are eighty-four, fifty-five of which have
no document to read at all. The same standard, applied honestly, refuses this
harder — and refuses it on the axis that matters, because committing a
generated file into a public GPL-3.0 repository is redistribution, not use.

The three provider terms actually read are not blanket grants either, and they
do not resemble each other:

* **ECB**, the pivot source —
  `https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html`
  — "Users of this website may make free use of the information obtained
  directly from it", conditioned on citing the ECB and on telling buyers the
  information is available free of charge if it is ever sold on.
* **Bank of Canada** — `https://www.bankofcanada.ca/terms/` — a bespoke
  conditional permission requiring attribution, a due-diligence duty on
  accuracy, and the same disclosure to purchasers. It names no licence.
* **Reserve Bank of Australia** — `https://www.rba.gov.au/copyright/` — the one
  that does use a standard licence, CC BY 4.0, and then lists **Financial
  Data** among the categories that licence does not cover, under separate
  terms of their own.

Three sources, three different regimes, and the only one on a recognised open
licence carves out the category the exchange rates are in. Whatever that is
collectively, it is not something you regenerate weekly and commit.

**None of this touches #140.** Fetching a rate at runtime is *use*, and it is
the same act Wallos already performs against fixer today. The challenge was to
*redistribute*, and redistribution is the half with no answer. The distinction
this document drew about SIX does the work here without modification, which is
some evidence the distinction was the right one to draw.

### 2. It is a rate catalogue, not the register, and it is measurably so

SIX's own lists, read at the source on 2026-09-04 and both stamped
`Pblshd="2026-01-01"`, carry **178** distinct current alpha codes and **137**
distinct historical alpha codes across 169 country rows, every historical row
with a `WthdrwlDt`. Against that:

| | ISO 4217 | Frankfurter |
|---|---|---|
| current codes | 178 | 165, four of which are not ISO codes at all |
| historical codes | 137 | 36 |

The gap is not the interesting part. The composition is.

* **`CNH`, `GGP`, `IMP` and `JEP` are in the active list and are not ISO 4217
  codes.** All four return `iso_numeric: ""`, in a field named `iso_code`.
  Offshore renminbi is a market convention; the Guernsey, Isle of Man and
  Jersey pounds are covered by ISO under `GBP`. A field named `iso_code` that
  returns things which are not ISO codes is this whole document's problem in
  one row.
* **`ANG` and `MRO` are listed as active and ISO withdrew both** — `ANG` at
  `2025-03`, replaced by `XCG`; `MRO` at `2017-12`, replaced by `MRU`. Both
  still return a rate today. `GET /v2/currency/ANG` names why:
  `"providers": ["BDI","NBP"]`. Two central banks still carry a line for a
  currency that stopped existing eighteen months ago, and that is enough to
  keep it in the active list. If `isoStatus` were generated from `scope`, `ANG`
  would come out `active`, and Wallos would offer a user a retired currency
  priced off a stale table in Burundi and Poland.
* **`ANG` and `XCG` both carry numeric code 532.** ISO reassigned 532 from the
  one to the other. Frankfurter holds both at once, so the numeric code is not
  unique in this catalogue.
* **19 current ISO codes are absent at either scope** — `BOV CHE CHW CLF COU
  MXV USN UYI UYW VED XAD XBA XBB XBC XBD XSU XTS XUA XXX`. Most are fund and
  unit codes nobody subscribes in. `VED` is a circulating code. `XXX` is the
  code ISO defines for "no currency", which is worth having precisely so that
  nothing else has to stand in for it.
* **There are no minor units.** `/v2/currencies` returns `iso_code`,
  `iso_numeric`, `name`, `symbol`, `start_date`, `end_date`;
  `/v2/currency/{code}` adds `providers` and `peg`. Minor units appear nowhere
  in the API. The file specified above requires them.

That last point is the plainest one and it stands on its own: **the proposed
replacement source cannot fill the fields of the file it would replace.**

None of this is carelessness on Frankfurter's part. It is a catalogue of codes
for which some contributing source has published a rate — which is why `CNH` is
in it and `XXX` is not, why `ANG` outlives its withdrawal, and why the
historical half stops at 36 rather than 137. Nothing is wrong with the
catalogue. It answers the second question in the table above, and it is the
wrong column for the first.

### 3. `end_date` has one meaning, and the meaning is not lifecycle

#140 flagged `end_date` as a freshness marker on active rows and a withdrawal
date on withdrawn ones, and called it a hazard. The API's own specification
settles it, and the answer is simpler and worse than that: it is a freshness
marker on **both** halves. The OpenAPI schema documents the pair as

> `start_date` — "Earliest available date"
> `end_date` — "Latest available date"

Nothing there is about a currency's life. On the active half the measurement
agrees — in a single call, 120 rows at `2026-09-04`, 44 at `2026-09-03`, and
`KPW` alone at `2026-09-02`. On the withdrawn half it agrees too, once the
dates are checked against ISO instead of against intuition.

**Six of the 36 agree with ISO's withdrawal date even to the month. Thirty do
not.**

The euro block is the mild case: ISO withdrew `ATS`, `BEF`, `ESP`, `FIM`,
`GRD`, `ITL`, `LUF` and `PTE` at `2002-03`, and Frankfurter has all eight at
`2002-02-28`. `FRF` is `2002-02-16` and `NLG` is `2002-01-26` — not because
anything happened in ISO's register on those days, but because that is when the
last quote landed. The bad cases are years out:

| code | ISO withdrawal | Frankfurter `end_date` |
|---|---|---|
| `ROL` | 2005-06 | 2008-12-30 |
| `TRL` | 2005-12 | 2010-05-11 |
| `ZWD` | 2008-08 | 2013-10-30 |
| `TMM` | 2009-01 | 2014-04-03 |
| `SLL` | 2023-12 | 2022-06-30 |

The first four are late because banks kept publishing a converted legacy series
after ISO retired the code. `SLL` is *early*, which is the same fault seen from
the other side: the quotes stopped eighteen months before ISO withdrew it. A
field that is three years late for one currency and eighteen months early for
another is not a withdrawal date with noise on it. It is a different quantity.

The fallback behaviour makes the real meaning visible.
`?date=2002-02-20&quotes=FRF` does not fail; it returns the row for
`2002-02-16` — the `end_date`, because that is the last row there is. And the
legacy rates are not the legal ones: the French franc was irrevocably fixed at
6.55957 to the euro from 1999, and this series returns 6.5828, 6.5511, 6.5619
and 6.5766 on different days. Converting an old FRF amount through it lands
near the right answer and not on it.

**So the two meanings cannot be told apart on the row, because there is only
one meaning and no lifecycle dates anywhere.** The only signal is which scope
an entry appears in; that split is *inferred* to be a rate-recency threshold —
it is not documented, but `ANG` at `2026-09-04` and `MRO` at `2026-09-03` are
active while `BGN` at `2025-12-31` is archived — and it is already wrong for
two currencies. This is worse than the hazard #140 described. The field is not
ambiguous. It is consistently something other than what was wanted.

One loose end, recorded because it was seen and not because it changes
anything: `?date=2002-02-28&quotes=BEF` returns 40.328, stable across three
calls, while `?date=2002-03-05&quotes=BEF` returns the same stated date
`2002-02-28` with rate 40.442. Two rates for one date, depending on which date
was asked for. Reproducible; cause not determined and not pursued. BEF's
irrevocable rate was 40.3399, so neither figure is the legal one.

### 4. The mechanism was never what was in question

Build-time generation with a committed file, a weekly job that opens a pull
request, and a running installation that never contacts the source: none of
that was challenged and none of it changes. #140 proposes a **source** change,
which is refused above; it is not a proposal to read metadata at runtime, and
the two are different arguments with different answers.

If anything the challenge strengthens the mechanism. A source whose `end_date`
column moves every day for 165 rows is a source you must not read at runtime
for a question that is supposed to be stable, and the freshness measurement in
section 3 is what that looks like from the outside.

### 5. The crypto slot is unaffected

`/v2/currency/BTC` returns HTTP 404, and neither `BTC` nor `ETH` appears at
either scope. #140's finding holds, and the `isoStatus: null` slot is exactly
as needed: someone tracking a crypto subscription still needs a provider that
prices it, and `fixerSupported` remains its own axis for that reason. The
metals were the error in the original bullet, and that correction is recorded
where the bullet is.

### What is worth keeping from the challenge

`/v2/currencies` is a good answer to the second question in the table above,
and a better one than fixer's `/symbols`, because `GET /v2/currency/{code}`
names which providers publish a code — so "we cannot price this" can say why.
That belongs in the provider column and in the informational CI report, both of
which this document already has room for. It stays a runtime query against a
provider and never becomes a committed file.

The report also gains a case that is no longer hypothetical. "Historical but
supported" was written above as a design row. `ANG` and `MRO` are that row,
live, today — ISO-withdrawn and still priced by a provider. That is precisely
the disagreement the report exists to print and not fail on.

### What could not be settled

* **The IMF's terms.** `https://www.imf.org/en/about/copyright-and-terms`
  returned HTTP 403 to two different tools from here. It is one of the 29
  providers that names a terms document, and the document could not be read.
* **The other 26 named terms documents were not read.** Three were, they
  disagree with each other, and the one on a standard open licence excludes
  financial data from it. Extrapolating from three to twenty-nine would be the
  same move this document refuses elsewhere.
* **The 55 providers with no `terms_url`.** Whether they publish terms
  somewhere Frankfurter has not linked is unknown. Frankfurter does not say and
  neither does this entry.
* **Where the currency metadata itself comes from.** `frankfurter.dev/currencies`
  says only "For symbols, subunits, and formatting positions, see World
  Currency Codes", linking to `https://frankfurter.dev/world-currency-codes/`,
  which is Frankfurter's own page and names no upstream and no licence. So even
  the provenance of the names, symbols and numeric codes — the part that would
  have been copied — is unstated.
* **Whether the `scope` split is a pure rate-recency threshold.** Inferred from
  three data points, not documented.
* **Whether the `BEF` two-rates-for-one-date result is a blend artefact or a
  bug.** Reproducible, unexplained, and irrelevant to the decision.
* **Asking SIX.** Still open, still worth doing, and unchanged by any of this.

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
