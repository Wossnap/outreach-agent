# Outreach Agent — build log

## 2026-08-12 — Initial build (greenfield)

Plan: AI-drafted, human-approved outreach system. API ingest by tag → Claude drafts per-step →
approval queue → randomized Gmail sending → reply/bounce handling → domain health monitoring.
Full plan: ~/.claude/plans/piped-finding-truffle.md

### Checklist
- [x] Phase 0 — Laravel 13 + Breeze (Livewire) + Sanctum + google/apiclient + Docker (app :8010, postgres :5433, queue worker, scheduler)
- [x] Phase 1 — data model (domains, mailboxes, automations, sequence_steps, contacts, enrollments, messages, replies, suppressions, health_checks, activity_logs), ApiAuth middleware, POST /api/contacts ingest
- [x] Phase 2 — Automations CRUD, AnthropicDrafter (claude-sonnet-5, strict JSON), DraftEmailJob
- [x] Phase 3 — Approval queue (inline edit, approve/reject/bulk) + SendScheduler (second-granular jitter, send windows, daily caps, warmup ramp, timezone aware)
- [x] Phase 4 — Google OAuth mailbox connect, GmailSender (RFC 2822 MIME, In-Reply-To/References threading), SendEmailJob (tries=1), atomic-claim dispatch command, Mailboxes page
- [x] Phase 5 — AdvanceSequences, ReconcileStuckMessages, RefreshMailboxTokens, schedule registry in bootstrap/app.php
- [x] Phase 6 — Gmail history polling, ReplyClassifier (bounce DSN/opt-out phrases/OOO), InboundProcessor (stop-on-reply, suppress), EnrollmentStopper, Replies inbox
- [x] Phase 7 — DNS auth checks (SPF/DKIM/DMARC), DNSBL (Spamhaus DBL/SURBL/URIBL), hourly engagement evaluation with auto-pause, Health page
- [x] Phase 8 — Contacts index (house filter/sort/URL-persistence standard via WithIndexTable), Activity page with retry, API keys page, README + docs/google-cloud-setup.md

### Review (2026-08-12) — DONE
- 125 tests / 332 assertions green (`php artisan test`), in-memory SQLite.
- Key design decisions:
  - Sends never blind-retry (duplicate-email risk) — reconciler heals rows with a gmail_message_id, fails the rest to a manual Retry button on /activity.
  - Mailbox pinned per enrollment for whole sequence (threading + consistent From).
  - Proxies/IP health intentionally out of scope: Gmail API sends from Google IPs; health = domain DNS auth + blocklists + engagement, with auto-pause.
  - Paused mailboxes still poll inbound (replies must stop sequences) — only disconnected ones don't.
- Untracked-files trap (lessons.md): everything committed phase-by-phase in git from the start.
- NOT yet done (needs Sean): fill .env (ANTHROPIC_API_KEY, GOOGLE_CLIENT_ID/SECRET — see README), Google Cloud project per docs/google-cloud-setup.md, register dashboard user, live end-to-end send test with a real mailbox.

## 2026-09-20 — Leads page, drafter guardrail, approvals redesign

Branch `feature/leads-page-approvals`, cut from `origin/feature/lead-enrichment` (local `main` was
behind; the Leads page and the optional-email model only exist on that branch).

### 1. Leads page
- [x] Migration: `category`, `niche`, `company_url` on contacts (+ backfill from `extra.*`)
- [x] Model fillable; API push accepts + resource exposes the three fields; ingest never blanks a known value
- [x] Filters panel open by default
- [x] Select2-style multi-select Blade component (Alpine): Address, Enrollment status, Category, Niche
- [x] Hide `not_found` leads by default; "Include leads with no address found" toggle; explicit "Not found" selection wins
- [x] Category + Niche columns, sortable
- [x] Name → `profile_url`, Company → `company_url` links when present
- [x] Tests

### 2. Drafter
- [x] Prompt guardrail: no unrequested asks/offers/CTAs ("Want me to send it over?"), no P.S.
- [x] Test asserting the guardrail is in the prompt

### 3. Approvals
- [x] Compact view by default (lead + automation + checkbox, then the email as text), full/edit view toggle
- [x] Sticky header row: select-all, Approve selected, Reject selected
- [x] `bulkReject` (stops enrollments like single reject)
- [x] Tests

### Verification
- [x] `php artisan test` green against local docker postgres (never RDS — local .env points at it)

### Review (2026-09-21) — DONE
- 485 tests / 1207 assertions green against local docker Postgres (`DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=postgres DB_PASSWORD=postgres php artisan test`).
- Browser walkthrough with Playwright against a throwaway `outreach_demo` DB: multi-select search/tick/chip-remove, URL persistence, not-found hidden by default, LinkedIn links, sticky approvals header, bulk reject note, per-row Edit, full view. No JS errors.
- Migration backfill verified by rolling back and re-running against a contact carrying `extra.linkedin.{category,niche,company_url}`.
- Not done: Scribe docs (`public/docs`) not regenerated; nav label still says "Contacts" for the Leads page; nothing committed.
- Warning: local `.env` points at an AWS RDS host. Never run `php artisan migrate`/`test` locally without DB_* overrides to the docker Postgres.

