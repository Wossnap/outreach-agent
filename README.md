# Outreach Agent

AI-drafted, human-approved email outreach. Other apps push contacts + a tag via the API; each tag maps to an automation whose steps carry your drafting instructions; Claude drafts every email; you approve (and optionally edit) each one in the dashboard; approved emails send from your connected Google Workspace inboxes on a randomized, natural-looking schedule. Replies stop sequences automatically, bounces and opt-outs suppress the contact, and domain/mailbox health is monitored with auto-pause.

## How it flows

```
POST /api/contacts {email, tags:[...]}          your other apps
      │
      ▼
enrollment created per tag ──► Claude drafts step 1 ──► Approval queue (you)
                                                            │ approve
                                                            ▼
                              randomized send slot (jitter, caps, warmup, window)
                                                            │ slot due
                                                            ▼
                              Gmail API send (from the pinned mailbox, threads follow-ups)
                                                            │
              ┌─────────────────────────────────────────────┤
              ▼                                             ▼
   inbox polling every 2 min                    delay elapsed, no reply
   reply → stop sequence                        → Claude drafts next step
   bounce/opt-out → suppress + stop             → back to approval queue
```

## Stack

Laravel 13 · Livewire (Breeze) · Postgres · database queue · Docker (app :8010, postgres :5433, queue worker, scheduler) · Claude API for drafting · Gmail API (OAuth) for send + reply detection.

## Getting started

```bash
cp .env.example .env               # then fill the vars below
docker compose up -d --build

# The image installs dependencies and builds the assets, then compose mounts
# this directory over the top of them — so both must be installed again here,
# through the container. Without these two commands every container exits
# immediately on a missing vendor/autoload.php.
docker compose run --rm --no-deps app composer install
docker compose exec app sh -c "npm install && npm run build"

docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
# set OUTREACH_REGISTRATION_ENABLED=true, register your login user at
# http://localhost:8010/register, then set it back to false
```

Local (no Docker) needs **PHP 8.4 or newer**: `composer install && npm install && npm run build && php artisan migrate && composer dev` — but you must also run `php artisan queue:work database` and `php artisan schedule:work` for anything to actually draft/send.

### Running the tests

`php artisan test` — the suite runs on in-memory SQLite. phpunit.xml sets that
with `<server>` entries as well as `<env>`, because docker-compose sets `DB_*`
on the container and Laravel reads `$_SERVER` first. Removing those entries
makes the suite run against the live database and drop every table in it.

### Required env vars

| Var | Purpose |
|---|---|
| `OUTREACH_REGISTRATION_ENABLED` | sign-up page. Closed by default; open it only to create an account |
| `ANTHROPIC_DRAFTER` | `api` calls Claude; `mock` drafts offline for local testing, no key needed |
| `ANTHROPIC_API_KEY` | Claude API key for drafting |
| `ANTHROPIC_MODEL` | default `claude-sonnet-5` |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | OAuth client — see `docs/google-cloud-setup.md` |
| `GOOGLE_REDIRECT_URI` | `{APP_URL}/oauth/google/callback` |
| `OUTREACH_TIMEZONE` | dashboard display + default send window timezone |
| `OUTREACH_DAILY_CAP_DEFAULT` | per-mailbox daily cap (default 40) |
| `OUTREACH_MIN_GAP_MINUTES` / `OUTREACH_MAX_GAP_MINUTES` | random gap between sends (default 3–15) |
| `OUTREACH_SEND_WINDOW_START` / `OUTREACH_SEND_WINDOW_END` | default sending window (09:00–17:00) |

## Setup order

1. **Connect mailboxes** (Mailboxes → Connect Google mailbox). Each new mailbox starts in warmup: 5/day, +3/day up to its cap. Domains are registered automatically.
2. **Create automations** (Automations). The tag (e.g. `seo-backlinks`) is what your apps send. Add steps: per-step *drafting instructions* are the prompt Claude uses, combined with the contact's name/company/website/custom data. Step 2+ have a delay and draft as in-thread follow-ups.
3. **Create an API key** (API Keys) and call the ingest API from your apps:

```bash
curl -X POST https://your-host/api/contacts \
  -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
  -d '{"email":"jane@acme.com","name":"Jane","company":"Acme","website":"https://acme.com",
       "custom":{"niche":"gardening","recent_post":"..."},"source":"tube-trend-tool",
       "tags":["seo-backlinks"]}'
```

Semantics: contact upserted by email (fields merged); one enrollment per known tag; a tag with an already-active enrollment is skipped; suppressed contacts are skipped entirely; unknown tags are reported in the response, never an error.

4. **Approve drafts** (Approvals). Edit inline if needed; approve → the email gets a send slot; bulk approve compounds slots naturally. Rejecting records a note.

## Sending behaviour (anti-spam by design)

- **Slots are second-granular**: each send lands a random 3–15 min (configurable) after the mailbox's previous send — never on a cron minute boundary.
- **Windows**: sends clamp into each mailbox's send window/timezone; weekends optional; window-open times are jittered too.
- **Caps + warmup**: per-mailbox daily cap, ramped for new mailboxes (5/day + 3/day by default).
- **Threading**: follow-ups send `In-Reply-To`/`References` and Gmail's threadId — they appear in the same conversation.
- **Stop-on-reply**: any human reply stops the sequence and cancels queued follow-ups instantly (checked again at send time).
- **Suppression list**: bounces and opt-out phrases ("unsubscribe", "remove me", …) suppress the address permanently; the ingest API refuses suppressed contacts.
- **No blind send retries**: a failed send never auto-retries (duplicate risk); it surfaces on the Activity page with a retry button.

