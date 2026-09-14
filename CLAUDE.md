# Yet Another KarmaBot

YAKB is a Slack karma bot. It watches channels for `@user ++` patterns. It keeps a running total for each user.

One PHP codebase supports three different clients. Each client has its own thin entry point.

**Build for Client 1 first.** Client 1 uses shared hosting. Prove the core logic on Client 1. Add Client 2 and Client 3 after that.

## What The Bot Does

- The bot watches Slack channels for messages that contain `@user` and one or more `+` signs.
- The bot adds karma equal to the number of `+` signs, up to a configured maximum per message.
- The bot saves karma and a full event log to a database.
- The bot replies in the channel with the user's new karma and tier.
- The bot adds an emoji reaction to the message that triggered the event.
- The bot assigns a karma tier (Bronze, Silver, Gold, or Platinum) based on the user's karma.
- The bot updates the user's Slack profile card with the current tier. This applies to Client 2 only.
- The bot updates the user's Google Workspace Directory profile with the current tier. This applies to Client 3 only.
- The bot answers slash commands for karma, history, and leaderboards.

## Architecture

The codebase is one PHP project. It has pluggable storage adapters. It has one thin entry point per client.

Only front-controller files sit under `public/`. All other code sits outside `public/`. This includes business logic, storage adapters, and the Composer `vendor/` folder. No web request can reach these files directly.

```
karmabot/
├── .env                        # secrets — outside public/, never web-servable
├── composer.json
├── vendor/                     # Composer dependencies — outside public/
│
├── src/
│   ├── Parser.php               # regex, message parsing
│   ├── Karma.php                # karma logic, tier calculation, profile updates
│   ├── SlackApiInterface.php    # abstract interface — lets Router be tested with a mock
│   └── SlackApi.php             # posting messages, reactions, profile writes
│
├── storage/
│   ├── StorageInterface.php     # abstract interface
│   ├── MySqlStorage.php         # Client 1
│   ├── DynamoDbStorage.php      # Client 2
│   └── FirestoreStorage.php     # Client 3
│
├── yakb.php                      # shared routing (Slack events + slash commands), included by each entry point
├── env.php                      # envValue() — reads config from $_ENV/$_SERVER, not getenv() (see Client 1's key constraints)
│
├── public/                      # web-exposed directory — Client 1 & 3 document root
│   └── index.php                # front controller
│
└── handlers/
    └── lambda_handler.php        # Client 2 entry point (invoked via Bref)
```

### Slack Integration

All clients use the Slack Events API. This means Slack sends HTTP webhooks to a public URL. The handler reads each webhook and responds.

No client uses Socket Mode. Socket Mode needs a persistent process. None of the three deployment targets runs a persistent process.

Slack sends a URL verification challenge on first setup. Deploy the handler before you add the Events API URL to the Slack app dashboard.

**Required OAuth scopes:**

| Scope | Purpose |
|---|---|
| `channels:history` | Read messages in public channels |
| `groups:history` | Read messages in private channels |
| `chat:write` | Post karma replies |
| `reactions:write` | Add the emoji reaction |
| `users.profile:write` | Update the karma tier on the Slack profile card. Client 2 only. |
| `commands` | Register slash commands |

**Credentials.** Never put credentials in code. Always put them in environment variables. Load these from `.env`, which sits outside `public/`.

| Variable | Description |
|---|---|
| `SLACK_SIGNING_SECRET` | Verifies that a request came from Slack. Uses HMAC-SHA256 over the raw request body. |
| `SLACK_BOT_TOKEN` | The `xoxb-...` token. Use this to call the Slack API. |

Invite the bot to each channel you want it to watch. Use `/invite @karmabot`.

### Karma Tiers

`src/Karma.php` calculates the tier from the cumulative karma. Every bot reply shows the tier.

Tier is derived data. It is never stored. Storage holds only the karma total. Every place that needs a tier calls `Karma::tierForKarma($karma)` at the moment it needs it. This keeps a stored tier from drifting out of sync with its karma total, and it means a change to `config/tiers.php` takes effect at once, for every user, with no backfill step.

To detect a tier change (Client 2 and Client 3 need this — see below), compute the tier from the karma before the event and from the karma after the event, then compare the two. Do this at the point where the event is handled (`yakb.php`), not inside storage. Storage only ever deals with karma.

Client 2 also writes the tier to a custom Slack profile field. It does this each time the tier changes.

Client 3 writes the tier to a Google Workspace Directory custom attribute. It does this each time the tier changes.

Tiers and thresholds are configured, not hardcoded. `config/tiers.php` holds an ordered list of tiers, each with a `name` and a `min` karma. `src/Karma.php` takes no built-in thresholds — it only knows how to walk whatever tier list it is given. An operator retunes tiers by editing `config/tiers.php`. No UI and no code change are needed.

