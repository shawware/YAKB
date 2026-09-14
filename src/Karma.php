<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb;

/**
 * Calculates a karma tier name from a cumulative karma total, using a
 * configurable, ordered list of tiers (see config/tiers.php).
 */
final class Karma
{
    /** @var array<int, array{name: string, min: int}> */
    private array $tiers;

    /**
     * @param array<int, array{name: string, min: int}> $tiers Ordered by
     *        ascending `min`. Each entry has a `name` and a `min` karma total.
     */
    public function __construct(array $tiers)
    {
        $this->tiers = $tiers;
    }

    /**
     * Returns the name of the highest tier whose `min` the karma total
     * meets or exceeds, or null if it's below every tier's `min`.
     */
    public function tierForKarma(int $karma): ?string
    {
        $tierName = null;

        foreach ($this->tiers as $tier) {
            if ($karma >= $tier['min']) {
                $tierName = $tier['name'];
            }
        }

        return $tierName;
    }
}
