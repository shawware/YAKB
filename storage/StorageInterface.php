<?php

declare(strict_types=1);

namespace Shawware\Yakb\Storage;

/**
 * Persists karma scores and the full karma event log.
 *
 * Implementations store a running score per user (the `scores`
 * table/collection) and a full audit log of individual karma events (the
 * `events` table/collection) — see CLAUDE.md's "Data Model" section.
 *
 * Tier is deliberately not stored here. It is derived data — always
 * computed from a score via `Karma::tierForScore()`, never persisted —
 * so retuning `config/tiers.php` takes effect immediately for every user,
 * with no backfill and no risk of a stored tier drifting out of sync with
 * its score.
 */
interface StorageInterface
{
    /**
     * @return array{userId: string, score: int}|null
     *         null if the user has no score on record yet.
     */
    public function getScore(string $userId): ?array;

    /**
     * Records a karma event: `$fromUser` gave `$points` to `$toUser` in
     * `$channel`. Applies the score change and returns the target user's
     * new score.
     *
     * @param \DateTimeImmutable|null $occurredAt Defaults to now; accepted
     *        explicitly so callers (and tests) can control event ordering.
     * @return array{userId: string, score: int}
     */
    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $points,
        string $channel,
        ?\DateTimeImmutable $occurredAt = null
    ): array;

    /**
     * Events involving the given user (as either giver or receiver),
     * newest first, that occurred at or after `$since`.
     *
     * @return array<int, array{
     *     id: int,
     *     fromUser: string,
     *     toUser: string,
     *     points: int,
     *     channel: string,
     *     timestamp: \DateTimeImmutable
     * }>
     */
    public function getEvents(string $userId, \DateTimeImmutable $since): array;

    /**
     * The top-scoring users, highest first.
     *
     * @return array<int, array{userId: string, score: int}>
     */
    public function getTopScores(int $limit): array;
}
