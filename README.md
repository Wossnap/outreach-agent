# Outreach Agent

Finds an email address for a lead, checks it is real, then runs AI-drafted,
human-approved outreach to it.

Other apps push leads over the API with a tag. A lead does not have to arrive
with an email address: send an address, a LinkedIn profile, or just a name and a
company domain, and the waterfall finds the address and confirms it. Each tag
maps to an automation whose steps carry your drafting instructions; Claude
drafts every email; you approve each one; approved emails send from your
connected Google Workspace inboxes on a randomized, natural-looking schedule.
Replies stop sequences, bounces and opt-outs suppress the lead, and domain and
mailbox health is monitored with auto-pause.

**Leads on screen, contacts in code.** The database table, the model and the API
are all `contacts`, and the pages call them leads. One name in each place, and
they do not change with the weather.

## How it flows

```
POST /api/contacts {email | profile_url | name+domain, tags:[...]}   your other apps
      │
      ▼
lead upserted (one record per person, however they reached us)
      │
      ├─ no confirmed address? ──► email waterfall: find, then verify
      │                                    │ confirmed
      ▼                                    ▼
enrollment created ──── waiting_email ─────┴──► active
                                                  │
                                    Claude drafts step 1 ──► Approval queue (you)
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
   bounce → suppress, stop, AND mark            → back to approval queue
            the address invalid so the
            provider that supplied it
            is answerable for it
```

An enrollment is created even when there is nowhere to send yet. It waits at
`waiting_email` and starts on its own the moment an address is confirmed, so a
caller never has to remember to push the same person again later.

## The email waterfall

Under **Waterfall**. Every provider the system can talk to appears by itself and
does nothing until you give it a key: nothing is ever silently live.

A lead with no address is offered to each finder in turn until one answers, then
the address is offered to each verifier until one commits. Both chains stop at
the first straight answer, so whoever is first settles most leads and nearly all
the money goes through that slot. Order them cheapest first to begin with, then
let **Spend** tell you what to change.

Free checks run before anything is paid for: syntax, then whether the domain
publishes anywhere to deliver mail at all.

A finder that checks what it finds is taken at its word. Hunter checks every
address it finds at no extra cost: **valid** settles the lead as valid with no
verifier paid; **catch-all** goes only to a verifier that can settle catch-alls
(BounceBan), skipping the rest, which would only say catch-all again, and is
risky if none is switched on; **unknown** goes through the verifiers as usual.
An address that arrived with the lead was checked by nobody, so it always goes
through the verifiers.

A catch-all domain is marked **risky**, never valid. On such a domain the server
accepts every address it is offered, so a yes says nothing about whether that
mailbox exists, and roughly a fifth to a third of business domains are set up
that way.

**Check credits now**, on the same page, asks every provider with a key what is
left on the account and keeps the answer until you press it again. It is a
button rather than something the page does by itself: those are six live calls
to six other companies, they take several seconds altogether, and a balance only
moves when we spend. Every figure carries the date it was read, so nothing on
screen pretends to be today's number.

Each provider's price is its published pay-as-you-go or starting-plan rate,
with the source and date beside it in the driver's `listPrice()`. A call's cost
is worked out and stored when the call is made, so correcting a price changes
new calls only. To put past lookups at the corrected prices, run
`php artisan enrichment:reprice-lookups` once after deploying: it reports the
totals before and after and changes nothing; run it again with `--apply`.

**Spend** reports what each provider cost and how often it was any good. The
column that matters is cost per answer, not cost per call: a provider charging a
fifth as much but answering one time in ten costs twice as much per address
found as the dearer one that answers every time.

**Lookups** is the same information before it was added up: every call ever
made, the misses and the failures included, filterable by provider, verdict and
step, each one keeping what the provider said in its own words. A total is a
dead end when somebody disputes a verdict; this is where that argument is had.
Each provider's name on Spend opens the calls behind it.

To send leads back through the waterfall after adding a provider or giving one a
key, tick them on the Leads page and use **Check the addresses again**. A settled
lead is never revisited on its own, because an answer is only paid for once.
Anybody who has opted out is skipped, and the message says how many.

### When a provider runs dry

A provider that fails five calls in a row, usually because it is out of credits
or over its plan's limit, is switched off, and the lead moves on to the next one.
A failure is never taken as an answer. A lead that only failed goes to
**waiting to retry**, never "not found" or "risky", and keeps any address a
finder already returned.

What each address status means:

