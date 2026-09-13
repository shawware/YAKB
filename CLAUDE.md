# Yet Another KarmaBot

A Slack karma bot that monitors channels for `@user ++` patterns and maintains a running score per user. Designed to be deployed for three different clients from a single shared PHP codebase.

**First deployment target: Client 1 (DreamHost shared hosting).** Build and validate against Client 1 first; Clients 2 and 3 follow once the core logic is proven.

## What It Does

- Monitors Slack channels for messages containing `@user` followed by one or more `+` signs
- Awards points equal to the number of `+` signs
- Persists scores and a full event log to a database
- Responds in-channel with the user's new karma score and tier
- Adds an emoji reaction to the triggering message as acknowledgement
- Assigns karma tiers (Bronze / Silver / Gold / Platinum) based on cumulative score
- Updates the user's Slack profile card with their current tier (Client 2 only)
- Updates the user's Google Workspace Directory profile with their current tier (Client 3 only)
- Exposes slash commands for querying scores, history, and leaderboards

## Architecture

Single PHP codebase with pluggable storage adapters and thin client-specific entry points. Only front-controller/entry-point files live under `public/` — everything else (business logic, storage adapters, vendor dependencies) sits outside the web-exposed directory.

```
karmabot/
├── .env                        # secrets — outside public/, never web-servable
├── composer.json
├── vendor/                     # Composer dependencies — outside public/
│
├── src/
│   ├── Parser.php               # regex, message parsing
│   ├── Karma.php                # score logic, tier calculation, profile updates
│   └── SlackApi.php             # posting messages, reactions, profile writes
│
├── storage/
│   ├── StorageInterface.php     # abstract interface
│   ├── MySqlStorage.php         # Client 1
│   ├── DynamoDbStorage.php      # Client 2
│   └── FirestoreStorage.php     # Client 3
│
├── app.php                      # shared routing (Slack events + slash commands), included by each entry point
│
├── public/                      # web-exposed directory — Client 1 & 3 document root
│   └── index.php                # front controller
│
└── handlers/
    └── lambda_handler.php        # Client 2 entry point (invoked via Bref)
```

### Slack Integration

All clients use the **Slack Events API** (HTTP webhooks) rather than Socket Mode. Slack POSTs events to a public URL; the handler processes and responds. Socket Mode is unsuitable for all deployment targets as none runs a persistent process.

Slack sends a URL verification challenge when the Events API endpoint is first configured — the handler must be deployed and live before configuring this in the Slack app dashboard.

**OAuth scopes required:**

| Scope | Purpose |
|---|---|
| `channels:history` | Read messages in public channels |
| `groups:history` | Read messages in private channels |
| `chat:write` | Post score responses |
| `reactions:write` | Add emoji acknowledgement |
| `users:read` | Resolve user ID to display name |
| `users.profile:write` | Update karma tier on Slack profile card (Client 2 only) |
| `commands` | Register slash commands |

**Credentials** — never in code, always in environment variables (loaded from `.env`, outside `public/`):

| Variable | Description |
|---|---|
| `SLACK_SIGNING_SECRET` | Verifies requests came from Slack (HMAC-SHA256 over the raw request body) |
| `SLACK_BOT_TOKEN` | `xoxb-...` token for calling the Slack API |

The bot must be invited into each channel it should monitor (`/invite @karmabot`).

### Karma Tiers

Tiers are calculated from cumulative score in `src/Karma.php` and included in all bot responses. Client 2 also writes the tier to a custom Slack profile field whenever it changes. Client 3 writes the tier to a Google Workspace Directory custom user attribute whenever it changes.

| Tier | Threshold |
|---|---|
| Bronze | 1 – 49 |
| Silver | 50 – 199 |
| Gold | 200 – 499 |
| Platinum | 500+ |

Client 2 requires a Slack workspace admin to define the custom profile field before tier profile writes will work. Client 3 requires a GWS admin to define a custom user attribute (e.g. "Karma Tier") in the Directory schema before tier writes will work.

### Slash Commands

Registered in the Slack app dashboard. All commands POST to the same routing (`app.php`) as events, dispatched by path.

| Command | Description |
|---|---|
| `/karma` | Your own score, tier, and leaderboard rank |
| `/karma @user` | Another user's score and tier |
| `/karma top` | Leaderboard, top N users |
| `/karma history [@user]` | Recent karma events — who gave to whom |
| `/karma month [@user]` | Points accrued in the past 30 days |

### Data Model

Two tables, common to all storage backends:

**scores** — current karma totals
- `user_id` (partition key / primary key)
- `username`
- `score`
- `tier`

**events** — full audit log; enables history queries and score recalculation
- `id`
- `from_user`
- `to_user`
- `points`
- `channel`
- `timestamp`

`storage/StorageInterface.php` must expose `getEvents(userId, since)` for date-range history queries. MySQL uses a standard index on `timestamp`; DynamoDB requires a **Global Secondary Index on `timestamp`** — add this at table creation time, it cannot be added without rebuilding the table. Firestore uses a **composite index on `(user_id, timestamp)`** — define this in `firestore.indexes.json` before deploying, as range queries across two fields require it.

