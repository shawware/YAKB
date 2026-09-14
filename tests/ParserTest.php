<?php

// Copyright © 2026 shawware.com.au

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

        $this->assertSame([['userId' => 'U123ABC', 'points' => 2, 'capped' => false]], $mentions);
    }

    public function testMentionWithNoSpaceBeforePlusses(): void
    {
        $mentions = $this->parser->parse('<@U123ABC>+++');

        $this->assertSame([['userId' => 'U123ABC', 'points' => 3, 'capped' => false]], $mentions);
    }

    public function testMultipleMentionsInOneMessage(): void
    {
        $mentions = $this->parser->parse('thanks <@U111> ++ and <@U222> +++++');

        $this->assertSame(
            [
                ['userId' => 'U111', 'points' => 2, 'capped' => false],
                ['userId' => 'U222', 'points' => 5, 'capped' => false],
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

        $this->assertSame([['userId' => 'U999XYZ', 'points' => 2, 'capped' => false]], $mentions);
    }

    public function testMentionWithDisplayNameSuffix(): void
    {
        // Slack renders a mention composed via the rich-text editor as
        // <@USERID|displayname> — the "|displayname" part must be ignored.
        $mentions = $this->parser->parse('<@U123ABC|david.shaw> ++');

        $this->assertSame([['userId' => 'U123ABC', 'points' => 2, 'capped' => false]], $mentions);
    }

    public function testPointsBeyondTheDefaultMaxAreCapped(): void
    {
        $mentions = $this->parser->parse('<@U123ABC> +++++++++'); // 9 pluses, default max 5

        $this->assertSame([['userId' => 'U123ABC', 'points' => 5, 'capped' => true]], $mentions);
    }

    public function testPointsExactlyAtTheMaxAreNotFlaggedAsCapped(): void
    {
        $mentions = $this->parser->parse('<@U123ABC> +++++'); // exactly the default max, 5

        $this->assertSame([['userId' => 'U123ABC', 'points' => 5, 'capped' => false]], $mentions);
    }

    public function testCustomMaxPointsPerMessage(): void
    {
        $parser = new Parser(maxPointsPerMessage: 2);

        $mentions = $parser->parse('<@U123ABC> ++++');

        $this->assertSame([['userId' => 'U123ABC', 'points' => 2, 'capped' => true]], $mentions);
    }
}
