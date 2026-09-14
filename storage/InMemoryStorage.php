<?php

// Copyright © 2026 shawware.com.au

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
    /** @var array<string, int> userId => cumulative karma */
    private array $karma = [];

    /**
     * @var array<int, array{
     *     id: int,
     *     fromUser: string,
     *     toUser: string,
     *     karma: int,
     *     channel: string,
     *     timestamp: \DateTimeImmutable
     * }>
     */
    private array $events = [];

    private int $nextEventId = 1;

    public function getKarma(string $userId): ?array
    {
        if (!array_key_exists($userId, $this->karma)) {
            return null;
        }

        return $this->karmaRecord($userId);
    }

    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $karma,
        string $channel,
        ?\DateTimeImmutable $occurredAt = null
    ): array {
        $this->karma[$toUser] = ($this->karma[$toUser] ?? 0) + $karma;

        $this->events[] = [
            'id' => $this->nextEventId++,
            'fromUser' => $fromUser,
            'toUser' => $toUser,
            'karma' => $karma,
            'channel' => $channel,
            'timestamp' => $occurredAt ?? new \DateTimeImmutable(),
        ];

        return $this->karmaRecord($toUser);
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

    public function getTopKarma(int $limit): array
    {
        $karma = $this->karma;
        arsort($karma);

        $top = array_slice($karma, 0, $limit, preserve_keys: true);

        return array_map(
            fn (string $userId, int $karma): array => $this->karmaRecord($userId),
            array_keys($top),
            array_values($top)
        );
    }

    public function getRank(string $userId): ?int
    {
        if (!array_key_exists($userId, $this->karma)) {
            return null;
        }

        $karma = $this->karma[$userId];
        $higher = 0;

        foreach ($this->karma as $otherKarma) {
            if ($otherKarma > $karma) {
                $higher++;
            }
        }

        return $higher + 1;
    }

    /**
     * @return array{userId: string, karma: int}
     */
    private function karmaRecord(string $userId): array
    {
        return [
            'userId' => $userId,
            'karma' => $this->karma[$userId] ?? 0,
        ];
    }
}