The shipped defaults, in `config/tiers.php`, are:

| Tier | Threshold |
|---|---|
| Bronze | 1 – 49 |
| Silver | 50 – 199 |
| Gold | 200 – 499 |
| Platinum | 500+ |

Client 2 needs a Slack workspace admin to create the custom profile field first. Tier writes will not work before that.

Client 3 needs a GWS admin to create a custom user attribute first, for example "Karma Tier". Tier writes will not work before that.

### Karma Cap

`src/Parser.php` caps the karma from a single message at `config/karma.php`'s `maxKarmaPerMessage` (shipped default: 5). This limits how much karma one message can award, for example `<@user> ++++++++++++++++++`. The cap applies per mention, not per message — a message that mentions two different users can still award each of them up to the maximum.

The parser reports whether it applied the cap (`capped: true`). When it did, the bot's reply names the cap, so the sender knows their `+` count was reduced rather than silently ignored:

> `<@user> now has 7 karma (Bronze)! (capped at 5 karma per message)`

An operator retunes the cap by editing `config/karma.php`. No UI and no code change are needed.

### Slash Commands

Register slash commands in the Slack app dashboard. All commands POST to the same routing in `yakb.php`. The router dispatches each command by its path.

Every `/karma` reply is ephemeral — visible only to the user who ran the command, not the rest of the channel. This is the only place karma activity is private; the `@user ++` award reaction and reply (from an ordinary message, not a slash command) stay public.

| Command | Description |
|---|---|
| `/karma` | Your own karma, tier, and leaderboard rank |
| `/karma @user` | Another user's karma and tier |
| `/karma top` | The leaderboard, top N users |
| `/karma history [@user]` | Recent karma events: who gave karma to whom |
| `/karma month [@user]` | Karma earned in the past 30 days |

### Data Model

All three storage backends use the same two tables.

**karma** — the current karma total for each user
- `user_id` (partition key / primary key)
- `karma`

Tier is derived from `karma` at read time via `Karma::tierForKarma()`. It is never stored — see "Karma Tiers" above.

All clients render mentions as `<@userId>` in every bot reply, letting Slack's own client resolve and display the real name — so no username is ever stored.

**events** — the full audit log. This log supports history queries and karma recalculation.
- `id`
- `from_user`
- `to_user`
- `karma`
- `channel`
- `timestamp`

`storage/StorageInterface.php` must expose a method `getEvents(userId, since)`. This method supports date-range history queries.

MySQL needs a standard index on `timestamp`.

DynamoDB needs a Global Secondary Index on `timestamp`. Add this index when you create the table. You cannot add it later without rebuilding the table.

Firestore needs a composite index on `(user_id, timestamp)`. Define this index in `firestore.indexes.json` before you deploy. Range queries across two fields need this index.

### Response Behaviour

On a valid karma event, the bot does two things. It must do both within Slack's 3-second response window.

1. The bot adds an emoji reaction to the original message.
2. The bot posts a message in the channel with the updated karma.

The handler must return HTTP 200 to Slack quickly. All processing should finish within 3 seconds. If it does not, Slack will retry the request. At karma-bot scale, one synchronous handler easily fits this window. This handler does one storage write and two Slack API calls.

**A user cannot give karma to themselves.** If the mentioned user is the same as the sender, the bot does not record an event or change any karma. Instead it reacts with a different emoji (`no_good`) and replies that self-karma is not allowed. This check happens per mention. A message that mentions the sender and someone else still awards the other person normally.

**The bot ignores its own messages.** The bot is a channel member, so its own replies are delivered back to it as ordinary message events. The handler skips any event with `subtype: bot_message` before parsing it, so it never reacts to its own output.

### Unmatched and Root Requests

The bot has no human-facing UI. It only answers Slack. A person may still land on the bare domain by accident, or a scanner may probe it.

Serve a simple static page for the root path and for any unmatched path. Do not redirect. Many shared hosting control panels do not support a clean same-URL redirect for this case.

The static page must not leak any information. Do not show a stack trace. Do not show a framework error page. Do not show which storage backend or which Slack workspace this instance serves.

Every real route (`/slack/events`, `/slack/commands`) must still check the Slack request signature. Reject an invalid or missing signature with HTTP 401 or 400. This check applies even if someone guesses a valid route path.

---

## Client 1 (first deploy)

**Profile:** Slack free tier. Google Workspace, paid. Shared hosting with MySQL.

**Runtime:** Native PHP, through Apache `mod_php` (or `php-fpm`, if the plan supports it). Many shared hosts do not support Phusion Passenger — check your host's own list of supported technologies before assuming it's available. No adapter is needed if the host runs PHP natively, which is true of nearly every shared host.

**Entry point:** `public/index.php`. Point the domain's document root at `public/`. `index.php` is the only web-exposed file. It routes each request into `yakb.php` and `src/`.

