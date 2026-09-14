<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Yakb\Karma;
use Shawware\Yakb\Parser;
use Shawware\Yakb\Router;
use Shawware\Yakb\SlackApiInterface;
use Shawware\Yakb\Storage\InMemoryStorage;

require_once __DIR__ . '/../yakb.php';

final class AppRoutingTest extends TestCase
{
    private InMemoryStorage $storage;
    private Karma $karma;
    private SlackApiInterface&\PHPUnit\Framework\MockObject\MockObject $slackApi;
    private Router $router;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->karma = new Karma([
            ['name' => 'Bronze', 'min' => 1],
            ['name' => 'Silver', 'min' => 10],
        ]);
        $this->slackApi = $this->createMock(SlackApiInterface::class);

        $this->router = new Router(new Parser(), $this->karma, $this->storage, $this->slackApi);
    }

    public function testUrlVerificationChallengeIsEchoedBack(): void
    {
        $result = $this->router->handleEvent([
            'type' => 'url_verification',
            'challenge' => 'abc123',
        ]);

        $this->assertSame('abc123', $result);
    }

    public function testKarmaMentionRecordsScoreReactsAndReplies(): void
    {
        // Slack user IDs are alphanumeric only (per Parser's regex) — no
        // underscores, unlike the U_FROM/U_TO style used for storage-level
        // test data elsewhere in this file, which never passes through Parser.
        $this->slackApi->expects($this->once())
            ->method('addReaction')
            ->with('C1', '1699999999.0001', 'tada');

        $this->slackApi->expects($this->once())
            ->method('postMessage')
            ->with('C1', $this->stringContains('<@UTOUSER> now has 2 points'));

        $result = $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C1',
                'user' => 'UFROMUSER',
                'ts' => '1699999999.0001',
                'text' => '<@UTOUSER> ++',
            ],
        ]);

        $this->assertNull($result);
        $this->assertSame(['userId' => 'UTOUSER', 'score' => 2], $this->storage->getScore('UTOUSER'));
    }

    public function testSelfMentionDoesNotAwardKarma(): void
    {
        $this->slackApi->expects($this->once())
            ->method('addReaction')
            ->with('C1', '1700000000.0001', 'no_good');

        $this->slackApi->expects($this->once())
            ->method('postMessage')
            ->with('C1', $this->stringContains("can't give yourself karma"));

        $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C1',
                'user' => 'USELFUSER',
                'ts' => '1700000000.0001',
                'text' => '<@USELFUSER> ++',
            ],
        ]);

        $this->assertNull($this->storage->getScore('USELFUSER'));
    }

    public function testSelfMentionInMixedMessageStillAwardsTheOtherUser(): void
    {
        $reactions = [];
        $messages = [];

        $this->slackApi->method('addReaction')
            ->willReturnCallback(function (string $channel, string $ts, string $emoji) use (&$reactions): void {
                $reactions[] = $emoji;
            });

        $this->slackApi->method('postMessage')
            ->willReturnCallback(function (string $channel, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C1',
                'user' => 'USELFUSER',
                'ts' => '1700000000.0002',
                'text' => 'thanks <@USELFUSER> ++ and <@UOTHERUSR> ++',
            ],
        ]);

        $this->assertNull($this->storage->getScore('USELFUSER'));
        $this->assertSame(['userId' => 'UOTHERUSR', 'score' => 2], $this->storage->getScore('UOTHERUSR'));

        $this->assertCount(2, $reactions);
        $this->assertContains('no_good', $reactions);
        $this->assertContains('tada', $reactions);

        $this->assertCount(2, $messages);
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, "can't give yourself karma")
        ));
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, '<@UOTHERUSR> now has 2 points')
        ));
    }

    public function testKarmaMentionExceedingCapIsCappedAndNotedInReply(): void
    {
        $this->slackApi->expects($this->once())
            ->method('postMessage')
            ->with('C1', $this->stringContains('(capped at 5 per message)'));

        $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C1',
                'user' => 'UFROMUSER',
                'ts' => '1700000000.0003',
                'text' => '<@UTOUSER> +++++++++', // 9 pluses, default cap 5
            ],
        ]);

        $this->assertSame(['userId' => 'UTOUSER', 'score' => 5], $this->storage->getScore('UTOUSER'));
    }

    public function testMessageWithNoMentionDoesNothing(): void
    {
        $this->slackApi->expects($this->never())->method('addReaction');
        $this->slackApi->expects($this->never())->method('postMessage');

        $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C1',
                'user' => 'U_FROM',
                'ts' => '1699999999.0001',
                'text' => 'just chatting, no mentions here',
            ],
        ]);

        $this->assertNull($this->storage->getScore('U_TO'));
    }

    public function testBotMessageIsIgnoredEvenIfItLooksLikeAKarmaMention(): void
    {
        $this->slackApi->expects($this->never())->method('addReaction');
        $this->slackApi->expects($this->never())->method('postMessage');

        $this->router->handleEvent([
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'subtype' => 'bot_message',
                'channel' => 'C1',
                'ts' => '1699999999.0001',
                'text' => '<@UTOUSER> ++',
            ],
        ]);

        $this->assertNull($this->storage->getScore('UTOUSER'));
    }

    public function testSlashCommandOwnScore(): void
    {
        $this->storage->recordEvent('U_OTHER', 'U_ME', 15, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'U_ME',
            'text' => '',
        ]);

        $this->assertSame('in_channel', $response['response_type']);
        $this->assertStringContainsString('15 points', $response['text']);
        $this->assertStringContainsString('tier Silver', $response['text']);
        $this->assertStringContainsString('rank #1', $response['text']);
    }

    public function testSlashCommandUserScore(): void
    {
        // extractMentionedUserId uses the same Slack-shaped ID regex as
        // Parser — alphanumeric only, hence UTOUSER rather than U_TO.
        $this->storage->recordEvent('U_ME', 'UTOUSER', 5, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'U_ME',
            'text' => '<@UTOUSER>',
        ]);

        $this->assertStringContainsString('<@UTOUSER> has 5 points', $response['text']);
    }

    public function testSlashCommandUserScoreWithDisplayNameSuffix(): void
    {
        // Slack renders a rich-text-composed mention as <@USERID|name> —
        // this must resolve the same as a plain <@USERID> mention.
        $this->storage->recordEvent('U_ME', 'UTOUSER', 5, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'U_ME',
            'text' => '<@UTOUSER|david.shaw>',
        ]);

        $this->assertStringContainsString('<@UTOUSER> has 5 points', $response['text']);
    }

    public function testSlashCommandTop(): void
    {
        $this->storage->recordEvent('U_FROM', 'U_LOW', 1, 'C1');
        $this->storage->recordEvent('U_FROM', 'U_HIGH', 20, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'U_ANY',
            'text' => 'top',
        ]);

        $this->assertStringContainsString('1. <@U_HIGH> — 20', $response['text']);
        $this->assertStringContainsString('2. <@U_LOW> — 1', $response['text']);
    }

    public function testSlashCommandHistoryForAnotherUser(): void
    {
        $this->storage->recordEvent('UAUSER', 'UBUSER', 3, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'UAUSER',
            'text' => 'history <@UBUSER>',
        ]);

        $this->assertStringContainsString('<@UAUSER> → <@UBUSER>: 3', $response['text']);
    }

    public function testSlashCommandMonthSumsPointsReceived(): void
    {
        $this->storage->recordEvent('U_A', 'U_ME', 4, 'C1');
        $this->storage->recordEvent('U_B', 'U_ME', 6, 'C1');
        $this->storage->recordEvent('U_ME', 'U_A', 100, 'C1'); // given, not received

        $response = $this->router->handleSlashCommand([
            'user_id' => 'U_ME',
            'text' => 'month',
        ]);

        $this->assertStringContainsString('<@U_ME> earned 10 points', $response['text']);
    }

    public function testSlashCommandHistoryWithUnresolvedUserDoesNotFallBackToRequester(): void
    {
        // "@use" is plain typed text, not a real mention — Slack only
        // encodes <@USERID> when the argument is selected from its
        // autocomplete. This must not silently show UAUSER's own history.
        $this->storage->recordEvent('UOTHER', 'UAUSER', 3, 'C1');

        $response = $this->router->handleSlashCommand([
            'user_id' => 'UAUSER',
            'text' => 'history @use',
        ]);

        $this->assertStringContainsString("don't recognize that user", $response['text']);
        $this->assertStringNotContainsString('UAUSER', $response['text']);
    }

    public function testSlashCommandMonthWithUnresolvedUserDoesNotFallBackToRequester(): void
    {
        $response = $this->router->handleSlashCommand([
            'user_id' => 'UAUSER',
            'text' => 'month @use',
        ]);

        $this->assertStringContainsString("don't recognize that user", $response['text']);
    }

    public function testSlashCommandUserScoreWithUnresolvedTextShowsUnknownUserMessage(): void
    {
        $response = $this->router->handleSlashCommand([
            'user_id' => 'UAUSER',
            'text' => '@use',
        ]);

        $this->assertStringContainsString("don't recognize that user", $response['text']);
    }
}
