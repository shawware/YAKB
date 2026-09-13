# Yet Another KarmaBot (YAKB)

YAKB is a Slack karma bot. It watches channels for `@user ++` mentions. It keeps a running score for each person. As a score grows, the bot shows a tier. The default tiers are Bronze, Silver, Gold, and Platinum, but an operator can change them.

One PHP codebase supports several clients. Each client runs on its own host, with its own storage backend.

## Features

- The bot tracks karma from `@user ++` mentions in Slack channels. It adds an emoji reaction and replies in the channel with the new score and tier.
- Slash commands: `/karma`, `/karma @user`, `/karma top`, `/karma history [@user]`, `/karma month [@user]`
- The bot shows a karma tier in its replies. Where the platform supports it, the bot also writes the tier to the user's Slack or Google Workspace profile.
- Tiers and their score thresholds are configurable. Edit `config/tiers.php` to change the tier names or thresholds, or to add or remove tiers. No UI and no code change are needed.
- A pluggable storage layer (MySQL, DynamoDB, or Firestore) lets one codebase run across different hosting environments.

## Status

The architecture and design are set. Client 1 (DreamHost shared hosting) is under active development.

Built so far, with tests:
- Configurable karma tier calculation (`src/Karma.php`, `config/tiers.php`)
- The `@user ++` message parser (`src/Parser.php`)

See [CLAUDE.md](CLAUDE.md) for the full architecture, the data model, and the per-client deployment details.

## License

GPLv3 — see [LICENSE.txt](LICENSE.txt).
