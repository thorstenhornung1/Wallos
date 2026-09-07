# goauthentik reports — drafts, unsent

Four findings from the test instance, running **authentik 2026.8.1** — three
from 2026-09-04, and a fourth from the 5.13.0 avatar test on 2026-09-05. They
are separate defects, drafted as separate issues, strongest first. Post
whichever you want; they do not depend on each other.

## How to post these

1. Go to <https://github.com/goauthentik/authentik/issues/new/choose> and pick
   **Bug report**.
2. Copy one section below, from its `### Title` line down to the `---` that
   ends it. Paste the title into the title field and the rest into the body.
3. **Before posting, fill in anything marked `«FILL IN»`.** Those are facts
   the diagnosing session holds and I do not — mostly the exact traceback for
   report 2. Posting a report with a placeholder in it is worse than posting
   nothing.
4. Report 1 and Report 4 are ready as they stand and are the ones worth posting
   first.

**Status (2026-09-07):**

- **Report 1** — POSTED as goauthentik/authentik#25826. Reviewed and corrected before posting: the notification window is the access-token lifetime (via `ExpiringModel`'s default manager excluding expired rows), *not* a `clean_expired_models` race; `access_token_validity` was five minutes *in this deployment*, not a 2026.8.1 default (the branch default is `hours=1`). An issue search found no existing duplicate.
- **Report 2** — NOT posted: already fixed upstream by PR #25816 "core: fix bulk session revocation" (merged 2026-09-06 21:47 UTC), the exact `session=` → `instance=` one-liner. Our three production events (2026-09-03 ×2, 2026-09-04 ×1; authentik event UUIDs 53d0e029/abbd1810/a423e2ff) were this bug, and the root cause was confirmed line-for-line before the upstream fix was found. Held as a duplicate.
- **Report 3** — held (unconfirmed hang).
- **Report 4** — separate (avatar / access-token bloat).

---

# Report 1 — the back-channel logout window is bound to access token lifetime

**Strongest of the three. Measured both directions, mechanism identified in
the source, and it has a positive control.**

### Title

`Back-channel logout is only delivered while an access token is alive`

### Body

**Version:** 2026.8.1

#### What happens

Deleting a user's session in the admin interface notifies the OIDC provider
only if that session still has a live access token. With
`access_token_validity` at its default of five minutes, an administrator who
ends a session an hour after the user signed in gets **no notification sent at
all** — no error, no task, nothing in the log. The delete itself succeeds and
the session disappears from authentik, so from the administrator's side it
looks like the user was signed out everywhere.

The relying party, meanwhile, never hears about it and keeps its own session
running. In our case that is a thirty-day session.

#### Measurements

Same provider, same configuration, same relying party. The only variable is
elapsed time since login.

**72 minutes after login** — `DELETE /core/authenticated_sessions/<id>/`
returned 204 and the session was gone. No `backchannel_logout_notification_dispatch`
and no `send_backchannel_logout_request` task ran; the worker only logged
`outpost_session_end`. The relying party's log stayed silent, and its session
row survived.

**Within 5 minutes of a fresh login** — the same action produced
`backchannel_logout_notification_dispatch` and `send_backchannel_logout_request`,
both finishing cleanly, the relying party logged the revocation, and the
browser was signed out on its next request. End to end in under two seconds.

#### Why

The `pre_delete` receiver for `AuthenticatedSession` iterates over
**`AccessToken` objects** filtered by user and session key, and notifies only
the providers it finds there. Refresh tokens are not consulted.

The filter carries **no expiry condition**, so an expired access token still
counts until `clean_expired_models` deletes the row. That makes the real
window "the access token's validity, plus however long until the cleanup task
next runs" — and we saw exactly that: in the failing run the cleanup had
executed 24 seconds before the delete. Had the order been reversed, the
notification would very likely have been sent.

So the same administrator action produces different outcomes depending on the
minute it happens in.

#### Why this matters

Back-channel logout exists for the case where a session must be ended out of
band: a lost laptop, someone leaving, a credential believed compromised. None
of those happen within five minutes of a login. The feature is close to
unavailable in precisely the situation it was designed for, and it fails
silently on both sides — authentik reports the session deleted, the relying
party reports nothing.

