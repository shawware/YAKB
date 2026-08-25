# Yet Another KarmaBot (YAKB)

A Slack karma bot that watches channels for `@user ++` mentions and keeps a running score per person, with tiered recognition (Bronze/Silver/Gold/Platinum) as scores grow. Built as a single Python codebase deployed independently for different clients, each on its own hosting and storage stack.

## Features

- Tracks karma via `@user ++` mentions in Slack channels, with an emoji reaction and in-channel reply confirming the new score and tier
- Slash commands: `/karma`, `/karma @user`, `/karma top`, `/karma history [@user]`, `/karma month [@user]`
- Karma tiers (Bronze / Silver / Gold / Platinum) surfaced in bot responses, and written back to the user's Slack or Google Workspace profile where supported
- Pluggable storage layer (MySQL / DynamoDB / Firestore) so one codebase runs across different hosting environments

## Status

Architecture and design are defined; no code has been written yet. See [CLAUDE.md](CLAUDE.md) for full architecture, data model, and per-client deployment details.

## License

GPLv3 — see [LICENSE.txt](LICENSE.txt).
