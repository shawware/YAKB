<?php

declare(strict_types=1);

namespace Shawware\Yakb\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Shawware\Yakb\Storage\StorageInterface;

/**
 * Shared behaviour every StorageInterface implementation must satisfy.
 *
 * Run against InMemoryStorage (InMemoryStorageTest) and, in Step 4, against
 * MySqlStorage too — proving both backends behave identically rather than
 * duplicating these assertions per backend.
 */
abstract class StorageContractTestCase extends TestCase
{
    abstract protected function createStorage(): StorageInterface;

    public function testGetScoreIsNullForAnUnknownUser(): void
    {
        $storage = $this->createStorage();

        $this->assertNull($storage->getScore('U_UNKNOWN'));
    }

    public function testRecordEventAccumulatesScoreForTheRecipient(): void
    {
        $storage = $this->createStorage();

        $storage->recordEvent('U_FROM', 'U_TO', 2, 'C1');
        $result = $storage->recordEvent('U_FROM', 'U_TO', 3, 'C1');

        $this->assertSame(['userId' => 'U_TO', 'score' => 5], $result);
    }

    public function testGetScoreReflectsRecordedEvents(): void
    {
        $storage = $this->createStorage();

        $storage->recordEvent('U_FROM', 'U_TO', 12, 'C1');

        $this->assertSame(
            ['userId' => 'U_TO', 'score' => 12],
            $storage->getScore('U_TO')
        );
    }

    public function testGetEventsFiltersByUserAndDateAndOrdersNewestFirst(): void
    {
        $storage = $this->createStorage();

        $oldest = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $middle = new \DateTimeImmutable('2026-02-01T00:00:00Z');
        $newest = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $since = new \DateTimeImmutable('2026-01-15T00:00:00Z');

        // Before $since — must be excluded.
        $storage->recordEvent('U_A', 'U_B', 1, 'C1', $oldest);
        // U_B gave points here — must still show up in U_B's history.
        $storage->recordEvent('U_B', 'U_A', 2, 'C1', $middle);
        $storage->recordEvent('U_A', 'U_B', 3, 'C1', $newest);
        // A different user pair entirely — must be excluded.
        $storage->recordEvent('U_X', 'U_Y', 4, 'C1', $newest);

        $events = $storage->getEvents('U_B', $since);

        $this->assertCount(2, $events);
        // assertEquals, not assertSame: MySqlStorage round-trips the
        // timestamp through a string and returns a new DateTimeImmutable
        // instance, so object identity isn't preserved — only the value is.
        $this->assertEquals($newest, $events[0]['timestamp']);
        $this->assertEquals($middle, $events[1]['timestamp']);
    }

    public function testGetTopScoresOrdersDescendingAndRespectsLimit(): void
    {
        $storage = $this->createStorage();

        $storage->recordEvent('U_FROM', 'U_LOW', 1, 'C1');
        $storage->recordEvent('U_FROM', 'U_HIGH', 150, 'C1');
        $storage->recordEvent('U_FROM', 'U_MID', 10, 'C1');

        $top = $storage->getTopScores(2);

        $this->assertSame(
            [
                ['userId' => 'U_HIGH', 'score' => 150],
                ['userId' => 'U_MID', 'score' => 10],
            ],
            $top
        );
    }

    public function testGetRankIsNullForAnUnknownUser(): void
    {
        $storage = $this->createStorage();

        $this->assertNull($storage->getRank('U_UNKNOWN'));
    }

    public function testGetRankOrdersHighestScoreFirst(): void
    {
        $storage = $this->createStorage();

        $storage->recordEvent('U_FROM', 'U_LOW', 1, 'C1');
        $storage->recordEvent('U_FROM', 'U_HIGH', 150, 'C1');
        $storage->recordEvent('U_FROM', 'U_MID', 10, 'C1');

        $this->assertSame(1, $storage->getRank('U_HIGH'));
        $this->assertSame(2, $storage->getRank('U_MID'));
        $this->assertSame(3, $storage->getRank('U_LOW'));
    }

    public function testGetRankGivesTiedUsersTheSameRank(): void
    {
        $storage = $this->createStorage();

        $storage->recordEvent('U_FROM', 'U_A', 10, 'C1');
        $storage->recordEvent('U_FROM', 'U_B', 10, 'C1');
        $storage->recordEvent('U_FROM', 'U_C', 5, 'C1');

        $this->assertSame(1, $storage->getRank('U_A'));
        $this->assertSame(1, $storage->getRank('U_B'));
        $this->assertSame(3, $storage->getRank('U_C'));
    }
}
