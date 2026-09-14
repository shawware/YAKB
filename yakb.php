<?php

// Copyright © 2026 shawware.com.au

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
    private const SELF_KARMA_EMOJI = 'no_good';
    private const HISTORY_WINDOW_DAYS = 30;
    private const MONTH_WINDOW_DAYS = 30;
    private const TOP_SCORES_LIMIT = 10;

    public function __construct(
        private readonly Parser $parser,
        private readonly Karma $karma,
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi,
        private readonly int $maxKarmaPerMessage = 5
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

        // The bot's own replies are also delivered back as message events,
        // since the bot is a channel member. Skip them — nothing here
        // should ever react to the bot's own output.
        if (($event['subtype'] ?? null) === 'bot_message') {
            return null;
        }

        $channel = (string) ($event['channel'] ?? '');
        $fromUser = (string) ($event['user'] ?? '');
        $messageTimestamp = (string) ($event['ts'] ?? '');
        $text = (string) ($event['text'] ?? '');

        foreach ($this->parser->parse($text) as $mention) {
            $this->awardKarma(
                $fromUser,
                $mention['userId'],
                $mention['karma'],
                $channel,
                $messageTimestamp,
                $mention['capped']
            );
        }

        return null;
    }

    private function awardKarma(
        string $fromUser,
        string $toUser,
        int $karma,
        string $channel,
        string $messageTimestamp,
        bool $capped = false
    ): void {
        if ($fromUser === $toUser) {
            $this->slackApi->addReaction($channel, $messageTimestamp, self::SELF_KARMA_EMOJI);
            $this->slackApi->postMessage(
                $channel,
                "Nice try, <@{$fromUser}> — you can't give yourself karma 😏"
            );

            return;
        }

        $result = $this->storage->recordEvent($fromUser, $toUser, $karma, $channel);
        $tier = $this->karma->tierForScore($result['score']);
        $tierText = $tier !== null ? " ({$tier})" : '';
        $cappedText = $capped ? " (capped at {$this->maxKarmaPerMessage} karma per message)" : '';

        $this->slackApi->addReaction($channel, $messageTimestamp, self::REACTION_EMOJI);
        $this->slackApi->postMessage(
            $channel,
            "<@{$toUser}> now has {$result['score']} karma{$tierText}!{$cappedText}"
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
        $rank = $this->storage->getRank($userId);

        $tierText = $tier !== null ? ", tier {$tier}" : '';
        $rankText = $rank !== null ? ", rank #{$rank}" : '';

        return $this->textResponse("You have {$score} karma{$tierText}{$rankText}.");
    }

    /** @return array{response_type: string, text: string} */
    private function replyUserScore(string $text): array
    {
        $userId = $this->extractMentionedUserId($text);

        if ($userId === null) {
            return $this->unknownUserResponse();
        }

        $score = $this->storage->getScore($userId)['score'] ?? 0;
        $tier = $this->karma->tierForScore($score);
        $tierText = $tier !== null ? ", tier {$tier}" : '';

        return $this->textResponse("<@{$userId}> has {$score} karma{$tierText}.");
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
        $argument = trim(substr($text, strlen('history')));
        $userId = $this->resolveOptionalUserArgument($argument, $requestingUser);

        if ($userId === null) {
            return $this->unknownUserResponse();
        }

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
        $argument = trim(substr($text, strlen('month')));
        $userId = $this->resolveOptionalUserArgument($argument, $requestingUser);

        if ($userId === null) {
            return $this->unknownUserResponse();
        }

        $since = (new \DateTimeImmutable())->modify('-' . self::MONTH_WINDOW_DAYS . ' days');
        $events = $this->storage->getEvents($userId, $since);

        $karmaReceived = array_sum(array_map(
            static fn (array $event): int => $event['toUser'] === $userId ? $event['points'] : 0,
            $events
        ));

        return $this->textResponse(
            "<@{$userId}> earned {$karmaReceived} karma in the last " . self::MONTH_WINDOW_DAYS . ' days.'
        );
    }

    private function extractMentionedUserId(string $text): ?string
    {
        // Slack sometimes includes a display-name suffix on a mention,
        // <@U12345|somename> — tolerate and ignore it, same as Parser does.
        if (preg_match('/<@([A-Z0-9]+)(?:\|[^>]*)?>/', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolves the optional `@user` argument for `history`/`month`: an
     * empty argument means "the requesting user." A non-empty argument
     * that doesn't resolve to a real mention is a genuine error — it must
     * not silently fall back to the requesting user, or it looks like
     * data for the wrong person instead of an error.
     */
    private function resolveOptionalUserArgument(string $argument, string $requestingUser): ?string
    {
        if ($argument === '') {
            return $requestingUser;
        }

        return $this->extractMentionedUserId($argument);
    }

    /** @return array{response_type: string, text: string} */
    private function unknownUserResponse(): array
    {
        return $this->textResponse(
            "I don't recognize that user. Type @ and pick them from Slack's suggestions, rather than typing the full name."
        );
    }

    /** @return array{response_type: string, text: string} */
    private function textResponse(string $text): array
    {
        return ['response_type' => 'in_channel', 'text' => $text];
    }
}
