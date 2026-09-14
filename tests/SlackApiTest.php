<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shawware\Yakb\SlackApi;

final class SlackApiTest extends TestCase
{
    private const SIGNING_SECRET = 'test-signing-secret';

    // --- verifySignature -----------------------------------------------

    public function testVerifySignatureAcceptsAValidSignature(): void
    {
        $slackApi = $this->makeSlackApi();
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SIGNING_SECRET);

        $this->assertTrue(
            $slackApi->verifySignature(self::SIGNING_SECRET, $timestamp, $body, $signature)
        );
    }

    public function testVerifySignatureRejectsATamperedBody(): void
    {
        $slackApi = $this->makeSlackApi();
        $timestamp = (string) time();
        $signature = 'v0=' . hash_hmac(
            'sha256',
            "v0:{$timestamp}:{\"type\":\"event_callback\"}",
            self::SIGNING_SECRET
        );

        // Signature was computed for a different body — must not verify.
        $this->assertFalse(
            $slackApi->verifySignature(self::SIGNING_SECRET, $timestamp, '{"type":"tampered"}', $signature)
        );
    }

    public function testVerifySignatureRejectsAStaleTimestamp(): void
    {
        $slackApi = $this->makeSlackApi();
        $staleTimestamp = (string) (time() - 400); // older than the 300s window
        $body = '{"type":"event_callback"}';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$staleTimestamp}:{$body}", self::SIGNING_SECRET);

        $this->assertFalse(
            $slackApi->verifySignature(self::SIGNING_SECRET, $staleTimestamp, $body, $signature)
        );
    }

    // --- postMessage / addReaction ---------------------------------------

    public function testPostMessageCallsChatPostMessageWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->postMessage('C123', 'nice work!');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://slack.com/api/chat.postMessage', (string) $request->getUri());
        $this->assertSame('Bearer test-bot-token', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['channel' => 'C123', 'text' => 'nice work!'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testAddReactionCallsReactionsAddWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->addReaction('C123', '1699999999.000100', 'tada');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://slack.com/api/reactions.add', (string) $request->getUri());
        $this->assertSame(
            ['channel' => 'C123', 'timestamp' => '1699999999.000100', 'name' => 'tada'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testPostMessageThrowsWhenSlackReturnsOkFalse(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => false, 'error' => 'channel_not_found'])),
            $history
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('channel_not_found');

        $slackApi->postMessage('C_BAD', 'hello');
    }

    // --- helpers ---------------------------------------------------------

    private function makeSlackApi(): SlackApi
    {
        return new SlackApi(new Client(), 'test-bot-token');
    }

    /**
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $history
     *        Passed by reference — Guzzle's history middleware appends to
     *        it as requests are made, so the caller must pass a real
     *        variable (not an inline expression) to observe the requests.
     */
    private function makeSlackApiWithMockedHttp(Response $response, array &$history): SlackApi
    {
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client(['handler' => $stack]);

        return new SlackApi($client, 'test-bot-token');
    }
}