## 2026-09-21 — Open tracking, Gmail Postmaster spam rate, dashboard analytics

Plan: ~/.claude/plans/i-want-to-add-fluttering-crown.md (same branch, still uncommitted).

- [x] Open tracking: `open_token`/`first_opened_at`/`last_opened_at`/`open_count` on messages; token assigned in SendEmailJob before the send; GmailSender body is text/plain unless tracked, then multipart/alternative (text + HTML twin with pixel), nested inside multipart/mixed with attachments; public `GET /t/o/{token}.gif` outside the `web` group (no session/CSRF); `OpenRecorder` ignores hits within 10 s of sending; kill switch `OUTREACH_OPEN_TRACKING`
- [x] Postmaster Tools v2 (`domains_domainStats->query`, SPAM_RATE/AUTH_SUCCESS_RATE/DELIVERY_ERROR_RATE): scope added to GmailClientFactory, `postmaster_stats` table, `PostmasterSync` + `postmaster:sync` daily 07:00, readable per-domain errors, "PostmasterTools" kept in composer.json vendor cleanup list
- [x] Dashboard is now Livewire (`App\Livewire\Dashboard` + `OutreachAnalytics`): window 7/30/90/all, tiles, per-day CSS bars grouped in the display timezone, by-automation table, Gmail spam rate by domain with Sync now
- [x] Health page: Open (7d) column, Spam rate (Gmail) column, reconnect banner; `health:evaluate` writes `open_rate_7d`
- [x] `/api/stats`: `tracked`, `opened`, `open_rate`, `postmaster.{worst, domains}`
- [x] README (env vars, "Open and spam tracking", schedule), docs/google-cloud-setup.md (API + scope), .env.example

### Review — DONE
- 509 tests / 1314 assertions green against local docker Postgres.
- Browser check on a seeded `outreach_demo` DB: dashboard (all windows), Health page, pixel fetch returns image/gif no-store with no cookie and records the open.
- Needs Sean: reconnect one mailbox (new scope), verify domains at postmaster.google.com, enable the Postmaster Tools API + scope in the Cloud project, run the three new migrations on the real DB, then `php artisan postmaster:sync`. Scribe docs not regenerated.

## 2026-09-21 — PHP 8.3 (server runs 8.3.31)
- composer.json: `php ^8.3` + `config.platform.php = 8.3.31`; lock re-resolved with the php@8.3 binary (Symfony 8.1 → 7.4, Laravel 13.17 → 13.32). Dockerfile `php:8.3-fpm`, README updated.
- App code uses no 8.4-only syntax/functions (grepped). 509 tests green under PHP 8.3.32 and 8.5.6.
- Local: `/opt/homebrew/opt/php@8.3/bin/php` exists; composer must be run with it (or trust the platform pin) so the lock never drifts back to 8.4-only packages.

## 2026-09-21 — Role field, leads detail toggle, attachments lost on save
- [x] `role` on contacts (migration + backfill from `extra.*.role`), accepted by `POST /api/contacts`, in the resource, drafter prompt, Leads column + multi-select filter + sort
- [x] Leads "Detail" toggle moved beside the checkbox: with Category/Niche/Role the far-right column scrolled out of view, which read as the detail being gone
- [x] Automation edit: a file chosen in a step row is attached by "Save automation" (validated first, works for a step saved in the same click); "Attach now" still works for saved steps; notice shows a chosen-but-unattached file
- 512 tests green under PHP 8.3; browser check of all three.

## 2026-09-28 — Apply the seannocode design system (v2.0 "Trust")

Spec: tasks/design-spec.md. Semantic colour tokens as CSS variables (light cream / dark navy),
views carry no `dark:` classes; theme switcher and its test stay.

- [x] Tokens: tailwind.config.js + resources/css/app.css; Google Fonts in both layouts
- [x] Chrome: layouts, navigation, wordmark, buttons, inputs, dropdown, modal, pill, sort-header, multi-select, pagination
- [x] Views batch A: contacts/index, approval-queue, activity, automations/*
- [x] Views batch B: dashboard, health/dashboard (+ DomainHealthScoringTest tone assertions), replies, mailboxes, provider-credits
- [x] Views batch C: settings/*
- [x] Views batch D: auth pages, profile, welcome
- [x] Verify: no gray/indigo/dark: left, `npm run build`, full test suite, screenshots light + dark

### Review (2026-09-28) — DONE
- 513 tests / 1333 assertions green against docker Postgres :5433. Only `text-accent` left in views is the wordmark's period.
- Status colours: green/yellow/red are gone; `<x-pill tone="good|warn|danger|neutral">` renders `data-tone` so tests assert meaning, not colour.
- Dark mode kept (the design system has no dark theme; navy is a surface). Implemented as the system's own navy tokens via CSS variables, so views carry no `dark:` classes. Dropping dark mode later is a matter of deleting the `.dark` block in app.css and the theme switcher.
- Two fixes found by screenshots: dashboard window toggle compared string to int key (never highlighted); Leads table padding tightened to px-4 so Enrollments fits at 1440.
- Laravel welcome page replaced with a one-line branded landing. Pagination views published and restyled (`resources/views/vendor/pagination`).
- Demo DB `outreach_demo` in docker Postgres, seeded (test@example.com / password) for screenshots.

