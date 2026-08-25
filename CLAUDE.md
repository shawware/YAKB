# Yet Another KarmaBot

This is a Slack karma bot. It monitors channels for `@user ++` patterns and keeps a running score for each user. One shared Python codebase supplies three different clients.

**Status:** The architecture is defined. No code exists yet. This document describes the intended design, not an existing implementation.

**License:** The project uses the GPLv3 license (see `LICENSE.txt`).

## What It Does

- The bot monitors Slack channels for messages that contain one or more `@user` mentions, each followed by one or more `+` signs.
- The bot awards points equal to the number of `+` signs after each mention. A single message can award karma to more than one user. The bot treats each mention as an independent karma event. The bot ignores self-karma, where the sender and the target are the same user. The bot does not support negative karma (`--`). It recognizes only `+` sequences.
- The bot saves scores and a full event log to a database.
- The bot replies in the channel with each awarded user's new karma score and tier.
- The bot adds an emoji reaction to the triggering message to confirm receipt.
- The bot assigns a karma tier (Bronze, Silver, Gold, or Platinum) based on the user's cumulative score.
- For Client 2 only, the bot updates the user's Slack profile card with the current tier.
- For Client 3 only, the bot updates the user's Google Workspace Directory profile with the current tier.
- The bot provides slash commands for score queries, history, and leaderboards.

## Architecture

One Python codebase supplies all three clients. It uses interchangeable storage adapters and a small, client-specific entry point for each client.

```
karmabot/
├── core/
│   ├── parser.py        # regex, message parsing
│   ├── karma.py         # score logic, tier calculation, profile updates
│   └── slack_api.py     # posting messages, reactions, profile writes
│
├── storage/
│   ├── base.py          # abstract interface
│   ├── mysql.py         # Client 1
│   ├── dynamodb.py      # Client 2
│   └── firestore.py     # Client 3
│
├── app.py               # Flask app, shared by all clients
│
└── handlers/
    ├── passenger_wsgi.py    # Client 1 entry point
    ├── lambda_handler.py    # Client 2 entry point
    └── cloud_run_handler.py # Client 3 entry point
```

The `KARMA_STORAGE_BACKEND` environment variable selects the storage backend: `mysql`, `dynamodb`, or `firestore`. At startup, `app.py` reads this variable once and creates the matching adapter from `storage/`. `app.py` stays identical across all three clients. Only the entry point and the environment differ.

### Local Development

None of the three runtimes (Passenger, Lambda, Cloud Run) run continuously on a developer machine. To test the Events API locally, you need:
- A tunnel (for example, ngrok or localtunnel) that exposes the local Flask app so Slack can deliver webhooks to it
- A local or emulated storage backend for each client: MySQL through `docker-compose`, DynamoDB Local, or the Firestore emulator

Do not point local development at production tables or collections.

### Slack Integration

All clients use the **Slack Events API** (HTTP webhooks). None use Socket Mode. Slack sends event data as an HTTP POST to a public URL. The handler processes the event and responds. Socket Mode does not work for any deployment target, because none of them runs a persistent process.

Slack sends a URL verification challenge the first time you configure the Events API endpoint. You must deploy the handler and confirm it is live before you configure this endpoint in the Slack app dashboard.

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

**Credentials** — never in code, always in environment variables:

| Variable | Description |
|---|---|
| `SLACK_SIGNING_SECRET` | Verifies requests came from Slack |
| `SLACK_BOT_TOKEN` | `xoxb-...` token for calling the Slack API |

You must invite the bot to each channel it monitors. Use `/invite @karmabot`.

### Karma Tiers

The code in `core/karma.py` calculates the tier from the cumulative score. All bot responses include the tier. For Client 2, the bot also writes the tier to a custom Slack profile field each time the tier changes. For Client 3, the bot writes the tier to a custom user attribute in the Google Workspace Directory each time the tier changes.

| Tier | Threshold |
|---|---|
| Bronze | 1 – 49 |
| Silver | 50 – 199 |
| Gold | 200 – 499 |
| Platinum | 500+ |

For Client 2, a Slack workspace admin must define the custom profile field before tier profile writes can work. For Client 3, a Google Workspace admin must define a custom user attribute (for example, "Karma Tier") in the Directory schema before tier writes can work.

### Slash Commands

You register slash commands in the Slack app dashboard. All commands send an HTTP POST to the same Flask app as events. The app routes each command by its path.

| Command | Description |
|---|---|
| `/karma` | Your own score, tier, and leaderboard rank |
| `/karma @user` | Another user's score and tier |
| `/karma top` | Leaderboard, top N users |
| `/karma history [@user]` | Recent karma events — who gave to whom |
| `/karma month [@user]` | Points accrued in the past 30 days |

### Data Model

All three storage backends share two tables.

The **scores** table holds the current karma totals:
- `user_id` (partition key / primary key)
- `username`
- `score`
- `tier`

The **events** table holds the full audit log. It enables history queries and score recalculation:
- `id`
- `from_user`
- `to_user`
- `points`
- `channel`
- `timestamp`