| Status | Meaning |
|---|---|
| Pending | Not looked up yet: its lookup is queued, or lookups are switched off |
| Finding / Verifying | A finder or checker is being asked right now (seconds) |
| Waiting to retry | Looked up, but it could not finish: a provider failed, ran out or was switched off for failing, or no checker was on |
| Valid | Confirmed to receive mail; the only status emailed on its own |
| Risky | Checked, nobody could confirm it: a catch-all domain, or every checker said unknown |
| Invalid | Does not receive mail, or failed the free syntax and DNS check |
| Not found | Every finder switched on looked and none had an address |

Leads the older code left in the wrong status (marked "not found" or "risky"
because a provider failed, or stuck at "finding") are repaired once after
deploying: `php artisan enrichment:repair-statuses` reports what it would
change and changes nothing; run it again with `--apply` to make the changes.

Nothing needs doing by hand to recover:

- **`enrichment:retry`**, every 15 minutes, sends leads waiting to retry
  through again, at once. A lead still pending, finding or verifying after
  15 minutes (`ENRICHMENT_RETRY_STALE_MINUTES`) is one whose lookup never ran
  or died part way, and is sent too.
  With nobody waiting it stops before asking any provider anything. Otherwise
  it asks them what they have left, switches back on any the waterfall had
  switched off that has credit again, so a top-up is used within 15 minutes
  rather than at the next hourly check, and does nothing when none can take
  the work. It sends a batch of 200, four seconds apart, so sending is
  never held up behind lookups. A provider that already answered for a lead is
  not asked again. **Check the addresses again** is the exception: a person
  asking wants a fresh answer, so everybody is asked.
- **`enrichment:check-providers`**, hourly, reads every balance, switches back
  on any provider the waterfall switched off once it has credit again, and
  emails when a finder or checker is low, empty or switched off. Each problem is
  emailed once, and again only if it clears and comes back. One you switched off
  yourself stays off.

The same warnings show at the top of **Leads** and **Dashboard**.

Three switches live there too, all live rather than config:

| Switch | What it does |
|---|---|
| Enrichment | Off: nothing is looked up and nothing is spent. Leads stay pending. |
| Require a confirmed address | Off: leads are emailed on the address supplied, with nothing having checked it. Useful while no verifier is configured. |
| Minimum cost | On: only Hunter, Reoon and BounceBan are used. Every other provider is switched off and locked, so the page never shows one as in use that is not. Off: each goes back exactly as it was. Hunter's own check is used either way. |

## Stack

Laravel 13 · Livewire (Breeze) · Postgres · database queue · Docker (app :8010, postgres :5433, queue worker, scheduler) · Claude API for drafting · Gmail API (OAuth) for send + reply detection · pluggable email finders and verifiers (Hunter, Findymail, Reoon, ZeroBounce, MyEmailVerifier, BounceBan).

LinkedIn collection is a separate site that runs on a Mac, because it drives a
real browser window. It pushes what it finds here over `POST /api/contacts` like
any other caller. Nothing here knows it exists.

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

Local (no Docker) needs **PHP 8.3 or newer** (the server runs 8.3; `composer.json` pins the platform to 8.3 so dependencies resolve for it): `composer install && npm install && npm run build && php artisan migrate && composer dev` — but you must also run `php artisan queue:work database` and `php artisan schedule:work` for anything to actually draft/send.

### Running the tests

`php artisan test` — the suite runs against Postgres, in an `outreach_test`
database. Create it once:

```bash
docker compose exec postgres psql -U postgres -c "CREATE DATABASE outreach_test;"
```

The suite needs a real Postgres server. Two behaviours the application depends
on are specific to it: an unaliased `COUNT(*)` in a grouped query throws, and
`LIKE` compares case, so a lead search for "acme" does not find "Acme".

**It will refuse to run against anything but `outreach_test`.** Every test drops
every table it can reach, so the database name is the whole of the safety.
phpunit.xml forces that name, but a cached config file overrides everything
phpunit sets, and `php artisan config:cache` is part of a normal deploy. On a
machine that has one, `php artisan test` would otherwise connect to the live
database and empty it. `Tests\Support\TestDatabase` stops the process before
the first test runs; if you see it, run `php artisan config:clear`.

