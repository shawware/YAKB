# Yet Another KarmaBot (YAKB)

YAKB is a Slack karma bot. It watches channels for `@user ++` mentions. It keeps a running score for each person. As a score grows, the bot shows a tier: Bronze, Silver, Gold, or Platinum.

One PHP codebase supports several clients. Each client runs on its own host, with its own storage backend.

## Features

- The bot tracks karma from `@user ++` mentions in Slack channels. It adds an emoji reaction and replies in the channel with the new score and tier.
- Slash commands: `/karma`, `/karma @user`, `/karma top`, `/karma history [@user]`, `/karma month [@user]`
- The bot shows karma tiers (Bronze, Silver, Gold, Platinum) in its replies. Where the platform supports it, the bot also writes the tier to the user's Slack or Google Workspace profile.
- A pluggable storage layer (MySQL, DynamoDB, or Firestore) lets one codebase run across different hosting environments.

## Status

The architecture and design are set. No code exists yet. See [CLAUDE.md](CLAUDE.md) for the full architecture, the data model, and the per-client deployment details.

## License

GPLv3 — see [LICENSE.txt](LICENSE.txt).
