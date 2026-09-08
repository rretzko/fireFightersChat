# Implementation Plan — FireFighters Chat Prototype

Companion to `docs/fire-department-texting-platform.md`. Update checkboxes as work lands; don't let this drift
from reality — it's the source of truth for "what's actually built" vs. the business plan's aspirational scope.

## Team / invite management — DONE (2026-09-08)

Not part of the original phased plan — added on request once the user hit the actual gap while testing Phase 3
(registered, created an org, had no way to add anyone else or know how roles get assigned).

- [x] `organization_invitations` table + `OrganizationInvitation` model (org-scoped, token-based, 7-day expiry).
- [x] `team` page (owner/admin-only, `OrganizationPolicy::manageTeam`) — list current team members with role
      badges, per-row actions menu (change role to owner/admin/sender, remove from team), pending-invitations
      list (resend/cancel). At least one owner is always enforced — the last owner can't be demoted or removed.
- [x] Invite flow: email + role (admin or sender only — inviting straight to owner isn't offered, to avoid
      accidentally granting ownership to an unregistered address). Sends a real `Mailable`
      (`OrganizationInvitationMail`, markdown, `log` driver locally per `MAIL_MAILER` — same "real pipeline,
      fake transport" pattern as `LogSmsGateway`) with an accept link.
- [x] `invitations/{token}` accept page — deliberately **outside** the `tenant` middleware group, since
      accepting is how a user *joins* a new org (no current tenant to require, and it might not match whichever
      org they're already in). Handles: email mismatch, expired, already-accepted, and already-a-member
      (idempotent, no duplicate pivot row) cases. An unauthenticated visitor hitting the link is sent to
      login/register and Laravel's normal "intended URL" mechanism returns them to the invitation afterward —
      verified this actually works for the *registration* path too, not just login (see
      `AcceptInvitationTest::test_an_unauthenticated_visitor_is_sent_to_login_and_returns_to_the_invitation_after_authenticating`),
      since Fortify's register/login responses both need to honor it for this to work with no custom code.

**A second, more general bug found while writing tests for this** (beyond the Phase-3 middleware-priority one):
calling `$model->update([...])` or `->save()` on a model instance that was originally fetched via
`Model::withoutGlobalScope(OrganizationScope::class)->...->first()` **does not actually bypass the scope for the
write** — `save()`/`update()` route through `newModelQuery()`, which re-applies every registered global scope
regardless of how the instance was originally loaded. With no tenant bound (exactly the situation these
scope-bypassing queries exist for), the write's `WHERE` clause silently matches zero rows — no exception, no
error, just a no-op. This is different from `Model::withoutGlobalScope(...)->whereKey($id)->update([...])` (a
query-builder update chained directly off the scope-bypassed builder), which **is** safe, because there's no
second query to reapply a scope to.

Found the hard way: `AcceptInvitationTest::test_a_matching_authenticated_user_can_accept_an_invitation` passed
the org-attach and current-org assertions but failed on `accepted_at` staying null — the transaction's `attach()`
and `save()` calls (safe patterns) worked, but `$invitation->update(['accepted_at' => now()])` (the unsafe
pattern) silently did nothing. Fixed in both places it existed:
- `resources/views/pages/invitations/⚡accept.blade.php` (`accept()`) — the bug's original discovery site.
- `app/Services/MemberCsvImporter.php` — same pattern, latent since Phase 2 (masked there because it's normally
  called from the members page, which *is* tenant-bound, so it happened to work in every path actually
  exercised so far — but the whole point of that service taking `$organization` explicitly is to also work
  from a tenant-less context, where this would have silently dropped every CSV-triggered *update* while still
  correctly *creating* new members).

**Audit rule going forward**: any place that does `Model::withoutGlobalScope(...)->...->first()` and later needs
to write to that instance must either (a) do the write as a continuation of the same scope-bypassed query
builder (`->whereKey($id)->update([...])`), or (b) bind the tenant first. Never `$instance->update()`/`->save()`
on a scope-bypassed instance outside a tenant-bound context.

## User (login account) phone numbers — DONE (2026-09-08, renamed same day)

Added on request to show a contact phone number on the `team` page — distinct from the `members.phone_number`
roster field, which is for SMS recipients and unrelated to login accounts.

- [x] `phone_number` nullable column on `users` (not required — most existing accounts have none).
- [x] Editable in profile settings (`settings/profile`) alongside name/email, using the same `PhoneNumber`
      validation rule + `PhoneNumberNormalizer::toE164()` normalization-on-save as the member roster form.
      Handles the Livewire quirk where a cleared text input arrives as `""`, not `null` — normalized to `null`
      explicitly before validation so clearing the field doesn't trip the format rule.
- [x] `User::formattedPhoneNumber(): ?string` — same `+1 (508) 555-0100` display convention as
      `Member::formattedPhoneNumber()`; the team page shows `—` when unset.
- [x] Verified live: profile settings saves and normalizes correctly, team page shows the formatted number for
      a user who has one and the `—` placeholder for one who doesn't, with no raw E.164 leaking either way.

**Renamed `users.phone_number` → `users.contact_phone_number` the same day**, prompted by the user noticing the
smell of two identically-named `phone_number` columns meaning genuinely different things: `members.phone_number`
is the tenant-scoped SMS destination (what Twilio texts), while this one is purely directory/contact metadata
on the login account, global like name/email, with zero involvement in the send pipeline — broadcasts always go
out via the *org's* Twilio number, never from an individual user's own phone, so there was never actually a
"per-org sender number" concept here to isolate. The rename addresses the real risk (same name inviting a
future mix-up in code) without touching `Member`, which has far larger surface area (CSV import, broadcast
fan-out, uniqueness constraints, many tests) for no benefit — see docs/fire-department-texting-platform.md §4
for the schema-level note. Migration: `2026_09_08_150000_rename_phone_number_to_contact_phone_number_on_users_table.php`
(a follow-up rename, not an edit to the original add-column migration, since it had already been run). Renamed
throughout: `User::formattedPhoneNumber()` → `User::formattedContactPhoneNumber()`, the profile-settings
Livewire property/validation key, and the team page column header ("Phone" → "Contact phone", to reduce
user-facing confusion with the Members page's phone column too, not just the code-level one).

Deliberately **not done**: no link between a `User` row and a `Member` row for the same person. If someone is
both a team admin and on their own org's SMS roster, they still have two independent phone numbers that could
drift with nothing surfacing that — noted as an open question, not a bug to fix now (see reference doc §10).

## Post-Phase-3 hotfix (2026-09-08): every button click 403'd for a real user

**Symptom** (reported by the user after registering + creating an org through the real UI, not tests): dashboard
loaded fine, sidebar showed "Members" and "Broadcasts" links, but clicking **any** button that calls a Livewire
component method — "Add member", "New broadcast", opt-out toggle, edit — returned a 403, even for the
organization's own owner.

**Root cause**: Livewire only replays a small, hardcoded allowlist of middleware
(`Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::$persistentMiddleware`) for its AJAX
component-update requests (`wire:click`, `wire:submit`, ...). A full page load (`GET /members`) runs the entire
route middleware stack — including our custom `tenant` alias — but a subsequent button click hits Livewire's own
internal update endpoint, which only re-runs middleware on that allowlist. `IdentifyTenant` wasn't on it, so
`Tenant::get()` was `null` for every such request, and every tenant-scoped policy check (`MemberPolicy`,
`BroadcastPolicy`) correctly — but unhelpfully — returned false. This is why the sidebar links were visible
(they render during a normal page load, which worked) while every action failed (those are AJAX calls, which
didn't).

**Why 88 passing tests didn't catch it**: `Livewire::test()`, used throughout Phases 1–3, calls
`withoutMiddleware()` internally (see `Livewire\Features\SupportTesting\RequestBroker`) — it never exercises
this mechanism at all. Every one of those tests works by manually calling `Tenant::set($organization)` before
invoking the component, which is realistic for verifying component *logic* but silently assumes the tenant
got bound some other way — exactly the assumption that was false in production.

**Fix**: register `IdentifyTenant` as Livewire persistent middleware in `AppServiceProvider::boot()`:
```php
Livewire::addPersistentMiddleware([IdentifyTenant::class]);
```
This is the officially documented mechanism for exactly this situation — custom auth/context middleware that
needs to run on every component request, not just the first one.

**Regression coverage added**: `tests/Feature/Http/LivewirePersistentMiddlewareTest.php` replays a genuine
Livewire AJAX round-trip through the real HTTP kernel — GET the page for a real rendered snapshot, then POST a
real `calls` payload to Livewire's actual (per-installation-hashed) update endpoint — the only way to actually
exercise this mechanism in a test. **Verified as a real regression guard**, not just a passing test: confirmed
it fails (403) with the fix commented out, and passes with it restored. Also caught a genuine test-methodology
trap along the way: Laravel's test client reuses the same application container across multiple requests within
one test method, so `TenantContext` (a singleton) leaked across the test's own GET and POST calls on the first
attempt, making the test pass unconditionally regardless of the fix — the test explicitly resets `Tenant::set(null)`
between the two requests to close that gap.

**Also fixed while investigating**: `MemberFactory` generated a fully random 10-digit phone number, which
occasionally produced one starting with 0 or 1 — invalid per `PhoneNumberNormalizer`'s NANP rules — making any
test that round-trips a factory-created member through the `PhoneNumber` validation rule flaky (failure rate
depends on Faker's random seed for that run). `MemberFactory::validPhoneNumber()` now always generates a
valid-format number. Confirmed via 5 consecutive full test-suite runs with no failures.

**If this class of bug recurs**: any *new* custom route-group middleware that a Livewire component action
depends on (another tenant-context concern, a feature flag gate, etc.) needs the same registration — it won't
work automatically just because it's applied to the page route.

## Phase 0 — Foundations

- [x] Install Flux Pro (`livewire/flux-pro` ^2.13, resolved 2.19.0) — repository + credentials configured in
      `auth.json` (gitignored, not committed).
- [ ] Decide + provision production database (Laravel Serverless Postgres or Laravel MySQL) on Laravel Cloud.
      Do this early — don't build up demo data on SQLite in the deployed environment (Gotcha #1).
- [ ] Add `twilio/sdk` via Composer.
- [ ] `declare(strict_types=1);` + PSR-4 conventions for all new classes (per standing project instructions).
- [ ] `composer dump-autoload` after any new model/factory/controller is created, before running PHPStan/tests.

## Phase 1 — Core Domain & Multi-Tenancy — DONE (2026-09-08)

- [x] Migrations: `organizations`, `organization_user`, `members`, `broadcasts`, `messages`, `inbound_messages`,
      `audit_logs`, plus `users.current_organization_id`.
- [x] Models + relationships + factories for the above. Role/status/direction fields use PHP enums
      (`OrganizationRole`, `MemberStatus`, `BroadcastStatus`, `MessageStatus`) rather than raw strings.
- [x] `BelongsToOrganization` trait / `OrganizationScope` global scope for tenant isolation — **fails closed**:
      if no tenant is bound, a scoped query returns nothing rather than every organization's data. This is
      stricter than originally planned (see reference doc §5) and deliberately errs toward "invisible" over
      "leaked" for any code path (console command, job) that forgets to bind a tenant.
- [x] Tenant context resolution: `TenantContext` singleton + `Tenant` static helper, bound per-request by the
      `IdentifyTenant` middleware (aliased as `tenant`). Users with no organization are redirected to
      `organizations.create`.
- [x] Policies: `OrganizationPolicy`, `MemberPolicy` (roster management restricted to owner/admin — the plan is
      explicit that senders don't see a phone-number roster), `BroadcastPolicy` (any org role can compose/view,
      matching the "unlimited senders" requirement).
- [x] **Tests that specifically try to leak data across two seeded orgs** — `tests/Feature/Tenancy/OrganizationIsolationTest.php`
      covers scoped queries, `find()` by ID, auto-stamping new records, fail-closed-with-no-tenant, and policy
      denial across orgs.
- [x] Org onboarding flow: `organizations/create` Livewire page — creates the org, attaches the creator as
      `owner`, sets it as their current organization, writes an audit log entry.

47/47 tests passing, PHPStan (level 7) clean, Pint clean.

## Phase 2 — Member Roster Management — DONE (2026-09-08)

- [x] Livewire component (Flux table) to list/add/edit/deactivate members within the current org —
      `members` route (`pages::members.index`), admin/owner-only per `MemberPolicy`. Search by name/phone,
      paginated (15/page).
- [x] Phone number normalization to E.164 on save — `PhoneNumberNormalizer` (US/NANP only, deliberately no
      international library yet) + `PhoneNumber` validation rule, applied both on the manual add/edit form and
      CSV import. Duplicate phone numbers within an org are rejected.
- [x] CSV import for initial roster load — `MemberCsvImporter` service (header row: `first_name`, `last_name`,
      `phone_number`), upserts by normalized phone number so a department can re-upload its whole roster to fix
      names. Per-row errors are collected and shown, not fatal to the batch. Wired into the page via
      `flux:file-upload` (Flux Pro).
- [x] Opt-out status visible and manually overridable by an org admin — toggle button per row, audit-logged
      (`member.opted_out` / `member.opted_in`).

**Bugs caught and fixed during this phase** (worth knowing about since they reveal a sharp edge in the fail-closed
tenant scope from Phase 1):
- `MemberCsvImporter` originally looked up existing members via `$organization->members()->...`, which goes
  through `OrganizationScope`. Since the importer is designed to run without an ambient tenant bound (so it's
  usable from console/queued contexts later), every lookup silently returned nothing and the "update" path was
  dead code — re-importing a roster attempted to re-insert every row and hit the unique constraint. Fixed by
  querying `Member::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)`
  explicitly. **Takeaway: any service that takes an explicit `Organization` instead of relying on `Tenant::get()`
  must bypass the scope deliberately — this will bite again in Phase 3/4 if not remembered.**
- The Livewire page template originally had three Blade root elements (the main content `<div>` plus two sibling
  `<flux:modal>` elements) — Livewire requires exactly one. Wrapped everything in a single outer `<div>`.

Verified by browser-equivalent means: no interactive browser tool was available this session, so the rendered
page was verified via an authenticated HTTP request (curl) against the real dev server — confirmed real member
rows, correct status badges/colors, working action buttons, modal and file-upload markup all render with no
PHP errors/warnings. This is not a substitute for actually clicking through it in a browser — do that before
demoing to a prospect.

47/47 → 73/73 tests passing, PHPStan (level 7) clean, Pint clean.

## Phase 3 — Messaging Pipeline (mocked send) — DONE (2026-09-08)

- [x] `SmsGateway` interface + `LogSmsGateway` implementation — logs what would've been sent and reports
      success, no real Twilio call. Bound in `AppServiceProvider` based on `config('sms.gateway')` (`log` today;
      Phase 4 adds a `twilio` case and a `TwilioSmsGateway`, one-line config change).
- [x] Livewire "compose broadcast" screen (`broadcasts` route) — any org role can send, matching the business
      plan's core "unlimited senders" requirement (`BroadcastPolicy`, unchanged from Phase 1).
- [x] Queued job (`SendBroadcastMessage`) fanned out via `Illuminate\Support\Facades\Bus::batch()` — one
      `Message` row + one batched job per active member, created by the `SendBroadcast` action.
      `->finally()` on the batch marks the broadcast `Sent` once every recipient job has run (`allowFailures()`
      so one bad send doesn't block the rest).
- [x] Broadcast detail view (`broadcasts/{broadcast}`) — status counts by message status visible to anyone who
      can view the broadcast; the full per-recipient table (names + phone numbers) is restricted to owner/admin,
      matching the same "no visible roster" rule as the member page. **Verified live** (see below): a sender
      sees the message body and a `sent: 3` summary but zero names or numbers; an owner sees the full table.
- [x] Rate limiting — `BroadcastRateLimiter` (per-user-per-minute + per-org-per-hour, both configurable via
      `config/sms.php`), checked before every send.
- [x] Audit log entry (`broadcast.sent`, with `recipient_count`) written by the `SendBroadcast` action itself,
      not the UI layer, so it fires regardless of caller.

**A systemic bug caught and fixed at the root, not patched per-route:** implicit Laravel route-model binding
(`Route::livewire('broadcasts/{broadcast}', ...)`) runs via `SubstituteBindings` middleware, which — by default —
executes *before* a route-group-only middleware like `tenant` that isn't in Laravel's built-in priority list.
That meant `{broadcast}` would try to resolve against `OrganizationScope` with no tenant bound yet, which fails
closed (see Phase 1) — so a perfectly valid broadcast in the viewer's own org would 404. Fixed globally in
`bootstrap/app.php` via `$middleware->prependToPriorityList(before: SubstituteBindings::class, prepend:
IdentifyTenant::class)`, so *every* future route using `{member}`/`{broadcast}`-style implicit binding on a
tenant-scoped model works correctly by default, rather than requiring each route to remember a workaround.
Locked in by `BroadcastShowTest::test_a_broadcast_from_another_organization_is_not_found`, which would have
failed (wrongly 404'd for a same-org broadcast, or worse) before this fix.

**Same fail-closed-scope lesson from Phase 2, applied consistently:** `SendBroadcast` and `SendBroadcastMessage`
both take explicit IDs/organization rather than relying on `Tenant::get()` — queued jobs run with no tenant
bound at all (there's no HTTP request), so every lookup inside them uses
`withoutGlobalScope(OrganizationScope::class)` and filters by the explicit ID instead. `AuditLog::record()`
wasn't used here for the same reason — it fills `organization_id` from `Tenant::get()`, which isn't guaranteed
bound — the action calls `AuditLog::create()` directly with `organization_id` supplied.

**Verified live**, not just via tests: seeded a real org/roster/broadcast through `php artisan tinker`, hit the
running dev server via authenticated curl requests (no browser tool available this session — see Phase 2 note),
and confirmed: broadcast index/show pages render with real data and correct status-colored badges, the
per-recipient table shows real names/phone numbers to an owner, a sender in the same org sees the aggregate
count but zero recipient details, and `/members` correctly 403s for that sender. Demo data was created and torn
down via tinker each time — nothing was left in the local dev database.

73/73 → 88/88 tests passing, PHPStan (level 7) clean, Pint clean.

## Phase 4 — Real Twilio Integration (in progress)

- [x] `TwilioSmsGateway` implementation; env-driven switch from `LogSmsGateway` (2026-09-08). Depends on the
      account's `MessageList` resource directly (`$client->messages`) rather than `Twilio\Rest\Client` itself —
      the SDK resolves `->messages` through magic `__get`/`__call` proxying, which fights standard mocking;
      `MessageList::create()` is a plain, non-final method that mocks cleanly. `AppServiceProvider` binds
      `Twilio\Rest\Client` and `MessageList` as lazy singletons (never instantiated unless `sms.gateway` is
      actually `twilio`), so staying on `log` needs no credentials at all.
- [x] Twilio phone number configured — one real number in `TWILIO_PHONE_NUMBER`, used as the fallback "from"
      when an organization has no `twilio_phone_number` of its own yet. The per-org column already exists
      (Phase 1 schema) and takes priority when set, so this isn't a hardcoded-forever single number.
- [ ] Twilio Messaging Service (recommended over a bare number once A2P 10DLC is registered — ties the number
      to the approved campaign).
- [ ] Outbound status-callback webhook endpoint + **signature validation**.
- [ ] Inbound-message webhook endpoint + **signature validation**.
- [ ] STOP/START/HELP keyword interception in the inbound webhook, updating `members.status`
      (Gotcha #5 — do this before any real number sends to real phones, even in internal testing).
- [ ] Reply routing: inbound message → resolve member → surface to original sender (in-app first; SMS-back-to-sender
      is a stretch goal, see open question in the reference doc).
- [ ] **A2P 10DLC brand + campaign registration — confirmed NOT started (2026-09-08).** The user believed this
      account was "already approved"; queried the Twilio API directly (`brandRegistrations`, `services` /
      Messaging Services) and found zero Brand Registrations and zero Messaging Services on the account. This
      needs to be started from scratch — real-world lead time ~1–3 days brand approval, ~10–15 days campaign
      review. Budget this before a live customer demo is needed.

**Live test send performed 2026-09-08** (with the user's explicit go-ahead): flipped `SMS_GATEWAY=twilio`,
sent one real broadcast through the actual app pipeline (`SendBroadcast` → `SendBroadcastMessage` job →
`TwilioSmsGateway`) to a real phone number the user provided. Result: **the integration works, delivery
doesn't (yet)**.
- Twilio's API accepted the send and returned a real message SID — confirms the code path (gateway, container
  wiring, per-org/fallback "from" number logic, job fan-out) all work correctly end to end.
- Fetching that message's status back from the Twilio API a few seconds later showed `undelivered`, error
  **30034: "US A2P 10DLC — Message from an Unregistered Number."** This is the carrier rejecting the message
  after Twilio accepted it — exactly the failure mode flagged as a risk before sending, now confirmed with a
  real error code rather than left as a theoretical concern. [Twilio's docs for 30034](https://www.twilio.com/docs/api/errors/30034)
  confirm this is specifically the unregistered-A2P-number rejection, not some other delivery problem.
- **`SMS_GATEWAY` reverted back to `log` afterward** — a deliberate call, not an oversight. Leaving it on
  `twilio` in this state would mean every future broadcast *looks* successful in the app (Twilio's API always
  accepts the send, so `messages.status` would show `sent`) while silently never reaching any recipient — a
  worse trap than staying on `log`, which at least doesn't pretend to deliver. Flip it back once A2P
  registration is actually complete; nothing else needs to change.
- Demo org/member/broadcast/message rows created for this test were deleted afterward — nothing was left in
  the database from it, only this record of what happened.

Real credentials live only in `.env` (confirmed gitignored before writing them) — `.env.example` got matching
placeholder keys (`SMS_GATEWAY`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`), all empty/`log`
default, safe to commit.

## Phase 5 — Polish for Prospective-Customer Demo

- [x] Format phone numbers as `+# (###) ###-#### x##` everywhere one is displayed — `PhoneNumberNormalizer::format()`
      + `Member::formattedPhoneNumber()`, applied on the members roster table, the edit-member form pre-fill,
      and the broadcast-detail recipient table (2026-09-08). No extension field exists yet, so the `x##` suffix
      is unused for now. Storage is unaffected — still E.164 in the database.
- [ ] Flux Pro components applied consistently across the app (tables, nav, forms) per UI standardization goal.
- [ ] Organization-switcher UI if the demo needs to show multiple tenants side-by-side to a prospect.
- [ ] Seed script / demo data for a realistic-looking fire department org (names, roster size ~50-100).
- [ ] Basic dashboard: recent broadcasts, delivery stats, roster size, opt-out count.
- [ ] Security pass against the Section 8 checklist in the reference doc before showing this to anyone outside
      the team.

## Explicitly Deferred (post-prototype, not needed to prove the concept)

- Per-organization dedicated Twilio number/campaign automation (self-service onboarding of a *second* real org).
- Billing/subscription (Stripe or similar) — pricing model exists on paper only for now.
- SOC 2 / HIPAA-adjacent compliance posture (only relevant if/when targeting hospitals per the business plan).
- AI agent-team operations tooling (business plan §9/§10) — that's a founder-operations concern, not part of
  the product itself.
