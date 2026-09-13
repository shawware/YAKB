<?php

declare(strict_types=1);

namespace Shawware\Yakb;

use Shawware\Yakb\Storage\StorageInterface;

/**
 * Shared routing for both Slack Events API callbacks and slash commands.
 * Included directly by each client's entry point (public/index.php,
 * handlers/lambda_handler.php) — not autoloaded via Composer, since it
 * sits outside src/.
 */
final class Router
{
    private const REACTION_EMOJI = 'tada';
    private const HISTORY_WINDOW_DAYS = 30;
    private const MONTH_WINDOW_DAYS = 30;
    private const TOP_SCORES_LIMIT = 10;

    public function __construct(
        private readonly Parser $parser,
        private readonly Karma $karma,
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi
    ) {
    }

    /**
     * Handles a decoded Slack Events API payload.
     *
     * @param array<string, mixed> $payload
     * @return string|null The challenge string to echo back for Slack's
     *         one-time url_verification handshake, or null otherwise (the
     *         caller should just respond HTTP 200 with an empty body).
     */
    public function handleEvent(array $payload): ?string
    {
        if (($payload['type'] ?? null) === 'url_verification') {
            return is_string($payload['challenge'] ?? null) ? $payload['challenge'] : null;
        }

        if (($payload['type'] ?? null) !== 'event_callback') {
            return null;
        }

        $event = $payload['event'] ?? [];

        if (!is_array($event) || ($event['type'] ?? null) !== 'message') {
            return null;
        }

        $channel = (string) ($event['channel'] ?? '');
        $fromUser = (string) ($event['user'] ?? '');
        $messageTimestamp = (string) ($event['ts'] ?? '');
        $text = (string) ($event['text'] ?? '');

        foreach ($this->parser->parse($text) as $mention) {
            $this->awardKarma($fromUser, $mention['userId'], $mention['points'], $channel, $messageTimestamp);
        }

        return null;
    }

    private function awardKarma(
        string $fromUser,
        string $toUser,
        int $points,
        string $channel,
        string $messageTimestamp
    ): void {
        $result = $this->storage->recordEvent($fromUser, $toUser, $points, $channel);
        $tier = $this->karma->tierForScore($result['score']);
        $tierText = $tier !== null ? " ({$tier})" : '';

        $this->slackApi->addReaction($channel, $messageTimestamp, self::REACTION_EMOJI);
        $this->slackApi->postMessage(
            $channel,
            "<@{$toUser}> now has {$result['score']} point" . ($result['score'] === 1 ? '' : 's') . "{$tierText}!"
        );
    }

    /**
     * Handles a decoded Slack slash-command payload (`/karma ...`).
     *
     * @param array<string, mixed> $payload
     * @return array{response_type: string, text: string}
     */
    public function handleSlashCommand(array $payload): array
    {
        $requestingUser = (string) ($payload['user_id'] ?? '');
        $text = trim((string) ($payload['text'] ?? ''));

        return match (true) {
            $text === '' => $this->replyOwnScore($requestingUser),
            $text === 'top' => $this->replyTopScores(),
            str_starts_with($text, 'history') => $this->replyHistory($text, $requestingUser),
            str_starts_with($text, 'month') => $this->replyMonth($text, $requestingUser),
            default => $this->replyUserScore($text),
        };
    }

    /** @return array{response_type: string, text: string} */
    private function replyOwnScore(string $userId): array
    {
        $score = $this->storage->getScore($userId)['score'] ?? 0;
        $tier = $this->karma->tierForScore($score);
        $rank = $this->rankOf($userId);

        $tierText = $tier !== null ? ", tier {$tier}" : '';
        $rankText = $rank !== null ? ", rank #{$rank}" : '';

        return $this->textResponse("You have {$score} points{$tierText}{$rankText}.");
    }

    /** @return array{response_type: string, text: string} */
    private function replyUserScore(string $text): array
    {
        $userId = $this->extractMentionedUserId($text);

        if ($userId === null) {
            return $this->textResponse('Usage: /karma @user');
        }

        $score = $this->storage->getScore($userId)['score'] ?? 0;
        $tier = $this->karma->tierForScore($score);
        $tierText = $tier !== null ? ", tier {$tier}" : '';

        return $this->textResponse("<@{$userId}> has {$score} points{$tierText}.");
    }

    /** @return array{response_type: string, text: string} */
    private function replyTopScores(): array
    {
        $top = $this->storage->getTopScores(self::TOP_SCORES_LIMIT);

        if ($top === []) {
            return $this->textResponse('No karma has been awarded yet.');
        }

        $lines = [];
        foreach ($top as $rank => $entry) {
            $lines[] = ($rank + 1) . ". <@{$entry['userId']}> — {$entry['score']}";
        }

        return $this->textResponse(implode("\n", $lines));
    }

    /** @return array{response_type: string, text: string} */
    private function replyHistory(string $text, string $requestingUser): array
    {
        $userId = $this->extractMentionedUserId($text) ?? $requestingUser;
        $since = (new \DateTimeImmutable())->modify('-' . self::HISTORY_WINDOW_DAYS . ' days');
        $events = $this->storage->getEvents($userId, $since);

        if ($events === []) {
            return $this->textResponse("No karma events for <@{$userId}> in the last " . self::HISTORY_WINDOW_DAYS . ' days.');
        }

        $lines = [];
        foreach ($events as $event) {
            $lines[] = "<@{$event['fromUser']}> → <@{$event['toUser']}>: {$event['points']}";
        }

        return $this->textResponse(implode("\n", $lines));
    }

    /** @return array{response_type: string, text: string} */
    private function replyMonth(string $text, string $requestingUser): array
    {
        $userId = $this->extractMentionedUserId($text) ?? $requestingUser;
        $since = (new \DateTimeImmutable())->modify('-' . self::MONTH_WINDOW_DAYS . ' days');
        $events = $this->storage->getEvents($userId, $since);

        $pointsReceived = array_sum(array_map(
            static fn (array $event): int => $event['toUser'] === $userId ? $event['points'] : 0,
            $events
        ));

        return $this->textResponse(
            "<@{$userId}> earned {$pointsReceived} points in the last " . self::MONTH_WINDOW_DAYS . ' days.'
        );
    }

    private function rankOf(string $userId): ?int
    {
        // Fetches the full leaderboard to find one user's rank. Fine at
        // karma-bot scale (per CLAUDE.md); a dedicated getRank() storage
        // method would be the fix if the user base ever grew large.
        $top = $this->storage->getTopScores(PHP_INT_MAX);

        foreach ($top as $index => $entry) {
            if ($entry['userId'] === $userId) {
                return $index + 1;
            }
        }

        return null;
    }

    private function extractMentionedUserId(string $text): ?string
    {
        if (preg_match('/<@([A-Z0-9]+)>/', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /** @return array{response_type: string, text: string} */
    private function textResponse(string $text): array
    {
        return ['response_type' => 'in_channel', 'text' => $text];
    }
}
