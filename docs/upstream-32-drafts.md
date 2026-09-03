# Two discussion drafts for issue #32

Prepared 2026-09-03. **Not sent.** Opening anything on `ellite/Wallos` needs
Thorsten's explicit approval, and these are two discussion threads, not pull
requests.

#32 has said since the beginning that both topics need to be raised separately
before any large architectural change goes out. Neither ever was. These are the
texts.

## How they are written, and why

Three things shape both drafts, and all three are observations about this
maintainer rather than general advice.

**He answers small correct things and ignores large proposals.** #1181 and
#1184 were merged within days, silently. The one person who prototyped a large
feature and asked whether a PR would be welcome got no answer at all. So
neither draft proposes anything to merge. Both ask a question and offer to do
the work in slices he approves.

**He has already built half of each topic himself.** Upstream carries sixteen
`OIDC_*` environment variables, one of them a `*_FILE` variant, plus
`SSRF_ALLOWLIST` and `DEMO_MODE`. And he already runs a test suite — ours,
which arrived through #1165–#1168 — against a schema built by his own migration
chain. Neither draft introduces a foreign concept. Both ask whether something he
is already doing should be done consistently.

**The strongest argument is what it does for his code, not ours.** The second
backend is the part worth having even if he never ships PostgreSQL: it is a
defect detector for SQLite code, because SQLite accepts things quietly that
another engine refuses out loud. That leads.

---

## Topic 2 — Optional PostgreSQL support

*This one goes first. It has a first step that changes no behaviour, and its
argument is a benefit to the existing codebase.*

> ### Would you want an optional second database backend — and would the
> defect-finding half be useful on its own?
>
> I run a fork of Wallos with PostgreSQL support. Before proposing anything
> here I want to ask whether the shape is one you would want at all, because
> the useful part turned out not to be PostgreSQL.
>
> **What happened.** Adding a second backend meant running the existing test
> suite against a second engine. That surfaced twelve defects in ordinary
> SQLite code — not PostgreSQL code, code that runs on every installation
> today. Three examples, all of them still present:
>
> * Unquoted mixed-case column aliases. SQLite accepts them; anything else
>   folds the case and the lookup misses. Seven code paths, one of which means
>   closed registration is not enforced.
> * A statement with 22 placeholders and 19 bindings. SQLite silently binds the
>   missing three as NULL. That is the ordinary path of the subscription form.
> * `ORDER BY 'order'` — a string constant, not the column. SQLite sorts by
>   nothing at all, so the list has looked unordered forever and nobody could
>   say why. This one is a one-line fix and I can send it separately today if
>   you would rather have it on its own.
>
> None of these needs PostgreSQL to be fixed. They needed a second engine to be
> *seen*, because SQLite's tolerance is exactly what hides them.
>
> **What the first step would be.** An adapter boundary and nothing else: the
> same SQLite3 calls behind an interface, one backend, no behaviour change, the
> suite green before and after. It changes no default, adds no service, and
> ships no PostgreSQL. Its whole purpose is to make the second engine
> *runnable in CI* so the class of defect above keeps being found.
>
> Whether a PostgreSQL backend follows is a separate decision, and I would not
> ask for it in the same pull request. Migration, backup/restore and multiple
> replicas are further still.
>
> **What I am not asking for.** I am not asking you to merge a large diff, and
> I am not asking you to support PostgreSQL. I am asking whether the boundary
> is a shape you would accept, and if so, how you would want it cut — because
> I would rather find that out before writing the pull request than after.
>
> Happy to send the `ORDER BY 'order'` fix immediately either way.

---

## Topic 1 — Instance-wide and shared configuration

*Second, and only after Topic 2 has an answer. It is about product semantics,
which is harder than a mechanical boundary, and it goes better once the first
conversation has established how these are going to work.*

> ### Should the `OIDC_*` environment variables become one pattern, or keep
> growing per subsystem?
>
> Wallos reads sixteen `OIDC_*` variables today, one of them
> `OIDC_CLIENT_SECRET_FILE`, plus `SSRF_ALLOWLIST` and `DEMO_MODE`. That is a
> configuration model, and a good one for anyone deploying in a container: the
> secret arrives as a mounted file, it is never written to the database, and it
> is not editable in the interface.
>
> It exists for exactly one subsystem. SMTP, the currency provider and the AI
> provider are configured per user in the database, which means an operator
> running one instance for a household or a small team has no way to supply one
> shared SMTP server or one shared API key without every user entering it
> themselves — or without an administrator typing a secret into a form that
> stores it.
>
> **The question is whether you would want the OIDC pattern generalised**, or
> whether each subsystem should keep growing its own variables as the need
> comes up. Both are defensible; the second is what is happening now.
>
> If generalised, the parts that seem to matter are these, and I would want
> agreement on them before writing anything:
>
> * One resolution order, the same everywhere: explicit user override →
>   `*_FILE` → environment → database → default.
> * An explicit `instance | custom` state per user, rather than inferring "use
>   the instance value" from a blank field. A blank field today means "blank",
>   and a value that means two things is where this gets confusing later.
> * A secret supplied through the environment is never written to the database,
>   and the interface shows it as managed rather than editable — which is what
>   `OIDC_CLIENT_SECRET_FILE` already does.
> * Instance defaults never silently rewrite settings a user chose.
>
> **What I would suggest as a first slice**, if you want it at all: the
> currency provider alone. It is one credential, one settings page, and it is
> the case where sharing helps most — one API key with a monthly request quota
> serving every user on the instance instead of one quota per user.
>
> I have this built and running, so I can answer implementation questions
> concretely. But I would rather agree the shape first: this is the kind of
> change that is cheap to discuss and expensive to redo.

---

## Before sending

* Send Topic 2 first, alone, and wait. Two threads at once is the "schedule a
  review" shape rather than the "answer a question" shape.
* Have the `ORDER BY 'order'` fix ready as its own branch when Topic 2 goes
  out. It is offered in the text, and offering something and not having it
  ready is worse than not offering.
* Neither draft names a fork issue number, a fork version or a fork migration
  number. He publishes text verbatim.
* Both were written to be answerable with one sentence. If he answers "no" to
  either, that is a result: it retires an open question that has been shaping
  this fork's roadmap since the beginning.