The `storage/base.py` interface must expose `get_events(user_id, since)` for date-range history queries. MySQL uses a standard index on `timestamp`. A composite `(user_id, timestamp)` index can improve MySQL performance further, because queries filter on both fields. DynamoDB requires a **Global Secondary Index on `timestamp`**. You can add this index to an existing table with `UpdateTable`; DynamoDB backfills it automatically, so a table rebuild is not necessary. Creating the index at table-creation time avoids the backfill wait later. Firestore requires a **composite index on `(user_id, timestamp)`**, because range queries across two fields need it. Define this index in `firestore.indexes.json` before you deploy.

`/karma top` and rank queries need `scores` sorted by `score`. MySQL supports this directly with `ORDER BY score DESC`. Add a plain index on `score` to keep rank-count queries (`COUNT(*) WHERE score > ?`) efficient as the table grows. Firestore also supports this directly. Single-field queries and count aggregations are indexed automatically, so `firestore.indexes.json` needs no new entry. DynamoDB needs a dedicated **Global Secondary Index** with a constant partition key (for example, `type = "SCORE"`) and `score` as the sort key. You can add this index to an existing table the same way as the `timestamp` GSI, with no rebuild required. Query that partition, sorted descending with a limit, for `/karma top N`. Compute rank with a `Select=COUNT` query for `score` greater than the user's score, plus one. A constant partition key concentrates reads and writes on a single GSI partition. At karma-bot scale, this stays well within DynamoDB's per-partition throughput limit.

DynamoDB Global Secondary Index reads are always eventually consistent. This is a fixed property of DynamoDB, not a setting, and it applies to both GSIs above. On Client 2, a `/karma history` or `/karma month` query, or a `/karma top` or rank query, run within about a second of a karma event can momentarily miss that event. The karma-event confirmation message does not read through either GSI, so this limitation affects only queries run immediately after an event.

Score updates must use atomic increment operations. Do not read the current value and then write a new one. Use SQL `UPDATE scores SET score = score + N WHERE user_id = ?`, DynamoDB `UpdateItem` with `ADD`, or Firestore `Increment()`. A read-modify-write cycle can silently drop points when concurrent karma events target the same user.

The score update and its matching event-log entry must happen in a single transaction. `storage/base.py` must expose one method that performs both writes atomically, so a partial failure cannot leave `scores` and `events` out of sync: a local transaction in MySQL, `TransactWriteItems` in DynamoDB, `runTransaction` in Firestore.

### Testing

The same codebase runs against three different storage backends. The `storage/base.py` interface therefore needs conformance tests that run against all three adapters, using the local or emulated backends described under Local Development. These tests catch behavioral drift between adapters. For example, a `get_events` date-range query can work correctly on MySQL but return wrong results on Firestore if the composite index is missing.

### Response Behaviour

A message can contain more than one valid `@user ++` mention. The bot treats each mention as an independent karma event, each with its own atomic score-and-event write. For a message with one or more valid mentions, the bot performs two actions within Slack's 3-second response window:
1. It adds one emoji reaction to the original message, regardless of how many mentions it contains.
2. It posts one reply in the channel listing the updated score and tier for each mentioned user.

The bot only processes original messages. It ignores `message_changed` events. Slack delivers `message_changed` on the same `message.channels` subscription when a user edits a message, and an edit must never register as a new karma event.

The handler (Lambda, Passenger, or Cloud Run) must acknowledge Slack's POST with an HTTP 200 response quickly. All processing must complete within 3 seconds, or Slack retries the delivery. At karma-bot scale, a single synchronous handler fits this window without difficulty. This handler performs one atomic storage write per mention and two Slack API calls per message.

Slack's Events API delivers each event at least once. A slow or dropped HTTP 200 response triggers a retry that carries the same event. Handlers must de-duplicate events by Slack's `event_id` before processing them. For example, check the events table for the `event_id`, or use a short-lived cache. Without de-duplication, a retried delivery will double-award karma.

The bot must log every storage and Slack API failure rather than fail silently. Client 2 uses CloudWatch. Client 3 uses Cloud Logging. Client 1 writes to an application log file, since shared hosting provides no built-in logging service.

---

## Client 1

**Profile:** Slack free tier, Google Workspace (paid), shared hosting with MySQL.

**Runtime:** The app runs on shared hosting through Phusion Passenger (WSGI). Entry point: `handlers/passenger_wsgi.py`.

**Storage:** MySQL on shared hosting, accessed through `pymysql`. This adds zero marginal cost, because the hosting already provides it.

**Deployment:** Where the host provides shell and git access, deploy with `git pull` on the server. Then restart the Passenger process. This gives commit-level versioning and a rollback path (`git log`, `git checkout <sha>`). On hosts without git access, deploy over SSH or FTP instead. Install dependencies into a virtualenv with pip (`flask`, `pymysql`, `slack-sdk`).

