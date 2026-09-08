# FireFighters Chat — Product & Build Reference

Living reference doc for the prototype. North star source: `fire-department-texting-business-plan.pdf`
(business plan assumed Python/Django/Postgres — this build intentionally substitutes Laravel/Livewire/Flux/PHP
per the builder's expertise; the *product* requirements from the plan still apply).

Last updated: 2026-09-08

---

## 1. Vision (condensed from the business plan)

A mass **one-to-one** texting platform for volunteer fire departments and similar membership organizations.

- **Core requirement no competitor cleanly serves:** any authorized member can compose a message that goes
  out to every other member as their own individual, private text — not a group thread — with **no per-seat
  fee for additional senders**.
- **Why now:** legacy tools (e.g. CodeMessaging.net) send via carrier email-to-SMS gateways
  (`number@txt.att.net` etc.), which carriers are actively shutting down (T-Mobile ~Dec 2024, AT&T
  June 17 2025, Verizon scheduled March 31 2027). Since Feb 2025, unregistered bulk SMS is blocked outright —
  legitimate senders need a registered **A2P 10DLC** brand + campaign.
- **Target market:** ~24,700 all-volunteer / mostly-volunteer US fire departments. Beachhead: CodeMessaging.net's
  existing "hundreds" of fire/police/hospital/private-org customers who are being forced to migrate right now.
- **Pricing anchor from plan:** ~$30/month per organization flat fee, unlimited senders. Direct per-tenant Twilio
  cost estimated at $10–15/mo, implying 50–65% gross margin.
- **Multi-tenant by design:** each customer organization is an isolated tenant with its own Twilio number and
  its own A2P 10DLC registration (tied to *their* EIN, not the platform's).
- **Explicitly out of scope:** this is not a 911/CAD dispatch-alerting tool (that's Active911/First Due/etc.
  territory).

## 2. Prototype Scope & Key Decisions

Decisions made 2026-09-08, recorded here so they aren't re-litigated silently later:

| Decision | Choice | Rationale |
|---|---|---|
| Tenancy | **Multi-tenant from day one** | The product's core pitch is multi-org SaaS; a prospective customer demo should show org isolation, not a retrofit. |
| Member UX | **Hybrid** — authorized members log into the Livewire web app to *compose/broadcast*; all members *receive and reply* purely via SMS, no app needed on their end | Matches the business plan's actual product shape and is easy to demo. |
| Twilio sending | **Mocked/stubbed initially** behind a `SmsGateway` interface; swap to live Twilio once credentials + A2P 10DLC exist | Avoids burning real SMS spend/credits during UI build-out and avoids being blocked on 10-15 day campaign approval. |
| Local database | SQLite | Zero-setup local dev, matches user's stated preference. |
| **Production database** | **Reversed from initial "SQLite everywhere" plan — see Gotcha #1 below.** Use a managed DB (Laravel Serverless Postgres or Laravel MySQL) on Laravel Cloud. | Laravel Cloud's filesystem is ephemeral; SQLite data would be silently lost on every redeploy/restart. Confirmed via [Laravel Cloud docs](https://cloud.laravel.com/docs/knowledge-base/sqlite), Sept 2026. |
| UI components | **Flux + Flux Pro** for all UI (per standing instruction: always use Flux components) | Flux Pro adds richer components (tables, charts, command palette, etc.) for a more polished demo. Installed 2026-09-08 (`livewire/flux-pro` 2.19.0). |

## 3. Tech Stack

- **Framework:** Laravel 13, PHP ^8.3
- **UI:** Livewire 4 + Flux 2.13 (+ Flux Pro once licensed) + Tailwind
- **Auth:** Laravel Fortify (already scaffolded) — passkeys + 2FA support present in starter kit
- **DB (local):** SQLite
- **DB (Laravel Cloud):** Laravel Serverless Postgres (Neon-backed) or Laravel MySQL — **decision pending, see open questions**
- **SMS:** Twilio (Programmable Messaging, A2P 10DLC) via `twilio/sdk`, behind an app-level gateway interface
- **Queue:** `database` driver (already configured) — message fan-out must be queued, never sent synchronously in the request cycle
- **Hosting:** Laravel Cloud (already deployed: https://firefighterschat-production-8vszxu.laravel.cloud/)
- **Repo:** GitHub (already linked)

## 4. Data Model (proposed)

```
organizations
  id, name, slug, status, twilio_phone_number, twilio_messaging_service_sid,
  a2p_brand_status, a2p_campaign_status, timezone, created_at, updated_at

users                          -- people who can LOG IN (senders/admins)
  id, name, email, contact_phone_number (nullable, directory info — deliberately NOT named phone_number,
    to avoid confusion with members.phone_number below, which is a different concept), password, ... (Fortify defaults)

organization_user              -- membership + role, pivot
  organization_id, user_id, role (owner|admin|sender), joined_at

organization_invitations       -- pending "join this org" invites (email + role, token-based, 7-day expiry)
  id, organization_id, email, role, token, invited_by_user_id, accepted_at, expires_at

members                        -- the full roster who RECEIVE texts (not all have logins)
  id, organization_id, first_name, last_name, phone_number (E.164), status (active|opted_out|invalid),
  opted_out_at, created_at

broadcasts                     -- one "send" event
  id, organization_id, sender_user_id, body, status (queued|sending|sent|failed), created_at

messages                       -- one outbound SMS to one member (fan-out row)
  id, broadcast_id, member_id, twilio_sid, direction (outbound), status (queued|sent|delivered|failed|undelivered),
  error_code, sent_at, delivered_at

inbound_messages               -- replies routed privately back to the original sender
  id, organization_id, member_id, broadcast_id (nullable, best-effort correlation), body,
  twilio_sid, received_at

audit_logs
  id, organization_id, user_id, action, subject_type, subject_id, meta(json), created_at
```

Notes:
- `members.phone_number` is the tenant-isolation-sensitive field — every query must be scoped by `organization_id`.
- Consider a `current_organization_id` on `users` (or session) for the active-tenant context, since a user could
  theoretically belong to more than one org (e.g. a captain who's also on mutual-aid).
- Postgres Row-Level Security from the original plan doesn't have a direct Laravel equivalent — see Section 5.
- **Two `phone_number`-shaped fields exist for genuinely different reasons — don't conflate them.**
  `members.phone_number` is the tenant-scoped SMS destination (what Twilio actually texts); `users.contact_phone_number`
  is directory metadata for a login account (global, like name/email, and uninvolved in sending — broadcasts
  always go out via the *org's* Twilio number, never an individual user's own phone). Named differently on
  purpose after a naming-collision smell was flagged (2026-09-08) when the two briefly shared the column name
  `phone_number`. There's intentionally no link between a `User` row and a `Member` row for the same person —
  if someone is both a team admin and on their own org's roster, the two numbers are independent and could
  drift; see the open question in Section 10.
- **UI convention:** phone numbers are always *stored* as E.164 (`+15085550100`), but whenever a phone number is
  *displayed* to a user, it's formatted as `+# (###) ###-#### x##` (e.g. `+1 (508) 555-0100`; the `x` extension
  suffix is reserved for if/when an extension field ever exists — none does today). Implemented 2026-09-08 via
  `PhoneNumberNormalizer::format()`, exposed as `Member::formattedPhoneNumber()` and `User::formattedContactPhoneNumber()`
  (the latter returns `null` if the user hasn't set one — the team page shows `—` in that case). Applied on the
  members roster table, the edit-member form pre-fill, the broadcast-detail recipient table, the profile
  settings phone field, and the team page. Apply the same accessor to any new view that shows a phone number —
  don't reformat ad hoc per view.

## 5. Multi-Tenancy Approach

The business plan leans on Postgres RLS as a database-level safety net under the application code. Laravel/SQLite/MySQL
doesn't have an equivalent primitive, so the safety net has to be built in the application layer instead:

1. **Global Eloquent scope** on every tenant-scoped model (`Member`, `Broadcast`, `Message`, `InboundMessage`,
   `AuditLog`) that automatically filters by the current tenant context — applied via a trait (`BelongsToOrganization`),
   not manually repeated in every query.
2. **Route/middleware-level tenant resolution** — resolve the active organization from the authenticated user's
   session/current-org selection, bind it into the container, and have the global scope read from there.
3. **Policies** on every action (`send`, `viewMember`, `manageOrg`) that explicitly check `member->organization_id ===
   $user->currentOrganization->id` even though the scope should already prevent cross-tenant access — defense in depth.
4. **Automated test coverage that specifically tries to leak data across tenants** (see Gotcha #3) — this is the
   closest thing to the RLS "safety net" the plan describes, since Laravel has no DB-level enforcement by default.
5. **Fails closed, not open.** If no tenant is bound (e.g. a console command or queued job that forgot to set
   one), `OrganizationScope` returns *zero rows* rather than skipping the filter. The alternative (no tenant
   bound = unfiltered = every org's data) is a much worse failure mode for a bug to fall into silently.
   Implemented 2026-09-08.

## 6. Messaging Pipeline

1. Authenticated member composes a message in the Livewire UI → creates a `Broadcast` row (status `queued`).
2. A queued job fans it out: one `Message` row per active, non-opted-out `Member` in the org, dispatched to
   individual queued jobs that call the `SmsGateway` (mock or real Twilio) per-recipient — never a synchronous loop
   in the web request.
3. Twilio status callback webhook updates `messages.status` (sent/delivered/failed/undelivered).
4. Inbound SMS webhook receives a reply → looks up the `Member` by phone number (scoped within that org's Twilio
   number) → creates an `InboundMessage` → routes it privately back to the original broadcast's sender (e.g. in-app
   notification / future: reply-by-SMS-back-to-sender).
5. `STOP`/`START`/`HELP` keywords are intercepted by the inbound webhook **before** normal processing and update
   `members.status` — this is a legal requirement, not optional (see Gotcha #5).

**Steps 1–2 implemented 2026-09-08**: `SendBroadcast` action creates the `Broadcast` + `Message` rows, then fans
out via `Bus::batch()` — one `SendBroadcastMessage` job per active member, `->finally()` marks the broadcast
`Sent`. Steps 3–5 (Twilio webhooks, opt-out interception) are Phase 4, since they require a real Twilio
integration to receive anything from.

## 7. Twilio Integration

- Wrap Twilio behind an `SmsGateway` interface (`send(Member $member, string $body): SendResult`) with two
  implementations: `LogSmsGateway` (writes to log/DB instead of sending — used until real credentials exist) and
  `TwilioSmsGateway`. Bind via config so switching is a one-line env change, not a code change.
  **Both implemented** — `SmsGateway` + `LogSmsGateway` 2026-09-08, `TwilioSmsGateway` the same day once the
  user provided a Twilio Account SID/Auth Token/phone number. `config('sms.gateway')` still defaults to `log`
  (unchanged in `.env`) — flipping to `twilio` is a one-line env change whenever the user says go, but that
  switch is being left to an explicit decision rather than flipped automatically, since it has a real-world
  side effect (actual texts, actual cost) the first time someone clicks "send." Tested against a mocked
  `MessageList` (Twilio's send resource) — no real API calls happen in the test suite.
- Each **organization** eventually gets its own Twilio phone number + Messaging Service + A2P 10DLC campaign
  (per the business plan's per-tenant registration model). For the prototype, a single shared Twilio number/sandbox
  is fine as long as the data model already supports per-org numbers (don't hardcode a single number app-wide).
  **`TwilioSmsGateway` already checks `organization.twilio_phone_number` first**, falling back to the one
  shared number in `TWILIO_PHONE_NUMBER` — so a second org can get its own number later with no code change.
- A2P 10DLC brand approval: ~1–3 business days. Campaign review: ~10–15 days (as of the business plan's research).
  **Treat this as onboarding lead time, not something the prototype needs to complete** — mock sending until it clears.
  **Confirmed NOT started, 2026-09-08** — queried the Twilio API directly for this account (brand registrations,
  messaging services); found zero of either. A live test send to a real phone confirmed the practical consequence:
  Twilio's API accepts the message (real SID returned) but the carrier rejects actual delivery with error 30034
  ("US A2P 10DLC — Message from an Unregistered Number"). See Gotcha #2.

## 8. Security & Compliance Checklist (from business plan §7)

- [ ] HTTPS/TLS everywhere (Laravel Cloud handles this by default — confirm)
- [ ] Encryption at rest for the production database (managed Postgres/MySQL — confirm provider default)
- [ ] Least-privilege DB user for the app (not a superuser/admin connection)
- [ ] Password hashing via bcrypt/argon2 — already default in Laravel/Fortify
- [ ] Rate limiting on send actions (per-user and per-org, to bound Twilio spend and abuse)
- [ ] Audit logging of who sent what, when
- [ ] Automatic STOP/START/HELP opt-out handling — **legally required**, not a nice-to-have
- [ ] Twilio webhook signature validation on both the status-callback and inbound-message endpoints

## 9. Gotchas — Resolve Before Any Real Production Use

Ranked by severity.

1. **SQLite will silently lose data on Laravel Cloud.** Laravel Cloud's filesystem is ephemeral — it resets on
   every redeploy, sleep/wake cycle, and infra migration. A SQLite file living on disk there is not durable.
   [Confirmed in Laravel Cloud's own docs.](https://cloud.laravel.com/docs/knowledge-base/sqlite) **Action:**
   use SQLite for local dev only; provision Laravel Serverless Postgres or Laravel MySQL for the deployed
   environment before any data anyone cares about is entered. This reverses the original "SQLite everywhere"
   plan — flagging it now before real demo data gets lost.
2. **Real sending is built and proven to work — real delivery is not, until A2P 10DLC registration exists.**
   `TwilioSmsGateway` is implemented and confirmed working at the integration level (2026-09-08): a live test
   broadcast through the actual app pipeline got a real Twilio message SID back, no errors. But this account has
   **zero Brand Registrations and zero Messaging Services** (confirmed via the Twilio API, not assumed) — the
   user believed registration was already done; it hadn't been started. The same test message came back
   `undelivered` with error 30034 ("US A2P 10DLC — Message from an Unregistered Number") when its status was
   checked a few seconds later — Twilio's API silently accepts unregistered sends and only the *carrier*
   rejects them, so this genuinely looks like success unless something checks delivery status afterward.
   `SMS_GATEWAY` is back to `log` on purpose after this test — leaving it on `twilio` in this state would mean
   every future broadcast looks successful in the app while silently reaching nobody. Registration needs to be
   started from scratch (~1–3 days brand, ~10–15 days campaign) before flipping back for anything beyond a
   controlled internal test like this one.
3. ~~Multi-tenant data isolation is unproven.~~ **Resolved 2026-09-08** — `BelongsToOrganization` /
   `OrganizationScope` enforce it (fail-closed, see Section 5), and `tests/Feature/Tenancy/OrganizationIsolationTest.php`
   specifically asserts org A can never see org B's members/broadcasts, including by direct ID lookup and via
   policies. Re-run and extend this test file as new tenant-scoped models are added.
4. **Twilio webhooks are unauthenticated by default.** Both the delivery-status callback and inbound-message
   webhook endpoints must validate Twilio's `X-Twilio-Signature` header, or anyone can forge delivery receipts
   or fake inbound replies.
5. **STOP/opt-out handling is a legal requirement, not a feature to defer.** Carriers will penalize (and can
   shut down) a messaging campaign that doesn't honor opt-outs. This needs to exist before any real numbers
   are texted, even in a pilot.
6. **Per-tenant Twilio number/campaign model isn't built yet.** The business plan requires each org to have
   its own number and 10DLC registration under its own EIN. If the prototype hardcodes a single shared number,
   that's fine for a demo but is a real gap before a second paying customer.
7. ~~No rate limiting yet.~~ **Resolved 2026-09-08** — `BroadcastRateLimiter` enforces per-user-per-minute and
   per-org-per-hour limits (`config/sms.php`), checked before every broadcast send.
8. **File storage disk is `local`.** Same ephemeral-filesystem issue as SQLite applies to any future file uploads
   (e.g. MMS attachments, CSV roster imports/exports) — not urgent today since none exist yet, but don't let one
   get added without also switching `FILESYSTEM_DISK` to S3-compatible object storage.
9. **No company/product name chosen** (per business plan §11) — cosmetic for a prototype, but affects Twilio
   brand registration (the A2P brand name should match the legal/DBA name used).
10. ~~Flux Pro isn't installed yet.~~ **Resolved 2026-09-08** — `livewire/flux-pro` 2.19.0 installed via the
    user's license; credentials live in `auth.json` (gitignored, not committed to the repo).
11. ~~Implicit route-model binding on tenant-scoped models could 404 valid records.~~ **Resolved 2026-09-08** —
    `SubstituteBindings` (Laravel's route-model-binding middleware) ran *before* `IdentifyTenant` by default,
    since a custom route-group middleware isn't in Laravel's built-in priority list. Any route using
    `{member}`/`{broadcast}`-style binding on a tenant-scoped model would have resolved with no tenant bound —
    which fails closed (Gotcha/decision #5 above) — and 404'd even for the viewer's own records. Fixed globally
    via `$middleware->prependToPriorityList(...)` in `bootstrap/app.php`, not per-route. If this ever gets
    reverted or a new tenant-scoped route param is added, `BroadcastShowTest::test_a_broadcast_from_another_organization_is_not_found`
    (and the "own broadcast is reachable" test next to it) will catch a regression.
12. ~~Every Livewire button click 403'd for real users despite tests passing.~~ **Resolved 2026-09-08** —
    Livewire only replays a hardcoded middleware allowlist for its AJAX component-update requests (button
    clicks, form submits); a full page load runs the real route middleware stack, but those AJAX calls don't,
    unless the middleware is explicitly registered via `Livewire::addPersistentMiddleware()`. `IdentifyTenant`
    wasn't registered, so `Tenant::get()` was null for every action, and every tenant-scoped policy check failed
    — even for the org's own owner. `Livewire::test()` (used throughout the test suite) can't catch this class
    of bug because it disables middleware entirely; the regression test that does catch it
    (`tests/Feature/Http/LivewirePersistentMiddlewareTest.php`) replays a real Livewire AJAX round-trip through
    the actual HTTP kernel instead. See `docs/implementation-plan.md` "Post-Phase-3 hotfix" for the full
    writeup — worth reading if this resurfaces, since the failure mode (sidebar links visible, every click
    403s) is non-obvious.
13. **`$model->update()`/`->save()` on a scope-bypassed instance silently no-ops with no tenant bound.**
    `Model::withoutGlobalScope(OrganizationScope::class)->where(...)->first()` correctly bypasses the scope for
    the SELECT, but a later `$instance->update([...])`/`->save()` on that same object goes through
    `newModelQuery()`, which **re-applies** the scope — and with no tenant bound (the exact situation these
    bypassing queries exist for), the write's `WHERE` matches zero rows and silently does nothing. No exception,
    no error. Caught while building team/invite management (2026-09-08): the invitation-accept page's `attach()`
    and `save()` calls worked, but `$invitation->update(['accepted_at' => now()])` silently never persisted.
    Also found the identical latent bug in `MemberCsvImporter` (masked there because it's only ever been called
    from a tenant-bound context so far). **Fix pattern**: continue the write on the same scope-bypassed query
    builder (`Model::withoutGlobalScope(...)->whereKey($id)->update([...])`) instead of calling `->update()`/
    `->save()` on the fetched instance. See `docs/implementation-plan.md` "Team / invite management" for the
    full writeup and the audit rule to follow for any new code in this shape.

## 10. Open Questions / Deferred (from business plan §11, still relevant)

- Final production DB choice: Laravel Serverless Postgres vs. Laravel MySQL on Laravel Cloud.
- Whether replies route back to the sender via in-app notification, SMS-back-to-sender, or both.
- Whether the prototype needs multi-org switching UI (a user in 2 orgs) or single-org-per-user is fine for the demo.
- Pricing/packaging isn't a prototype concern but keep the data model able to express it (plan flag, seat exempt-status, etc.) later.
- Should a `User` (login account) ever link to a `Member` (SMS roster) row for the same real person within an
  org? Today they're fully independent — a team admin who's also on their own org's texting roster has two
  separate phone numbers (`users.contact_phone_number` and a `members.phone_number` row) with nothing keeping
  them in sync. Raised 2026-09-08 alongside the `contact_phone_number` rename; not acted on since it's a real
  design decision (auto-link on matching number? explicit opt-in? leave separate forever?), not an obvious fix.

---

See `docs/implementation-plan.md` for the phased build plan and current status.