phpunit.xml pins the database name with `<server>` entries as well as `<env>`,
because docker-compose sets `DB_*` on the container and Laravel reads `$_SERVER`
first. Removing those entries makes the suite run against the live database and
drop every table in it. `TestDatabaseIsolationTest` fails loudly if that ever
happens.

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
| `OUTREACH_OPEN_TRACKING` | put an open-tracking pixel in every email (default `true`); `APP_URL` must be the public host |
| `OUTREACH_OPEN_TRACKING_IGNORE_SECONDS` | pixel hits this soon after sending are scanners, not people (default 10) |
| `OUTREACH_POSTMASTER_SYNC` | pull Gmail Postmaster Tools spam rates daily (default `true`) |
| `OUTREACH_POSTMASTER_LOOKBACK_DAYS` | how far back the first Postmaster sync asks for (default 30) |
| `ENRICHMENT_ALERT_EMAIL` | who is emailed when a finder or checker is low or switched off, comma separated. Empty emails every dashboard user |
| `ENRICHMENT_LOW_CREDITS` | below this many credits a provider counts as low (default 50) |
| `ENRICHMENT_RETRY_BATCH` / `ENRICHMENT_RETRY_SPACING` | waiting leads sent per retry, and seconds between them (default 200, 4) |

## Setup order

1. **Connect mailboxes** (Mailboxes → Connect Google mailbox). Each new mailbox starts in warmup: 5/day, +3/day up to its cap. Domains are registered automatically.
2. **Create automations** (Automations). The tag (e.g. `seo-backlinks`) is what your apps send. Add steps: per-step *drafting instructions* are the prompt Claude uses, combined with the lead's name, job title, company, domain and anything the source sent. Step 2+ have a delay and draft as in-thread follow-ups.
3. **Set up the waterfall** (Waterfall). Give at least one verifier a key, or turn
   off "require a confirmed address" and nothing will ever become sendable.
4. **Create an API key** (API Keys) and push leads from your apps:

```bash
curl -X POST https://your-host/api/contacts \
  -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
  -d '{"email":"jane@acme.com","name":"Jane","company":"Acme","domain":"acme.com",
       "extra":{"niche":"gardening","recent_post":"..."},"source":"tube-trend-tool",
       "tags":["seo-backlinks"]}'
```

Send `contacts` as an array to push up to 500 at once. A batch is all or
nothing, so a caller never has to work out which half of it landed. The answer
has one shape either way: `data.contacts` is always a list, and a list of one is
a perfectly good list.

`domain` takes a full URL as happily as a bare host — it is reduced to the host
— so there is no second field to choose between. `extra` is filed under your
`source`, which is how two callers can both send a "score" and mean different
things by it.

Each lead needs an **email**, or a **profile_url**, or a **name together with a
domain**. That third form is how you ask for an address to be found: say who the
person is and where they work.

Semantics: a lead is upserted on email, else LinkedIn URL, else name plus domain,
with supplied fields merged and nothing blanked by omission; one enrollment per
known tag; a tag with an enrollment already open (active or waiting) is skipped;
a suppressed address is stored but never enrolled; unknown tags are reported in
the response, never an error.

4. **Approve drafts** (Approvals). Edit inline if needed; approve → the email gets a send slot; bulk approve compounds slots naturally. Rejecting records a note.

## Sending behaviour (anti-spam by design)

- **Slots are second-granular**: each send lands a random 3–15 min (configurable) after the mailbox's previous send — never on a cron minute boundary.
- **Windows**: sends clamp into each mailbox's send window/timezone; weekends optional; window-open times are jittered too.
- **Caps + warmup**: per-mailbox daily cap, ramped for new mailboxes (5/day + 3/day by default).
- **Threading**: follow-ups send `In-Reply-To`/`References` and Gmail's threadId — they appear in the same conversation.
- **Stop-on-reply**: any human reply stops the sequence and cancels queued follow-ups instantly (checked again at send time).
- **Suppression list**: bounces and opt-out phrases ("unsubscribe", "remove me", …) suppress the address permanently, and stop every sequence that person is in, waiting ones included. A suppressed lead pushed to the API is stored but never enrolled.
- **No blind send retries**: a failed send never auto-retries (duplicate risk); it surfaces on the Activity page with a retry button.
- **Open tracking is the only HTML**: each email is the plain text plus an HTML twin saying exactly the same words, with a 1x1 image served by this app. Switch it off with `OUTREACH_OPEN_TRACKING=false` and emails are plain text again.

## Open and spam tracking

**Opens.** Every sent email carries a pixel at `{APP_URL}/t/o/{token}.gif`; the first fetch after the ignore window stamps `first_opened_at`, later ones bump `open_count`. The dashboard, the Health page and `/api/stats` count opens against messages that carried a pixel. Treat the figure as directional: image blocking undercounts, and Gmail's image proxy and Apple's Mail Privacy Protection fetch pixels nobody looked at. Serve the app over HTTPS on a host you control, because that host becomes part of the mail's reputation.

