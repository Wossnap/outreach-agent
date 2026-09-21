# Google Cloud setup (one-time)

The app sends and reads mail via the Gmail API using OAuth per mailbox. You need one Google Cloud project + OAuth client; every mailbox then connects through the dashboard's "Connect Google mailbox" button.

## 1. Create the project + enable the API

1. [console.cloud.google.com](https://console.cloud.google.com) → New project (e.g. `outreach-agent`).
2. **APIs & Services → Library** → search **Gmail API** → Enable.
   Also search **Gmail Postmaster Tools API** → Enable. This is what feeds the spam-rate figures on the dashboard; without it the daily `postmaster:sync` reports an API error for every domain.

## 2. OAuth consent screen

**APIs & Services → OAuth consent screen.**

- **Internal** (recommended): available if all sending mailboxes live in one Google Workspace organisation. No Google verification, no test-user cap, refresh tokens don't expire. Pick this if you can.
- **External**: needed only if you mix mailboxes from different Workspace orgs / plain Gmail. In *Testing* mode you're capped at 100 test users **and refresh tokens expire after 7 days** (mailboxes would disconnect weekly) — so an External app realistically needs Google verification for production use. Prefer moving all sending domains under one Workspace org and using Internal.

Fill app name + support email. Scopes: add `https://www.googleapis.com/auth/gmail.send`, `https://www.googleapis.com/auth/gmail.readonly` and `https://www.googleapis.com/auth/postmaster.traffic.readonly` (plus `openid`, `email`). The Postmaster scope is what lets the app read Gmail's spam-rate figures; a mailbox connected before it was added has to be reconnected once to grant it.

## 3. OAuth client

**APIs & Services → Credentials → Create credentials → OAuth client ID**

- Type: **Web application**
- Authorized redirect URI: `https://your-host/oauth/google/callback` (must match `GOOGLE_REDIRECT_URI` exactly; for local dev `http://localhost:8010/oauth/google/callback`)

Copy the client ID + secret into `.env`:

```
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/oauth/google/callback"
```

## 4. Connect mailboxes

Dashboard → Mailboxes → **Connect Google mailbox** → sign in as the mailbox → consent. Repeat per mailbox. Each new mailbox starts warming (5/day, +3/day). The domain is registered automatically and its SPF/DKIM/DMARC checked on the next daily run (or Health → "Run checks now").

## 5. Domain DNS (per sending domain)

- **SPF** TXT on the root: `v=spf1 include:_spf.google.com ~all`
- **DKIM**: Google Admin → Apps → Google Workspace → Gmail → Authenticate email → generate + publish the `google._domainkey` record, then click *Start authentication*.
- **DMARC** TXT on `_dmarc`: start with `v=DMARC1; p=none; rua=mailto:you@domain`, move to `p=quarantine` once reports look clean.

The Health page verifies all three daily and pauses a domain's mailboxes if SPF/DKIM are missing or the domain lands on a blocklist.

## Notes

- Only the token exchange happens server-side; tokens are stored encrypted at rest.
- If a mailbox shows **disconnected** (refresh token revoked — e.g. password change, admin action), just Reconnect it; history and settings are kept.
- Google Workspace's own sending limits (2,000/day for paid accounts) are far above the caps this app uses; keep caps conservative anyway — deliverability dies from volume spikes, not limits.
