<?php

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

    /**
     * Finds every karma mention in the given message text.
     *
     * A "karma mention" is a user mention immediately followed by one or
     * more `+` characters. A mention with no `+` is not a karma event and
     * is not returned.
     *
     * @return array<int, array{userId: string, points: int}>
     */
    public function parse(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $mentions = [];

        foreach ($matches as $match) {
            $mentions[] = [
                'userId' => $match[1],
                'points' => strlen($match[2]),
            ];
        }

        return $mentions;
    }
}
