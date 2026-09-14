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
    public function testDefaultTierBoundaries(int $score, ?string $expectedTier): void
    {
        $karma = new Karma($this->defaultTiers());

        $this->assertSame($expectedTier, $karma->tierForScore($score));
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

        $this->assertNull($karma->tierForScore(9));
        $this->assertSame('Rookie', $karma->tierForScore(10));
        $this->assertSame('Rookie', $karma->tierForScore(99));
        $this->assertSame('Veteran', $karma->tierForScore(100));
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

        $this->assertNull($karma->tierForScore(0));
        $this->assertSame('Tin', $karma->tierForScore(1));
        $this->assertSame('Bronze', $karma->tierForScore(10));
        $this->assertSame('Silver', $karma->tierForScore(25));
        $this->assertSame('Gold', $karma->tierForScore(50));
        $this->assertSame('Platinum', $karma->tierForScore(100));
        $this->assertSame('Diamond', $karma->tierForScore(250));
        $this->assertSame('Diamond', $karma->tierForScore(1_000_000));
    }
}