**Storage:** MySQL on shared hosting, through PDO (`pdo_mysql`). This costs nothing extra — nearly all shared hosting plans already include MySQL.

**Deployment:** SFTP or git, the same as your other PHP apps on this host. Install dependencies with Composer (`composer install`). Composer writes them to `vendor/`, outside `public/`.

Slack's free tier does not support custom profile fields. So karma tiers appear in bot replies only, not on the profile card.

**For the full step-by-step install — domain setup, `.env`, MySQL, the Slack app, and known pitfalls — see [docs/install-shared-hosting-mysql.md](docs/install-shared-hosting-mysql.md).**

---

## Client 2

**Profile:** Slack paid tier. Google Workspace, paid. AWS partner.

**Runtime:** AWS Lambda. The entry point is `handlers/lambda_handler.php`. It runs on a custom PHP runtime layer, built with Bref (`bref/bref`). Bref converts each Lambda/API Gateway event into a standard HTTP request. `yakb.php` then handles that request the normal way.

**Storage:** DynamoDB, through `aws/aws-sdk-php`. At karma-bot scale, this stays inside the AWS always-free tier (25GB storage, 25 RCU/WCU). The `events` table needs a Global Secondary Index on `timestamp`. Create this index when you first provision the table.

**Infrastructure:**
- API Gateway (HTTP API) calls Lambda. Lambda (Bref custom runtime) calls DynamoDB.
- The Lambda execution role needs DynamoDB read/write permissions and CloudWatch logging permissions.
- Initial setup needs IAM role creation permissions. Coordinate with an AWS admin if your account restricts this.

**Karma tiers:** Full support. A workspace admin must first create a custom profile field, for example "Karma Tier". The bot then updates this field each time a user's tier changes.

**Deployment:** Use the Serverless Framework or SAM, with Bref's plugin or layer. Set `SLACK_SIGNING_SECRET` and `SLACK_BOT_TOKEN` as Lambda environment variables.

**Cost:** Effectively zero. This stays well within the AWS free tier. AWS partner credits likely cover it regardless.

---

## Client 3

**Profile:** Slack paid tier. Google Workspace, paid. GCP partner.

**Runtime:** Cloud Run. The entry point is `public/index.php`. A `Dockerfile` builds a PHP container (`php:8.3-apache`, or `php:8.3-cli` with the built-in server) to serve it. Cloud Run runs this container directly. No adapter library is needed, because the container serves plain HTTP.

**Storage:** Firestore, through `google/cloud-firestore`. This is serverless. It scales to zero. At karma-bot scale, it stays within the GCP free tier. The `events` collection needs a composite index on `(user_id, timestamp)`. Define this in `firestore.indexes.json`. Deploy it before first use.

**Infrastructure:**
- Cloud Run calls Firestore.
- The service account needs Firestore read/write permissions and Cloud Logging permissions.
- For GWS Directory writes: grant the service account domain-wide delegation in the GWS Admin console. Enable the Admin SDK Directory API in the GCP project. Use `google/apiclient` to call the Directory API.

**Karma tiers:** Full support. A GWS admin must first create a custom user attribute, for example "Karma Tier", in the Directory schema. The bot then updates this attribute each time a user's tier changes.

**Deployment:** Use `gcloud run deploy` or Cloud Build. Build from the project's `Dockerfile`. Set `SLACK_SIGNING_SECRET` and `SLACK_BOT_TOKEN` as Cloud Run environment variables. Source them from Secret Manager where possible.

**Cost:** Effectively zero. This stays well within the GCP free tier. GCP partner credits likely cover it regardless.

---

## Slack App Setup (all clients)

1. Create the app at api.slack.com. This needs a Slack account. It does not need workspace admin rights.
2. Add a Bot User.
3. Configure the OAuth scopes. See the table above. Omit `users.profile:write` for Client 1.
4. Register the slash commands in the app dashboard.
5. Deploy the handler first. Configure the Events API endpoint URL after that.
6. Subscribe to `message.channels`. Also subscribe to `message.groups`, if you need private channels.
7. Install the app to the workspace. This needs workspace admin approval.
8. Client 2 only: ask a Slack workspace admin to define the "Karma Tier" custom profile field.
9. Client 3 only: ask a GWS admin to define the "Karma Tier" custom user attribute in the Directory schema. Grant the GCP service account domain-wide delegation.
10. Invite the bot to each channel: `/invite @karmabot`.

A real install turned up several non-obvious pitfalls (Socket Mode silently breaking delivery, "Incoming Webhooks" vs. "Event Subscriptions" confusion, where the bot token actually appears, and more). See the Troubleshooting section of [docs/install-shared-hosting-mysql.md](docs/install-shared-hosting-mysql.md) for all of them.

---

