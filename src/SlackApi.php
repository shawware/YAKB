<?php

declare(strict_types=1);

namespace Shawware\Yakb;

use GuzzleHttp\ClientInterface;

/**
 * Verifies incoming Slack requests and makes the two outbound Slack Web
 * API calls the bot needs: posting a message and adding a reaction.
 */
final class SlackApi
{
    private const BASE_URL = 'https://slack.com/api/';

    /** Slack requires requests within this many seconds of "now" — replay protection. */
    private const MAX_REQUEST_AGE_SECONDS = 300;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $botToken
    ) {
    }

    /**
     * Verifies that a request actually came from Slack: the signature
     * matches, computed the same way Slack computes it, and the request
     * isn't a stale replay of an old one.
     */
    public function verifySignature(
        string $signingSecret,
        string $timestamp,
        string $rawBody,
        string $signatureHeader
    ): bool {
        if (abs(time() - (int) $timestamp) > self::MAX_REQUEST_AGE_SECONDS) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$rawBody}";
        $expected = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);

        return hash_equals($expected, $signatureHeader);
    }

    public function postMessage(string $channel, string $text): void
    {
        $this->call('chat.postMessage', [
            'channel' => $channel,
            'text' => $text,
        ]);
    }

    public function addReaction(string $channel, string $timestamp, string $emoji): void
    {
        $this->call('reactions.add', [
            'channel' => $channel,
            'timestamp' => $timestamp,
            'name' => $emoji,
        ]);
    }

    /**
     * @param array<string, string> $json
     */
    private function call(string $method, array $json): void
    {
        $response = $this->httpClient->request('POST', self::BASE_URL . $method, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->botToken,
            ],
            'json' => $json,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if (!is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) ? ($body['error'] ?? 'unknown_error') : 'invalid_response';
            throw new \RuntimeException("Slack API call to {$method} failed: {$error}");
        }
    }
}
