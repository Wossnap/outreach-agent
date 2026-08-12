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
