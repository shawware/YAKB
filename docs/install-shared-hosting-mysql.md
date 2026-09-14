# Install YAKB on Shared Hosting with MySQL

This guide covers Client 1: shared hosting, PHP, and MySQL. See [CLAUDE.md](../CLAUDE.md) for the architecture behind these steps, and for Client 2 (AWS Lambda) and Client 3 (Cloud Run), which are designed but not yet built.

Exact steps and panel navigation vary by host. This guide uses generic terms and describes the underlying requirement at each step, so you can find the equivalent option whatever your provider calls it. Where a specific host is known to behave differently, that's called out explicitly.

## Prerequisites

- A shared hosting account with a domain (or subdomain) you control, that supports PHP and MySQL
- SSH access to that account
- A Slack account that is a member of the workspace you want to install into
- Composer installed on the server (or the ability to install it yourself over SSH)

## 1. Create the Slack app

1. Go to [api.slack.com/apps](https://api.slack.com/apps) → **Create New App** → **From scratch**. Name it, and pick the workspace to install into.
2. **Socket Mode**, in the sidebar → confirm it is **off**. New apps have been seen with this on by default.
3. **App Home** — confirm a bot user exists (created by default for new apps).
4. **OAuth & Permissions** → **Bot Token Scopes** → add:
   - `channels:history`
   - `groups:history`
   - `chat:write`
   - `reactions:write`
   - `commands`
5. **Slash Commands** → **Create New Command** → command `/karma`. Leave the Request URL blank for now — it needs a live domain first (step 8).
6. **Basic Information** → **App Credentials** → copy the **Signing Secret**. This becomes `SLACK_SIGNING_SECRET`.
7. **OAuth & Permissions** → **Install to Workspace** → **Allow**. This needs workspace admin approval if you aren't the admin.
8. After install, **OAuth & Permissions** shows a **Bot User OAuth Token** (`xoxb-...`). This becomes `SLACK_BOT_TOKEN`.

Don't enable Event Subscriptions yet — that needs a live Request URL too (step 8).

## 2. Set up the domain with your hosting provider

In your host's control panel:

1. Add the domain or subdomain for this deployment.
2. Set the **document root** (sometimes called the "web directory") to the `public/` subfolder of wherever you'll place the repo — not the repo root. This is what keeps `.env`, `vendor/`, and the rest of the codebase unreachable by URL.
3. Confirm PHP 8.5 (or the version in `composer.json`) is selected for that domain.
4. Enable HTTPS (most hosts offer a free Let's Encrypt cert). Slack requires `https://` for both Request URLs.

## 3. Get the code onto the server

Over SSH:

```
git clone <your-remote-url> ~/yourdomain.com
cd ~/yourdomain.com
composer install
```

## 4. Create `.env`

Directly on the server, at the project root (a sibling of `composer.json`, outside `public/`) — never commit this file:

```
DB_DSN=mysql:host=<db-hostname>;port=3306;dbname=<db-name>;charset=utf8mb4
DB_USER=<mysql-username>
DB_PASS=<mysql-password>

SLACK_SIGNING_SECRET=<from step 1.5>
SLACK_BOT_TOKEN=<from step 1.7>
```

See `.env.example` in the repo root for the template.

## 5. Create the MySQL database

You need three things: a database, a MySQL user, and that user granted access to that specific database. On many hosts, all three are set up through the hosting **control panel** rather than phpMyAdmin — phpMyAdmin typically only manages the contents of a database you already have access to, not account-level provisioning.

1. In your host's control panel, find the database section (often called "MySQL Databases" or similar) and create a new database.
2. In the same section, create (or reuse) a MySQL user, and **explicitly grant that user access to this specific database**. Many hosts require this grant even for a user that already exists for other databases — creating the user elsewhere doesn't automatically extend to a new database.
3. Note the database's hostname, name, username, and password for `.env`'s `DB_DSN`/`DB_USER`/`DB_PASS`. If your host instead gives you direct MySQL access (e.g. via SSH or phpMyAdmin's "Privileges" tab), the equivalent is a plain `CREATE DATABASE` plus `GRANT ALL ON dbname.* TO 'user'@'host'`.

## 6. Run migrations

```
php bin/migrate.php
```

This creates `scores`, `events`, and a `schema_migrations` tracking table. Safe to re-run — already-applied migrations are skipped.

## 7. Confirm the site responds

```
curl -i https://yourdomain.com/
```

Should return `200` and a small static placeholder page. If this doesn't work, nothing past this point will either — fix connectivity/PHP/`.htaccess` first.

## 8. Finish the Slack app configuration

Back at api.slack.com:

1. **Slash Commands** → edit `/karma` → Request URL: `https://yourdomain.com/slack/commands`
2. **Event Subscriptions** → enable → Request URL: `https://yourdomain.com/slack/events`. Slack sends a one-time `url_verification` challenge here immediately; it must succeed before you can save.
3. Under **Subscribe to bot events**, add `message.channels` (public channels) and `message.groups` (private channels, if needed).
4. Click **Save Changes**, and reinstall the app if Slack prompts you to.

## 9. Invite the bot and test

```
/invite @yourbotname
```

in the channel(s) you want monitored. Then post `<@someone> ++` (select the mention from Slack's autocomplete, don't just type plain text) and confirm the emoji reaction and reply both appear. Try `/karma` too.

---

## Troubleshooting

These are the actual problems hit getting a real install working — check here before assuming it's a new bug.

### `getenv()` returns `false` even though `.env` looks correct

Some shared hosts disable the `putenv()` function for security (confirmed on DreamHost; worth checking on any host you use). `vlucas/phpdotenv` needs `putenv()` to make a loaded value visible to `getenv()`. Without it, `.env` still loads correctly into `$_ENV` and `$_SERVER`, but `getenv()` silently returns `false` for every value.

This is why the codebase reads config through `envValue()` in `env.php` (checks `$_ENV`/`$_SERVER` first) rather than `getenv()` directly. If you're debugging with a one-off `php -r '...'` snippet, remember it'll show the same symptom — check `$_ENV` instead.

### `PDOException: Access denied for user ... to database ...`

The database and the user both need to exist, **and** the user needs to be explicitly granted access to that specific database — on many hosts this doesn't happen automatically even for a user that already exists elsewhere. See step 5 above.

### Slash command fails with "the app did not respond," and your access log shows no request for it at all

**Socket Mode is probably back on.** Step 1.2 above should have already turned this off, but it can be re-enabled later (by anyone with access to the app config), silently breaking a previously-working install. Find "Socket Mode" in the app's sidebar and confirm it's off.

### Confusing "Incoming Webhooks" with "Event Subscriptions"

They're unrelated features. **Incoming Webhooks** generates a URL for posting messages *into* Slack from an external system — the opposite direction of what this bot needs (it already posts via `chat.postMessage` using the bot token). The feature this project needs is **Event Subscriptions**, a separate sidebar item.

### Can't find the bot token

It doesn't exist until after you install the app — it's not on "Basic Information." Find it on **OAuth & Permissions**, under "Bot User OAuth Token," after clicking **Install to Workspace** (or **Install App**) and approving it. "Client Secret" and "Verification Token," both on Basic Information, are not needed for this project at all (Client Secret is only for a full OAuth install flow this project doesn't use; Verification Token is a deprecated credential superseded by the Signing Secret).

### Added a bot event subscription, but nothing changed

Click **Save Changes** at the bottom of the Event Subscriptions page — easy to miss, since the field can look filled in without the change taking effect. Slack usually also asks you to reinstall the app after this kind of change; do that too.

### The bot autocompletes/`@mention`s fine in a channel, but events still aren't arriving

That only confirms the bot is a channel member — it says nothing about event delivery. Confirm delivery by checking your server's access log for an actual `POST /slack/events` or `POST /slack/commands` request at the time you tested.

### Strange `ModSecurity` warnings in the Apache error log about `/.git/` or `/.env`

This is background internet noise, not something wrong with your install — automated scanners constantly probe every public web server for common misconfigurations. Your host's firewall (many run something like ModSecurity) is likely blocking these attempts, and even if it weren't, `.git` and `.env` sit outside `public/` (the only web-exposed directory), so they were never reachable anyway.
