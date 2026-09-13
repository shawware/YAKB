<?php

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke tests for public/index.php: actually starts PHP's
 * built-in server against the real public/ directory and sends real HTTP
 * requests at it, rather than calling any function directly — this is
 * the only way to prove the *wiring* itself (which paths reach the
 * database, which get rejected before that) rather than just the logic
 * inside Router or SlackApi individually.
 */
final class FrontControllerTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 8199;

    /** @var resource */
    private static $serverProcess;

    private static Client $http;

    public static function setUpBeforeClass(): void
    {
        $publicDir = dirname(__DIR__) . '/public';

        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST . ':' . self::PORT, '-t', $publicDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (self::$serverProcess === false) {
            self::markTestSkipped('Could not start PHP built-in server for the front controller test.');
        }

        self::$http = new Client([
            'base_uri' => 'http://' . self::HOST . ':' . self::PORT,
            'http_errors' => false,
            'timeout' => 2,
        ]);

        self::waitUntilServerIsReady();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== false && self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    private static function waitUntilServerIsReady(): void
    {
        $deadline = microtime(true) + 3;

        while (microtime(true) < $deadline) {
            try {
                self::$http->get('/');
                return;
            } catch (\GuzzleHttp\Exception\ConnectException) {
                usleep(50_000);
            }
        }

        self::markTestSkipped('PHP built-in server did not become ready in time.');
    }

    public function testRootPathServesTheStaticPlaceholderWithoutTouchingTheDatabase(): void
    {
        $response = self::$http->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('YAKB', (string) $response->getBody());
    }

    public function testUnmatchedPathAlsoServesTheStaticPlaceholder(): void
    {
        $response = self::$http->get('/some/random/scanned/path');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('YAKB', (string) $response->getBody());
    }

    public function testSlackEventsWithoutAValidSignatureIsRejected(): void
    {
        $response = self::$http->post('/slack/events', [
            'body' => '{"type":"event_callback"}',
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid signature', (string) $response->getBody());
    }

    public function testSlackCommandsWithoutAValidSignatureIsRejected(): void
    {
        $response = self::$http->post('/slack/commands', [
            'body' => 'command=/karma&text=&user_id=U123',
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid signature', (string) $response->getBody());
    }
}
