<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb;

/**
 * Verifying incoming Slack requests and making the outbound Slack Web
 * API calls the bot needs. See SlackApi for the real implementation.
 */
interface SlackApiInterface
{
    public function verifySignature(
        string $signingSecret,
        string $timestamp,
        string $rawBody,
        string $signatureHeader
    ): bool;

    public function postMessage(string $channel, string $text): void;

    public function addReaction(string $channel, string $timestamp, string $emoji): void;
}