### Response Behaviour

On a valid karma event the bot does two things within the Slack 3-second response window:
1. Adds an emoji reaction to the original message
2. Posts a message in the channel with the user's updated score

The Lambda/DreamHost/Cloud Run handler must acknowledge Slack's POST with HTTP 200 quickly. All processing should complete within 3 seconds or Slack will retry. At karma-bot scale a single synchronous handler (storage write + two Slack API calls) comfortably fits this window.

---

## Client 1 (first deploy)

**Profile:** Slack free tier, Google Workspace (paid), DreamHost shared hosting with MySQL.

**Runtime:** Native PHP via Apache/`mod_php` (or `php-fpm` if available on the plan) — DreamHost does **not** support Phusion Passenger; confirmed via DreamHost's own [supported technologies](https://help.dreamhost.com/hc/en-us/articles/217141627-Supported-and-unsupported-technologies) page. No WSGI/CGI adapter is needed since PHP is DreamHost's natively supported language.

**Entry point:** `public/index.php` — the domain's document root points at `public/`; `index.php` is the only web-exposed file and routes requests into `app.php` / `src/`.

**Storage:** MySQL on shared hosting via PDO (`pdo_mysql`). Zero marginal cost — already provisioned.

**Deployment:** SFTP/git, same as your other DreamHost PHP apps. Dependencies installed via Composer (`composer install`) into `vendor/`, kept outside `public/`.

**Key constraints:**
- Confirm the host permits inbound webhooks from external IPs before configuring Events API
- Karma tiers displayed in bot responses only — custom profile fields not available on Slack free tier
- `.env` lives alongside `composer.json` at the project root, outside `public/` — loaded via `vlucas/phpdotenv`

---

## Client 2

**Profile:** Slack paid tier, Google Workspace (paid), AWS partner.

**Runtime:** AWS Lambda. Entry point: `handlers/lambda_handler.php`, run via a custom PHP runtime layer using **Bref** (`bref/bref`), which adapts Lambda/API Gateway events into a standard HTTP request/response for the shared routing in `app.php`.

**Storage:** DynamoDB via `aws/aws-sdk-php`. Within always-free tier at karma-bot scale (25GB storage, 25 RCU/WCU). The `events` table requires a Global Secondary Index on `timestamp` — create this when the table is first provisioned.

**Infrastructure:**
- API Gateway (HTTP API) → Lambda (Bref custom runtime) → DynamoDB
- Lambda execution role requires DynamoDB read/write and CloudWatch logging permissions
- IAM role creation permissions required during initial setup — coordinate with AWS admin if restricted

**Karma tiers:** Full support. Workspace admin must define a custom profile field (e.g. "Karma Tier") before the bot can write to it. The bot updates this field whenever a user's tier changes.

**Deployment:** Serverless Framework or SAM with Bref's plugin/layer. Environment variables (`SLACK_SIGNING_SECRET`, `SLACK_BOT_TOKEN`) set as Lambda environment variables.

**Cost:** Effectively zero. Well within AWS free tier and likely covered by AWS partner credits regardless.

---

## Client 3

**Profile:** Slack paid tier, Google Workspace (paid), GCP partner.

**Runtime:** Cloud Run. Entry point: `public/index.php`, served via a PHP container (`php:8.3-apache` or `php:8.3-cli` + built-in server) built from a project `Dockerfile`. Cloud Run runs the container directly — no adapter library required, since the container just serves normal HTTP.

**Storage:** Firestore via `google/cloud-firestore`. Serverless, scales to zero, within GCP free tier at karma-bot scale. The `events` collection requires a **composite index on `(user_id, timestamp)`** — define in `firestore.indexes.json` and deploy before first use.

**Infrastructure:**
- Cloud Run → Firestore
- Service account requires Firestore read/write and Cloud Logging permissions
- For GWS Directory writes: service account must be granted domain-wide delegation in the GWS Admin console and the Admin SDK Directory API must be enabled in the GCP project; use `google/apiclient` for the Directory API calls

**Karma tiers:** Full support. GWS admin must define a custom user attribute (e.g. "Karma Tier") in the Directory schema before the bot can write to it. The bot updates this attribute whenever a user's tier changes.

**Deployment:** `gcloud run deploy` or Cloud Build, building the project's `Dockerfile`. Environment variables (`SLACK_SIGNING_SECRET`, `SLACK_BOT_TOKEN`) set as Cloud Run environment variables, ideally sourced from Secret Manager.

**Cost:** Effectively zero. Well within GCP free tier and likely covered by GCP partner credits regardless.

---

## Slack App Setup (all clients)

1. Create app at api.slack.com — requires a Slack account, not necessarily workspace admin
2. Add a Bot User
3. Configure OAuth scopes (see above; omit `users.profile:write` for Client 1)
4. Register slash commands in the app dashboard
5. Deploy the handler first, then configure the Events API endpoint URL
6. Subscribe to `message.channels` (and `message.groups` if private channels needed)
7. Install to workspace — requires workspace admin approval
8. Client 2 only: Slack workspace admin defines the "Karma Tier" custom profile field
9. Client 3 only: GWS admin defines the "Karma Tier" custom user attribute in the Directory schema; GCP service account granted domain-wide delegation
10. Invite bot to channels: `/invite @karmabot`