#### What we would expect

A session that exists should be notifiable for as long as it exists,
independent of whether a short-lived token happens to be alive. Consulting
refresh tokens, or the session's provider bindings directly, would do it.

If the current behaviour is intended, it should be documented — the effect on
an administrator's expectations is large, and nothing in the current
documentation suggests that ending a session might not notify anybody.

#### Note

A relying party can work around this by keeping an access token alive through
periodic refresh, and we have done so. That is a workaround for the relying
party's own sessions; it does not help any deployment that has not thought
about it, which is all of them until they measure.

---

# Report 2 — bulk session delete raises a TypeError in the SSF signal handler

**«FILL IN» the traceback before posting. Everything else is observed.**

### Title

`Bulk-deleting authenticated sessions raises a TypeError in the SSF signal handler`

### Body

**Version:** 2026.8.1

#### What happens

Selecting authenticated sessions in the admin interface and using the bulk
delete action raises a `TypeError` inside the SSF signal handling that runs on
session deletion. The request fails; the deletion does not complete cleanly.

Deleting a single session from the user's detail page works and returns 204.
Only the bulk path fails.

Observed three times (2026-09-03 07:29:47, 2026-09-03 07:29:54, 2026-09-04 14:52:29 +02), all identical. Fixed upstream by PR #25816 (merged 2026-09-06); not posted as a duplicate.

#### Traceback

```
«FILL IN — the exact traceback from the server log, including the
 file and line of the TypeError and the signal receiver it happened in.
 Redact any user identifier, session key, token or hostname before pasting.»
```

#### Reproduction

1. Admin interface → Directory → Users → a user with at least one active
   session, or the authenticated sessions list.
2. Select one or more sessions with the checkbox.
3. Use the bulk delete action.

#### Note

The single-delete path on the user detail page is a working alternative, which
is what we used for our own testing after hitting this.

---

# Report 3 — authorization endpoint hung for one provider until the server was restarted

**Weakest of the three. Observed and thoroughly narrowed, but the mechanism is
not established and one attempt to reproduce it failed. Post it as an
observation, not as a diagnosis — or hold it until it recurs.**

### Title

`/application/o/authorize/ hung indefinitely for a single provider until restart`

### Body

**Version:** 2026.8.1

#### What happened

`/application/o/authorize/` hung for **one specific OIDC provider**, reliably,
until the request timed out at the reverse proxy after 30 seconds. Reproducible
anonymously, with no cookie. Other providers on the same instance were
unaffected and answered in about 0.26 s throughout.

The hanging requests **never logged completion**. They accumulated in the
server process with every attempt. Restarting the server container — same
image, no configuration change — resolved it completely; the endpoint then
answered in 0.38 s.

#### What was ruled out

* Database locks — `pg_stat_activity` showed nothing waiting.
* Network reachability of the relying party from the container — 0.1 s.
* The `logout_uri` feature in general — a second provider with a `logout_uri`
  set was unaffected and served requests normally throughout.
* Policy bindings and provider configuration — the only difference from the
  working provider was `invalidation_flow`, unchanged for a week.

#### What we suspected, and why we are not asserting it

The hang appeared after a crashed bulk session delete (report 2) involving that
provider's session. A plausible mechanism is a lock acquired around the session
deletion signal dispatch whose release is not in a `finally`, so an exception
mid-dispatch leaves it held — which would explain the determinism, the binding
to one provider, the accumulation, and the fix by restart together.

**We could not confirm it.** A later occurrence of the same bulk-delete
TypeError did **not** produce a hang; the authorization endpoint answered
normally afterwards. And a server restart had taken place between the first
crash and the observed hang, which weakens the chronology further.

So this is an observation with a narrowed field and no established cause. If
the receiver does hold a lock or other process-wide state across the signal
dispatch, that would be the place to look.

---

# Report 4 — the default `picture` mapping bloats the access_token, and userinfo then exceeds header limits

