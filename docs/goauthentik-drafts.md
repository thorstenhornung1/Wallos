# goauthentik reports — drafts, unsent

Three findings from the test instance on 2026-09-04, running **authentik
2026.8.1**. They are separate defects and are drafted as three separate
issues, strongest first. Post whichever you want; they do not depend on each
other.

## How to post these

1. Go to <https://github.com/goauthentik/authentik/issues/new/choose> and pick
   **Bug report**.
2. Copy one section below, from its `### Title` line down to the `---` that
   ends it. Paste the title into the title field and the rest into the body.
3. **Before posting, fill in anything marked `«FILL IN»`.** Those are facts
   the diagnosing session holds and I do not — mostly the exact traceback for
   report 2. Posting a report with a placeholder in it is worse than posting
   nothing.
4. Report 1 is ready as it stands and is the one worth posting first.

Nothing here has been sent. No account of yours has posted anything.

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

Observed four times on 2026-09-04.

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

## Where the evidence lives

Wallos-side measurements and reasoning are in this repository:

* `docs/test-instance.md` §7.4 — the back-channel test, including the run that
  would confirm the refresh workaround end to end.
* Issue #144 — the relying-party half of report 1, and the fix.

The authentik-side measurements — worker task lists, timings, database
queries, source reading — were taken by the session administering the test
instance and are not reproduced here.