---

## Dependencies (Composer)

| Package | Purpose |
|---|---|
| `vlucas/phpdotenv` | Loads `.env` from outside `public/`, all clients |
| `guzzlehttp/guzzle` | HTTP client for calling the Slack Web API |
| `pdo_mysql` (PHP extension) | Client 1 MySQL storage adapter |
| `aws/aws-sdk-php` | Client 2 DynamoDB storage adapter |
| `bref/bref` | Runs the app as a Lambda custom runtime (Client 2) |
| `google/cloud-firestore` | Client 3 Firestore storage adapter |
| `google/apiclient` | Client 3 GWS Directory API (tier profile writes) |

---

## Deployment Model: Single-Tenant vs. Multi-Tenant

YAKB is intended as a self-hosted open-source project: anyone can clone the repo, pick a storage adapter, and run their own instance. Slack has no concept of "storage" — it only POSTs events to whatever URL an app's Events API is configured with, and which storage backend that URL's server uses is entirely a property of what code is deployed there, decided by the operator, never by Slack or by the installing workspace.

There are two supportable deployment shapes for someone (including a single operator) who wants to run YAKB against more than one Slack workspace. **Option A is the current default.** Option B is documented here as a known migration path, not yet implemented.

### Option A — one deployment per workspace (current default)

Each Slack workspace gets its own fully separate deployment: its own handler process, its own database, its own Slack app installation, one `SLACK_BOT_TOKEN` set as a single env var. This matches the existing Client 1/2/3 model exactly — no OAuth install flow is needed, no workspace-identifying data ever needs to be threaded through the storage layer, and there is no cross-workspace isolation risk because each deployment can only ever reach one workspace's data.

**Trade-off:** infrastructure cost and operational overhead scale linearly with the number of workspaces (N workspaces = N deployments = N databases).

### Option B — one shared deployment across multiple workspaces

A single deployment (one handler process, one database) serves multiple Slack workspaces, distinguished by the `team_id` Slack includes on every event and slash-command payload. This is more resource-efficient but requires real new infrastructure:

- **`installations` table** (`team_id` → encrypted bot token), replacing the single `SLACK_BOT_TOKEN` env var. Populated via a Slack OAuth v2 install flow: a new `/slack/oauth/callback` route exchanges the one-time `code` Slack redirects with for a bot token (via `oauth.v2.access`), using new `SLACK_CLIENT_ID` / `SLACK_CLIENT_SECRET` credentials (static, one pair per app registration, from the app's Basic Information page — distinct from the per-workspace bot tokens).
- **`team_id` as a partition key** on `scores` (composite key `(team_id, user_id)`) and as a column on `events`, threaded through every `storage/StorageInterface.php` method (`getScore`, `recordEvent`, `getEvents`, etc.) so isolation is enforced structurally rather than by convention at each call site. DynamoDB's `timestamp` GSI and Firestore's `(user_id, timestamp)` composite index both need `team_id` folded in.
- **Revocation on uninstall** — handle Slack's `app_uninstalled` event by deleting that workspace's `installations` row immediately.

**Migrating from A to B later:** additive, not a rewrite. Backfill `team_id` on existing rows (trivial — each pre-existing single-tenant DB only ever held one workspace's data, so every row gets the same known value), merge databases if both deployments already share a storage backend (crossing backends, e.g. MySQL to DynamoDB, is the one genuinely harder case), add the OAuth route and `installations` table, repoint both workspaces' Events API URLs at the surviving deployment, and re-run each through the OAuth flow once to populate proper per-workspace tokens.

### Security handling for Option B's `installations` table

Storing other workspaces' live bot tokens in a shared table is a materially bigger responsibility than one deploy-time env var, and needs to be handled accordingly:

- **Encrypt the token at rest**, not just rely on disk-level DB encryption — protects against a compromised DB read path (leaked connection string, over-permissioned tooling, injection elsewhere in the app), not just a stolen disk. PHP's built-in `sodium` extension (`sodium_crypto_secretbox`), or the `defuse/php-encryption` library for a higher-level API, with the key held outside the DB (env var at minimum; AWS KMS / GCP Secret Manager preferred where available) is sufficient — store ciphertext in MySQL/DynamoDB/Firestore, decrypt only in memory at the moment of a Slack API call.
- **Split DB access by role, least-privilege.** The webhook/event-processing path only ever needs `SELECT` on `installations` (token lookup per incoming event) — it never needs to write there. Only the OAuth callback route (install) and the uninstall handler (delete) need write access. Use two DB users/roles rather than one shared app user with full CRUD on the table, so a bug or compromise in the (much larger, more exposed) event-parsing code path can't insert, alter, or wipe other workspaces' tokens.
- **Never log the token**, and keep `installations` separate from any table that might be casually exported or queried by debugging tooling.
- **Rotate the encryption key** by re-encrypting all stored tokens if the key is ever suspected compromised; keep the key out of DB backups.