## Deliverability & the proxy question

Mail is sent through the **Gmail API**, so it leaves **Google's IP ranges** — sending-IP reputation (and therefore proxies) is not a factor for deliverability in this architecture. What actually determines inbox placement here, and what the Health page monitors:

- **Domain authentication** — SPF (must include `_spf.google.com`), DKIM (Workspace selector), DMARC. Checked daily via DNS.
- **Domain blocklists** — Spamhaus DBL, SURBL, URIBL, checked daily (best-effort: some lists throttle public resolvers).
- **Engagement** — rolling 7-day bounce/reply rates per mailbox.

**Auto-pause**: bounce rate > 5% (with ≥ 20 sends), a blocklisted domain, or missing SPF/DKIM pauses the affected mailboxes and logs an error. Fix the cause, then resume manually on the Mailboxes page.

## Scheduled commands

| Command | Cadence | Purpose |
|---|---|---|
| `outreach:dispatch-due-emails` | every minute | atomically claim due messages → send jobs |
| `gmail:poll-mailboxes` | every 2 min | fetch replies/bounces per mailbox |
| `outreach:advance-sequences` | every 10 min | draft next step when delay elapsed, no reply |
| `outreach:reconcile-stuck` | every 15 min | heal/fail rows orphaned by worker crashes |
| `health:evaluate` | hourly | bounce/reply rates + auto-pause |
| `health:check-dns` / `--dnsbl` | daily | SPF/DKIM/DMARC + blocklists |
| `mailboxes:refresh-tokens` | daily | surface dead Google refresh tokens early |

## The API

Full reference at `/docs`, with an OpenAPI spec at `/docs/openapi.yaml` and a
Postman collection at `/docs/collection.json`.

Keys are created in the dashboard under **Settings → API keys** and carry
abilities. An endpoint accepts only the ones it lists, so a key can be given
exactly what its caller needs and nothing more.

| Ability | Covers |
|---|---|
| `read` | every GET: contacts, replies, suppressions, messages, enrollments, automations, mailboxes, activity, stats |
| `write` | push contacts, edit and reject drafts, manage automations, steps, attachments, enrollments and mailboxes |
| `approve` | approve a draft so it sends |
| `ingest` | legacy, accepted on `POST /api/contacts` only, so keys issued before abilities existed keep working |

A wrong key gives 401. A valid key without the right ability gives 403, and the
message names the ability that was missing.

### Two actions are off by default

Both remove a safeguard rather than just moving data, so each needs a config
switch as well as the right ability. While off they return 403 whatever the key
carries.

| Action | Switch | Why it is held back |
|---|---|---|
| `POST /api/messages/{id}/approve` | `OUTREACH_API_ALLOW_APPROVAL` | sends a real email with nobody having read it |
| `DELETE /api/suppressions/{email}` | `OUTREACH_API_ALLOW_SUPPRESSION_REMOVAL` | resumes emailing a person who asked us to stop |

### Regenerating the docs

Scribe is a dev dependency and the built page in `public/docs` is committed, so
the server serves it as static files with the package absent. `config/scribe.php`
returns early when Scribe is not installed, so `config:cache` still works there.

Scribe calls the real GET endpoints to capture example responses, so generate
against a throwaway database seeded with sample data — never a real one:

```bash
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/docs.sqlite
touch /tmp/docs.sqlite
php artisan migrate --force
TOKEN_OUT=/tmp/docs-token php artisan db:seed --class=DocsExampleSeeder --force

SCRIBE_BASE_URL=https://your-host SCRIBE_AUTH_KEY=$(cat /tmp/docs-token)   php artisan scribe:generate
```

The URL is baked into the examples at generation time, so pass the host the
docs are for. The seeded key is used to make the calls and is not written into
the output.

## Attachments

A file can be attached to a **sequence step**, not to the automation as a whole:
a step-level field can express "attach to every email" by repeating it, but an
automation-level one cannot express "attach to the first email only".

Upload on the automation page, or `POST /api/automations/{automation}/steps/{step}/attachments`.
Attachments are listed on the Approvals page above the approve button, so an
email is never released without its attachments being visible.

Executables and archives are refused: recipients' gateways strip or quarantine
them, and that costs deliverability across the whole sending domain. Limits are
in `config/outreach.php`.

If a file is missing from storage at send time the send fails rather than going
out without it, so the error is visible on the Activity page instead of a
recipient receiving an email whose text refers to an attachment that is not
there.

## Contact names

Contacts carry `name` alongside `first_name` and `last_name`. Whatever a caller
supplies wins; anything omitted is derived from what was supplied, but only when
the stored value is blank, so re-sending one part never rewrites a name already
on record.

`name` is kept rather than derived because not every contact splits into two
parts: mononyms and role addresses ("Support Team") would be mangled by it.

## Tests

`php artisan test` — 226 tests over the API (read, write, abilities and the two
guarded actions), the ingest endpoint, drafting, approval, scheduling math
(frozen-time jitter/window/cap/warmup), Gmail MIME/threading including
multipart attachments, atomic dispatch, inbound classification (bounce DSNs,
opt-out phrases, OOO), health checks (stubbed DNS), and the dashboard pages.
