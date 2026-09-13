<?php

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Yakb\Parser;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testSingleValidMention(): void
    {
        $mentions = $this->parser->parse('<@U123ABC> ++');

        $this->assertSame([['userId' => 'U123ABC', 'points' => 2]], $mentions);
    }

    public function testMentionWithNoSpaceBeforePlusses(): void
    {
        $mentions = $this->parser->parse('<@U123ABC>+++');

        $this->assertSame([['userId' => 'U123ABC', 'points' => 3]], $mentions);
    }

    public function testMultipleMentionsInOneMessage(): void
    {
        $mentions = $this->parser->parse('thanks <@U111> ++ and <@U222> +++++');

        $this->assertSame(
            [
                ['userId' => 'U111', 'points' => 2],
                ['userId' => 'U222', 'points' => 5],
            ],
            $mentions
        );
    }

    public function testMentionWithNoPlusIsNotAKarmaEvent(): void
    {
        $mentions = $this->parser->parse('great work <@U123ABC>, thanks');

        $this->assertSame([], $mentions);
    }

    public function testPlainTextWithNoMention(): void
    {
        $mentions = $this->parser->parse('just a regular message, no mentions here');

        $this->assertSame([], $mentions);
    }

    public function testMixedTextAroundThePattern(): void
    {
        $mentions = $this->parser->parse('great job on the release <@U999XYZ> ++ really appreciate it!');

        $this->assertSame([['userId' => 'U999XYZ', 'points' => 2]], $mentions);
    }
}
