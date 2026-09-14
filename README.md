# Yet Another KarmaBot (YAKB)

YAKB is a Slack karma bot. It watches channels for `@user ++` mentions. It keeps a running score for each person. As a score grows, the bot shows a tier. The default tiers are Bronze, Silver, Gold, and Platinum, but an operator can change them.

One PHP codebase supports several clients. Each client runs on its own host, with its own storage backend.

## Features

- The bot tracks karma from `@user ++` mentions in Slack channels. It adds an emoji reaction and replies in the channel with the new score and tier.
- A user cannot give karma to themselves. The bot reacts and replies to say so, instead.
- Slash commands: `/karma`, `/karma @user`, `/karma top`, `/karma history [@user]`, `/karma month [@user]`
- The bot shows a karma tier in its replies. Where the platform supports it, the bot also writes the tier to the user's Slack or Google Workspace profile.
- Tiers and their score thresholds are configurable. Edit `config/tiers.php` to change the tier names or thresholds, or to add or remove tiers. No UI and no code change are needed.
- A pluggable storage layer (MySQL, DynamoDB, or Firestore) lets one codebase run across different hosting environments.

## Status

Client 1 (shared hosting, MySQL) is built, tested, and running live against a real Slack workspace.

Client 2 (AWS Lambda, DynamoDB) and Client 3 (Cloud Run, Firestore) are designed but not yet built.

To install YAKB on shared hosting with MySQL, see [docs/install-shared-hosting-mysql.md](docs/install-shared-hosting-mysql.md).

See [CLAUDE.md](CLAUDE.md) for the full architecture, the data model, and the per-client design details.

## License

GPLv3 — see [LICENSE.txt](LICENSE.txt).
