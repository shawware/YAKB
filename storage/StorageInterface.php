<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Storage;

/**
 * Persists karma totals and the full karma event log.
 *
 * Implementations store a running karma total per user (the `karma`
 * table/collection) and a full audit log of individual karma events (the
 * `events` table/collection) — see CLAUDE.md's "Data Model" section.
 *
 * Tier is deliberately not stored here. It is derived data — always
 * computed from a karma total via `Karma::tierForKarma()`, never persisted
 * — so retuning `config/tiers.php` takes effect immediately for every
 * user, with no backfill and no risk of a stored tier drifting out of
 * sync with its karma total.
 */
interface StorageInterface
{
    /**
     * @return array{userId: string, karma: int}|null
     *         null if the user has no karma on record yet.
     */
    public function getKarma(string $userId): ?array;

    /**
     * Records a karma event: `$fromUser` gave `$karma` to `$toUser` in
     * `$channel`. Applies the karma change and returns the target user's
     * new total.
     *
     * @param \DateTimeImmutable|null $occurredAt Defaults to now; accepted
     *        explicitly so callers (and tests) can control event ordering.
     * @return array{userId: string, karma: int}
     */
    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $karma,
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
     *     karma: int,
     *     channel: string,
     *     timestamp: \DateTimeImmutable
     * }>
     */
    public function getEvents(string $userId, \DateTimeImmutable $since): array;

    /**
     * The highest-karma users, highest first.
     *
     * @return array<int, array{userId: string, karma: int}>
     */
    public function getTopKarma(int $limit): array;

    /**
     * A user's leaderboard rank: 1 + the number of users with strictly
     * more karma. Users tied on karma share the same rank.
     *
     * @return int|null null if the user has no karma on record yet.
     */
    public function getRank(string $userId): ?int;
}
