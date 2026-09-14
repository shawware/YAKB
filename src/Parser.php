<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb;

/**
 * Parses a raw Slack message for karma mentions.
 *
 * Slack renders a user mention in event text as `<@U12345>`, not as a
 * literal `@user` — this is the format that must be matched against.
 * Slack sometimes includes a display-name suffix, `<@U12345|somename>`
 * (observed on mentions composed via the rich-text editor) — the pattern
 * tolerates and ignores that suffix.
 */
final class Parser
{
    private const PATTERN = '/<@([A-Z0-9]+)(?:\|[^>]*)?>\s*(\++)/';

    public function __construct(private readonly int $maxKarmaPerMessage = 5)
    {
    }

    /**
     * Finds every karma mention in the given message text.
     *
     * A "karma mention" is a user mention immediately followed by one or
     * more `+` characters. A mention with no `+` is not a karma event and
     * is not returned. Karma is capped at `maxKarmaPerMessage`; `capped`
     * tells the caller whether that cap was actually applied, so it can
     * tell the sender apart from an ordinary award.
     *
     * @return array<int, array{userId: string, karma: int, capped: bool}>
     */
    public function parse(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $mentions = [];

        foreach ($matches as $match) {
            $rawKarma = strlen($match[2]);

            $mentions[] = [
                'userId' => $match[1],
                'karma' => min($rawKarma, $this->maxKarmaPerMessage),
                'capped' => $rawKarma > $this->maxKarmaPerMessage,
            ];
        }

        return $mentions;
    }
}