**Key constraints:**
- Phusion Passenger can restart the process between requests on idle sites. Do not store data in memory between requests.
- Before you configure the Events API, confirm that the host permits inbound webhooks from external IPs.
- Slack's free tier does not support custom profile fields. The bot displays karma tiers only in its responses.
- Shared hosting has no equivalent to CloudWatch or Cloud Logging. The app must write storage and Slack API failures to its own log file. This constraint follows from the shared-hosting profile, not from a design choice.

---

## Client 2

**Profile:** Slack paid tier, Google Workspace (paid), AWS partner.

**Runtime:** The app runs on AWS Lambda. Entry point: `handlers/lambda_handler.py`. This handler uses `Mangum` or `aws-wsgi` to present Lambda events as WSGI requests to the shared Flask app.

**Storage:** DynamoDB. At karma-bot scale, usage stays within the always-free tier (25 GB storage, 25 RCU/WCU). The `events` table requires a Global Secondary Index on `timestamp`, and the `scores` table requires a Global Secondary Index on `score` for `/karma top` and rank queries (see Data Model). Create both indexes when you first provision the tables.

**Infrastructure:**
- API Gateway (HTTP API) sends requests to Lambda, which reads and writes DynamoDB.
- The Lambda execution role must have DynamoDB read/write permissions and CloudWatch logging permissions.
- Initial setup requires permission to create IAM roles. If this permission is restricted, coordinate with an AWS admin.

**Karma tiers:** Fully supported. A workspace admin must define a custom profile field (for example, "Karma Tier") before the bot can write to it. The bot updates this field each time a user's tier changes.

**Deployment:** Deploy with the AWS CLI, SAM, or CDK. Package only this client's dependencies. Exclude `pymysql` and the Google packages to keep Lambda cold-start time and package size down. The app must source `SLACK_SIGNING_SECRET` and `SLACK_BOT_TOKEN` from AWS Secrets Manager or SSM Parameter Store at cold start. Do not store these as plaintext Lambda environment variables. This matches Client 3's use of Secret Manager.

**Cost:** Near zero. Usage stays well within the AWS free tier, and AWS partner credits likely cover any remaining cost.

---

## Client 3

**Profile:** Slack paid tier, Google Workspace (paid), GCP partner.

**Runtime:** The app runs on Cloud Run, or on Cloud Functions 2nd gen. Entry point: `handlers/cloud_run_handler.py`. Cloud Run serves the Flask app directly as a container. Unlike Client 2, this runtime needs no WSGI adapter library.

**Storage:** Firestore. This is serverless and scales to zero. At karma-bot scale, usage stays within the GCP free tier. The `events` collection requires a **composite index on `(user_id, timestamp)`**. Define this index in `firestore.indexes.json` and deploy it before first use.

**Infrastructure:**
- Cloud Run sends requests directly to Firestore.
- The service account must have Firestore read/write permissions and Cloud Logging permissions.
- For GWS Directory writes, the service account must have domain-wide delegation, granted in the GWS Admin console. The GCP project must also have the Admin SDK Directory API enabled.

**Karma tiers:** Fully supported. A GWS admin must define a custom user attribute (for example, "Karma Tier") in the Directory schema before the bot can write to it. The bot updates this attribute each time a user's tier changes.

**Deployment:** Deploy with `gcloud run deploy` or Cloud Build. Package only this client's dependencies. Exclude `pymysql` and `boto3` to keep the container image small. Set `SLACK_SIGNING_SECRET` and `SLACK_BOT_TOKEN` as Cloud Run environment variables. The app must source these values from Secret Manager.

**Cost:** Near zero. Usage stays well within the GCP free tier, and GCP partner credits likely cover any remaining cost.

---

## Slack App Setup (all clients)

1. Create the app at api.slack.com. This requires a Slack account; it does not require workspace admin access.
2. Add a Bot User.
3. Configure the OAuth scopes listed above. Omit `users.profile:write` for Client 1.
4. Register the slash commands in the app dashboard.
5. Deploy the handler. Then configure the Events API endpoint URL.
6. Subscribe to `message.channels`. Also subscribe to `message.groups` if the bot must monitor private channels.
7. Install the app to the workspace. This requires workspace admin approval.
8. For Client 2 only: have a Slack workspace admin define the "Karma Tier" custom profile field.
9. For Client 3 only: have a GWS admin define the "Karma Tier" custom user attribute in the Directory schema, and grant the GCP service account domain-wide delegation.
10. Invite the bot to each channel: `/invite @karmabot`.

---

## Dependencies

| Package | Purpose |
|---|---|
| `flask` | HTTP handling, shared by all three clients |
| `slack-sdk` | Slack API calls and request verification |
| `pymysql` | Client 1 MySQL storage adapter |
| `boto3` | Client 2 DynamoDB storage adapter |
| `mangum` | Wraps Flask as a Lambda handler (Client 2) |
| `google-cloud-firestore` | Client 3 Firestore storage adapter |
| `google-api-python-client` | Client 3 GWS Directory API (tier profile writes) |
| `google-auth` | Client 3 service account authentication |