## Dependencies (Composer)

| Package | Purpose |
|---|---|
| `vlucas/phpdotenv` | Loads `.env` from outside `public/`. All clients use this. |
| `guzzlehttp/guzzle` | The HTTP client for calls to the Slack Web API. |
| `pdo_mysql` (PHP extension) | The Client 1 MySQL storage adapter. |
| `aws/aws-sdk-php` | The Client 2 DynamoDB storage adapter. |
| `bref/bref` | Runs the app as a Lambda custom runtime. Client 2 only. |
| `google/cloud-firestore` | The Client 3 Firestore storage adapter. |
| `google/apiclient` | The Client 3 GWS Directory API client. Writes tier profile updates. |

---

## Deployment Model: One Workspace vs. Many Workspaces

YAKB is a self-hosted, open-source project. Anyone can clone the repo. They can pick a storage adapter. They can run their own instance.

Slack has no concept of storage. Slack only sends events to whatever URL an app's Events API points at. The code behind that URL decides which storage backend to use. The operator makes this choice, not Slack, and not the installing workspace.

There are two ways to run YAKB against more than one Slack workspace. This applies even when one operator runs both. **Option A is the current default.** Option B is a known future path. It is not built yet.

### Option A — one deployment per workspace (current default)

Each Slack workspace gets its own full deployment: its own handler process, its own database, its own Slack app install, and one `SLACK_BOT_TOKEN` env var. This matches the Client 1, 2, and 3 model above.

This approach needs no OAuth install flow. No workspace ID needs to travel through the storage layer. Each deployment can reach only one workspace's data, so there is no risk of data crossing between workspaces.

**Trade-off:** infrastructure cost rises with each new workspace. N workspaces need N deployments and N databases.

### Option B — one shared deployment for many workspaces

One deployment, with one handler process and one database, serves many Slack workspaces. Each event carries a `team_id` field from Slack. The app uses this field to tell workspaces apart.

This approach uses fewer resources. It needs real new infrastructure:

- An `installations` table. It maps `team_id` to an encrypted bot token. This table replaces the single `SLACK_BOT_TOKEN` env var. A Slack OAuth v2 install flow fills this table. A new route, `/slack/oauth/callback`, exchanges a one-time `code` for a bot token, through Slack's `oauth.v2.access` endpoint. This flow needs new `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET` credentials. Get these from the app's Basic Information page. Each app has one such pair. This differs from the per-workspace bot tokens.
- A `team_id` field, used as a partition key. Add it to `karma` (the key becomes `(team_id, user_id)`) and as a column on `events`. Thread this field through every method in `storage/StorageInterface.php`: `getKarma`, `recordEvent`, `getEvents`, and others. This makes isolation a structural property of the code, not a rule that each call site must remember. DynamoDB's `timestamp` index and Firestore's `(user_id, timestamp)` index must both include `team_id` too.
- Revocation on uninstall. Slack sends an `app_uninstalled` event. When this event arrives, delete that workspace's row from `installations` at once.

**Migrating from Option A to Option B later:** This is additive work, not a rewrite. First, backfill `team_id` on existing rows. This step is easy, because each single-tenant database already holds only one workspace's data, so every row gets the same known value. Next, merge the databases, if both deployments already use the same storage backend. Crossing backends, for example MySQL to DynamoDB, is the one genuinely hard case. Then add the OAuth route and the `installations` table. Then point both workspaces' Events API URLs at the one surviving deployment. Finally, run each workspace through the OAuth flow once, to store its own token.

### Security for Option B's `installations` table

Storing other workspaces' live bot tokens is a bigger responsibility than one env var. Handle it with care.

- **Encrypt the token at rest.** Do not rely only on disk-level database encryption. Encryption at the application level also protects against a compromised read path: a leaked connection string, an over-permissioned tool, or an injection bug elsewhere in the app. Use PHP's built-in `sodium` extension (`sodium_crypto_secretbox`), or the `defuse/php-encryption` library for a simpler API. Keep the encryption key outside the database. At minimum, use an env var. Where possible, use AWS KMS or GCP Secret Manager instead. Store only ciphertext in MySQL, DynamoDB, or Firestore. Decrypt only in memory, only at the moment of a Slack API call.
- **Split database access by role.** Follow least privilege. The webhook path only needs `SELECT` on `installations`, to look up a token. It never needs to write there. Only the OAuth callback route (install) and the uninstall handler (delete) need write access. Use two database users or roles, not one shared user with full read and write access. This way, a bug or a compromise in the larger, more exposed event-parsing code cannot insert, change, or delete another workspace's token.
- **Never log the token.** Keep `installations` separate from any table that debugging tools might export or query casually.
- **Rotate the encryption key if you suspect it is compromised.** Re-encrypt every stored token under the new key. Keep the key out of database backups.