**Spam.** Nobody outside Google can see whether an email landed in a spam folder. What Gmail does publish is the share of delivered mail its users marked as spam, per sending domain, through **Postmaster Tools**. `postmaster:sync` pulls that daily (`SPAM_RATE`, `AUTH_SUCCESS_RATE`, `DELIVERY_ERROR_RATE`) into `postmaster_stats`, and the dashboard shows the worst domain. To get figures:

1. Enable the **Gmail Postmaster Tools API** in the Cloud project and add the `postmaster.traffic.readonly` scope to the consent screen (`docs/google-cloud-setup.md`).
2. Add and verify each sending domain at [postmaster.google.com](https://postmaster.google.com) with the Google account of a connected mailbox.
3. **Reconnect that mailbox** from the Mailboxes page so its token carries the new scope. The Health page says when no mailbox has it.

Gmail only reports days with meaningful volume from the domain and lags by a day or three, so "no data yet" is normal for a new or low-volume domain.

## Deliverability & the proxy question

Mail is sent through the **Gmail API**, so it leaves **Google's IP ranges** — sending-IP reputation (and therefore proxies) is not a factor for deliverability in this architecture. What actually determines inbox placement here, and what the Health page monitors:

- **Domain authentication** — SPF (must include `_spf.google.com`), DKIM (Workspace selector), DMARC. Checked daily via DNS.
- **Domain blocklists** — Spamhaus DBL, SURBL, URIBL, checked daily (best-effort: some lists throttle public resolvers).
- **Engagement** — rolling 7-day bounce/reply/open rates per mailbox.
- **Gmail's spam rate** — per domain, from Postmaster Tools (see above).

**Auto-pause**: bounce rate > 5% (with ≥ 20 sends), a blocklisted domain, or missing SPF/DKIM pauses the affected mailboxes and logs an error. Fix the cause, then resume manually on the Mailboxes page.

## Scheduled commands

| Command | Cadence | Purpose |
|---|---|---|
| `outreach:dispatch-due-emails` | every minute | atomically claim due messages → send jobs |
| `gmail:poll-mailboxes` | every 2 min | fetch replies/bounces per mailbox |
| `outreach:advance-sequences` | every 10 min | draft next step when delay elapsed, no reply |
| `outreach:reconcile-stuck` | every 15 min | heal/fail rows orphaned by worker crashes |
| `enrichment:retry` | every 15 min | send leads still waiting for an address or a check through the waterfall again |
| `enrichment:check-providers` | hourly | refresh balances, switch topped-up providers back on, email about new problems |
| `health:evaluate` | hourly | bounce/reply/open rates + auto-pause |
| `health:check-dns` / `--dnsbl` | daily | SPF/DKIM/DMARC + blocklists |
| `postmaster:sync` | daily | Gmail Postmaster spam-rate figures per domain |
| `mailboxes:refresh-tokens` | daily | surface dead Google refresh tokens early |

## The API

Full reference at `/docs`, with an OpenAPI spec at `/docs/openapi.yaml` and a
Postman collection at `/docs/collection.json`.

Keys are created in the dashboard under **Settings → API keys** and carry
abilities. An endpoint accepts only the ones it lists, so a key can be given
exactly what its caller needs and nothing more.

| Ability | Covers |
|---|---|
| `read` | every GET: leads, replies, suppressions, messages, enrollments, automations, mailboxes, activity, stats |
| `write` | push leads, edit and reject drafts, manage automations, steps, attachments, enrollments and mailboxes |
| `approve` | approve a draft so it sends |

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
createdb outreach_docs
export DB_DATABASE=outreach_docs
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

## Lead names

Leads carry `name` alongside `first_name` and `last_name`. Whatever a caller
supplies wins; anything omitted is derived from what was supplied, but only when
the stored value is blank, so re-sending one part never rewrites a name already
on record.

`name` is kept rather than derived because not every contact splits into two
parts: mononyms and role addresses ("Support Team") would be mangled by it.

## Tests

`php artisan test` — 426 tests over the API (read, write, abilities and the two
guarded actions), the push endpoint, drafting, approval, scheduling math
(frozen-time jitter/window/cap/warmup), Gmail MIME/threading including
multipart attachments, atomic dispatch, inbound classification (bounce DSNs,
opt-out phrases, OOO), health checks (stubbed DNS), and the dashboard pages.
