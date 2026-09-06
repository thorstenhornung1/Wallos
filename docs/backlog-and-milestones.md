# Wallos Fork — State & Backlog Analysis

_Working document, 2026-09-06. Not a public commitment; internal planning for milestone structure._

## Released
- **5.13.0** — Frankfurter v2, instance-config notifications (Telegram/Pushover/ntfy/Gotify), OIDC session-lifetime + back-channel/logout hardening, PKCE, https/issuer verification, remember-me correctness, image −25%, FPM ondemand.
- **5.14.0** — **OIDC Session Authority v2 phases 1+2** (provider authoritative for the whole session lifetime; the idle-gap refresh-oracle closed, `prompt=none` silent revalidation; **live-proven in QA**), server-side enforcement of IdP-managed fields (#156), **Web Push** (#162), **currency i18n via Unicode CLDR** (#163) + seed-time localization (#160) + first-admin/opt-in localizer (#164), the #147 PostgreSQL admin fixes, the `dev/bind-audit` gate (#157).

## On main, unreleased (next release candidate)
- Test-harness isolation fixes (#146/#148/#145) — worktree-stale sandbox, subprocess DB+session isolation, semgrep empty-scan gate.
- #141 — fixer direct path tries https first, warns on cleartext http.
- #129 — container starts on hosts without IPv6.
- #158 — subscription logo fetch names the failure reason and logs it.
- #138 — renew.php duplicate UPDATE removed.
- Localizer discovery banner (#165).
- **OIDC v2 Phase 3a** (ID-token validator + `(iss,sub)` identity + Secure cookies) merged; **Phase 3b** (remember-me as hashed resume handle) ready to merge.

## OIDC Session Authority v2 — phase status (milestone #15)
- **Phase 1** ✅ close the refresh-oracle (four-state guard, coverage boundary, fail-closed). LIVE-proven.
- **Phase 2** ✅ silent `prompt=none` resume guard + #159 (XHR reauth). LIVE-proven.
- **Phase 3a** ✅ ID-token validator (WP2), `(iss,sub)` + UserInfo-sub-equality (WP3), Secure cookie (§15).
- **Phase 3b** ✅ (pending merge) remember-me as hashed resume handle (WP6).
- **Phase 4** ⏳ jti + replay cache + mandatory exp on logout tokens (WP8); one-time legacy-session revoke/cleanup (WP10 — also clears the inert pre-v2 "zombie" rows).
- **#166** ⏳ a distinct login-page message for a central logout (`login_required`).
- Deferred (gold-plating): refresh-token encryption at rest, `__Host-` cookies, the normalized `oidc_identities` table, SSF/CAEP.

## Fork differentiators (vs upstream ellite/Wallos)
PostgreSQL dual backend (DB boundary + dual-backend test harness) · rootless/hardened container · the OIDC/SSO suite · Web Push · Frankfurter (keyless) + CLDR currency i18n · seed-time i18n + localizer · the dev audit gates (db-boundary, write-audit, bind-audit, sh/js-audit, container-modes) + the trend benchmark.

## Open backlog, by theme

### A. Write integrity — "no failure reported as success"
The family where a write's failure is decoupled from the reported result, or a write happens twice / silently.
- **#87** — sweep every write path for statements whose failure is reported as success (the umbrella).
- **#137** — fifteen writes followed by a success response that never consulted them.
- **#139** — a third number in the write audit (discarded write + success response on the same branch).
- **#136** — a skipped startup run erases the report of the run that did the work.
- (#138 landed as part of this family.)

### B. Currency correctness & data integrity
- **#149** — a held rate survives a main-currency change in the old base, then is silently wrong.
- **#143** — changing the main currency reports success when no rate was converted. _(upstream)_
- **#142** — saving a currency API key costs two provider requests and replaces the key without a transaction.
- **#133** — currency codes are free text: an invented code is accepted and converts at 1:1. _(upstream)_
- **#134** — what an unused currency actually costs, and whether the perf note still holds.

### C. Complete i18n coverage (milestone #14) — nearly done
Remaining: **#130** (notification texts hardcoded English) + **#160 Phase 2** (the ~122 keys missing per non-en/de locale, the half-populated `category_*` keys). The CLDR/localizer/banner all landed here.

### D. Polish / nice-to-have
- **#131** — CSV export breaks currency signs and unquoted fields.
- **#132** — long amounts push the subscription row out of alignment.

### E. Epics (big, already grouped)
- **Price history** (#107–113): a subscription's price is one mutable number; editing rewrites the past. A price-history table, lifetime-spend correctness, price-change display.
- **Shared workspaces & cost sharing** ("J", #60–77): workspaces, membership, invitations, payment sources with owners, cost allocations/splits, beneficiaries — a major multi-user feature.

## Existing milestones
- **#14 Complete i18n coverage** — near done (theme C).
- **#15 OIDC Session Authority v2** — in progress (Phase 4 + #166).
- Label groups already in the tracker: **Price history** (#107–113), **J — Shared workspaces and cost sharing** (#60–77).

## Milestone plan (decided 2026-09-06)

Priority order — each layer rests on the one below: **finish-in-flight → foundation-correctness → self-contained feature → product-defining feature.**

1. **OIDC Session Authority v2** (#15) — _in progress._ Finish Phase 4 (jti/replay + legacy sweep) + #166. Security, and nearly done; a half-hardened auth path is worse than finishing it.
2. **Complete i18n coverage** (#14) — _near done._ #130 + #160 Phase 2 (the ~122 keys per non-en/de locale). Small remaining.
3. **Write integrity** (#16) — #87, #137, #139, #136 — AND **Currency correctness & data integrity** (#17) — #149, #143, #142, #133, #134. Kept **separate** (a generic write-audit sweep vs. the currency domain). The foundation: silent wrong data is the worst bug class, best ROI, much of it upstream-relevant, and the epics build on the data these fixes make trustworthy.
4. **Price history** (#13) — a scoped data-model addition (historize prices instead of one mutable number); depends on correct currency handling, so after #17; before workspaces because it is self-contained and does not reshape the product.
5. **Shared workspaces & cost sharing** (#10) — the largest, product-defining change (single-user → multi-user cost sharing); last, on a correct/stable base.

Off the critical path (opportunistic, not scheduled): Performance (#12), C — additional shared integrations (#3), G — optional scale-out (#7). Polish (#131 CSV export, #132 row alignment) — ungrouped, pick up alongside.

**Decisions (2026-09-06):** Currency correctness and Write integrity are SEPARATE milestones. Price history before Shared workspaces. The priority order above is confirmed.