**Strong: measured, mechanism identified in the source, and a working fix. As
ready to post as report 1.**

### Title

`Default \`picture\` property mapping embeds the avatar data-URI in the access_token, so userinfo requests exceed header size limits`

### Body

**Version:** 2026.8.1

#### What happens

A user with an uploaded profile picture signs in through the OIDC
authorization-code flow. authentik's default `picture` property mapping returns
the avatar as an inline `data:` URI. That claim appears not only in the
**userinfo** response, where a relying party expects it, but — through the same
scope mapping — is also embedded in the **access\_token** and the **id\_token**.

The token exchange succeeds. The relying party then calls the userinfo endpoint
with `Authorization: Bearer <access_token>`, as it must. Because the access
token now carries the whole avatar, that request header is enormous, and the
server fronting the userinfo endpoint rejects the oversized header and resets
the connection. The relying party's userinfo call fails at the transport level
before it ever receives a response.

The sign-in cannot complete — and it fails at the userinfo step even though the
code exchange worked, which is a confusing place for the failure to surface.

#### Measurements

Same instance, one real user, one uploaded avatar.

* the avatar returned in the `picture` claim: **~160 KB** (a JPEG `data:` URI)
* the resulting **access\_token: ~216 KB**
* the resulting **id\_token: ~161 KB**
* the relying party's userinfo request failed with curl `CURLE_SEND_ERROR` —
  *"Failed sending data to the peer"* — the connection was reset while the
  oversized request header was still being sent.

With the same user and no avatar (or a small one), the tokens are normal size
and userinfo answers immediately.

#### Why

The `picture` mapping is evaluated for the tokens as well as for userinfo, with
no gating by endpoint, so the `data:` URI is written into the JWT access and id
tokens rather than staying in the userinfo JSON. An access token is meant to be
a bearer credential carried in a header; embedding an unbounded blob in it makes
the token unusable for the very endpoint it must authenticate against — and it
is authentik's own userinfo endpoint that then cannot accept the token authentik
issued.

#### Why this matters

This is the **default** `picture` mapping, and it backs the standard mapping
providers authentik ships — Immich, Paperless, Nextcloud and the rest. Any of
them, as soon as one user with a sizeable uploaded avatar signs in, arms the
same failure — silently, until it happens. It is self-inflicted: nothing the
relying party can do helps, because it must send the bearer token it was given.

#### The fix we used

Gate the `picture` mapping to the userinfo endpoint, so the avatar is returned
there and kept out of the tokens. In an expression mapping that is a check on
the request path:

```python
if request.http_request.path.endswith("/userinfo/"):
    return {"picture": <the data URI>}
return {}
```

We verified against the source that the userinfo view evaluates property
mappings with the real HTTP request, which is what makes the path check work.
That behaviour is **undocumented**, and the managed default mappings cannot be
edited durably, so this has to live in a separate custom mapping.

#### What we would expect

A default mapping should not embed unbounded data into a JWT that then travels
in a header. Returning `picture` as a **URL** rather than an inline `data:` URI
in the default mappings would avoid it entirely; failing that, gating large
claims to the userinfo endpoint by default — or documenting the request-path
technique — would give operators a supported way out.

#### Note

Our relying party reads `picture` from **userinfo only**, raster images only,
with a size ceiling — so once the tokens shrink, nothing on our side changes.
The failure was traced in minutes rather than hours only because the relying
party names it precisely (`reason=userinfo_failed`, with the curl error);
without that line, "the token exchange worked but userinfo will not connect" is
a long hunt.

---

## Where the evidence lives

Wallos-side measurements and reasoning are in this repository:

* `docs/test-instance.md` §7.4 — the back-channel test, including the run that
  would confirm the refresh workaround end to end.
* Issue #144 — the relying-party half of report 1, and the fix.
* Report 4 — the avatar import that consumes `picture` is
  `includes/oidc/oidc_avatar.php` (raster-only, size-limited, content-addressed);
  the token-size figures were measured by the session administering the test
  instance.

The authentik-side measurements — worker task lists, timings, database
queries, source reading — were taken by the session administering the test
instance and are not reproduced here.
