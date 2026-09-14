<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shawware\Yakb\Karma;

final class KarmaTest extends TestCase
{
    private function defaultTiers(): array
    {
        return require __DIR__ . '/../config/tiers.php';
    }

    #[DataProvider('defaultTierBoundaries')]
    public function testDefaultTierBoundaries(int $karmaTotal, ?string $expectedTier): void
    {
        $karma = new Karma($this->defaultTiers());

        $this->assertSame($expectedTier, $karma->tierForKarma($karmaTotal));
    }

    public static function defaultTierBoundaries(): array
    {
        return [
            'below every tier'   => [0, null],
            'bottom of bronze'   => [1, 'Bronze'],
            'top of bronze'      => [49, 'Bronze'],
            'bottom of silver'   => [50, 'Silver'],
            'top of silver'      => [199, 'Silver'],
            'bottom of gold'     => [200, 'Gold'],
            'top of gold'        => [499, 'Gold'],
            'bottom of platinum' => [500, 'Platinum'],
            'well above every tier' => [1_000_000, 'Platinum'],
        ];
    }

    public function testCustomTierConfiguration(): void
    {
        $karma = new Karma([
            ['name' => 'Rookie', 'min' => 10],
            ['name' => 'Veteran', 'min' => 100],
        ]);

        $this->assertNull($karma->tierForKarma(9));
        $this->assertSame('Rookie', $karma->tierForKarma(10));
        $this->assertSame('Rookie', $karma->tierForKarma(99));
        $this->assertSame('Veteran', $karma->tierForKarma(100));
    }

    public function testMoreThanFourTiers(): void
    {
        $karma = new Karma([
            ['name' => 'Tin',      'min' => 1],
            ['name' => 'Bronze',   'min' => 10],
            ['name' => 'Silver',   'min' => 25],
            ['name' => 'Gold',     'min' => 50],
            ['name' => 'Platinum', 'min' => 100],
            ['name' => 'Diamond',  'min' => 250],
        ]);

        $this->assertNull($karma->tierForKarma(0));
        $this->assertSame('Tin', $karma->tierForKarma(1));
        $this->assertSame('Bronze', $karma->tierForKarma(10));
        $this->assertSame('Silver', $karma->tierForKarma(25));
        $this->assertSame('Gold', $karma->tierForKarma(50));
        $this->assertSame('Platinum', $karma->tierForKarma(100));
        $this->assertSame('Diamond', $karma->tierForKarma(250));
        $this->assertSame('Diamond', $karma->tierForKarma(1_000_000));
    }
}
