<?php

declare(strict_types=1);

namespace Shawware\Yakb\Storage;

/**
 * A plain-array-backed StorageInterface implementation.
 *
 * Used in tests only, so that anything depending on storage (routing,
 * slash commands) can be tested without a real database. MySqlStorage
 * implements the same interface against MySQL.
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, int> userId => cumulative score */
    private array $scores = [];

    /**
     * @var array<int, array{
     *     id: int,
     *     fromUser: string,
     *     toUser: string,
     *     points: int,
     *     channel: string,
     *     timestamp: \DateTimeImmutable
     * }>
     */
    private array $events = [];

    private int $nextEventId = 1;

    public function getScore(string $userId): ?array
    {
        if (!array_key_exists($userId, $this->scores)) {
            return null;
        }

        return $this->scoreRecord($userId);
    }

    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $points,
        string $channel,
        ?\DateTimeImmutable $occurredAt = null
    ): array {
        $this->scores[$toUser] = ($this->scores[$toUser] ?? 0) + $points;

        $this->events[] = [
            'id' => $this->nextEventId++,
            'fromUser' => $fromUser,
            'toUser' => $toUser,
            'points' => $points,
            'channel' => $channel,
            'timestamp' => $occurredAt ?? new \DateTimeImmutable(),
        ];

        return $this->scoreRecord($toUser);
    }

    public function getEvents(string $userId, \DateTimeImmutable $since): array
    {
        $matching = array_values(array_filter(
            $this->events,
            static fn (array $event): bool =>
                ($event['fromUser'] === $userId || $event['toUser'] === $userId)
                && $event['timestamp'] >= $since
        ));

        usort(
            $matching,
            static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']
        );

        return $matching;
    }

    public function getTopScores(int $limit): array
    {
        $scores = $this->scores;
        arsort($scores);

        $top = array_slice($scores, 0, $limit, preserve_keys: true);

        return array_map(
            fn (string $userId, int $score): array => $this->scoreRecord($userId),
            array_keys($top),
            array_values($top)
        );
    }

    /**
     * @return array{userId: string, score: int}
     */
    private function scoreRecord(string $userId): array
    {
        return [
            'userId' => $userId,
            'score' => $this->scores[$userId] ?? 0,
        ];
    }
}
