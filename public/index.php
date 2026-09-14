<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../yakb.php';
require __DIR__ . '/../env.php';

use Shawware\Yakb\Karma;
use Shawware\Yakb\Parser;
use Shawware\Yakb\Router;
use Shawware\Yakb\SlackApi;
use Shawware\Yakb\Storage\MySqlStorage;

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// There's nothing here for a crawler to index — tell well-behaved bots
// not to bother, before they even reach the generic placeholder below.
if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /\n";
    return;
}

// Only these two paths do anything Slack-signed or touch the database.
// Everything else — including the bare domain and any guessed/scanned
// path — gets the static placeholder below and never connects to
// storage at all, per CLAUDE.md's "Unmatched and Root Requests" section:
// no redirect, no diagnostic information, and no unnecessary DB traffic
// from bots probing random paths.
if ($path !== '/slack/events' && $path !== '/slack/commands') {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/resources/placeholder.html');
    return;
}

$rawBody = (string) file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';
$signingSecret = (string) envValue('SLACK_SIGNING_SECRET');

$slackApi = new SlackApi(new GuzzleHttp\Client(), (string) envValue('SLACK_BOT_TOKEN'));

if (!$slackApi->verifySignature($signingSecret, $timestamp, $rawBody, $signature)) {
    error_log("[YAKB] signature verification FAILED for {$path} (timestamp={$timestamp})");
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Invalid signature';
    return;
}

error_log("[YAKB] signature verified for {$path}, body={$rawBody}");

$pdo = new PDO(
    (string) envValue('DB_DSN'),
    envValue('DB_USER'),
    envValue('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$storage = new MySqlStorage($pdo);
$karma = new Karma(require $root . '/config/tiers.php');
$maxPointsPerMessage = (require $root . '/config/karma.php')['maxPointsPerMessage'];
$router = new Router(new Parser($maxPointsPerMessage), $karma, $storage, $slackApi, $maxPointsPerMessage);

if ($path === '/slack/events') {
    $payload = json_decode($rawBody, true);

    error_log(sprintf(
        '[YAKB] event payload type=%s event_type=%s text=%s',
        $payload['type'] ?? 'n/a',
        $payload['event']['type'] ?? 'n/a',
        $payload['event']['text'] ?? 'n/a'
    ));

    $challenge = $router->handleEvent(is_array($payload) ? $payload : []);

    if ($challenge !== null) {
        header('Content-Type: text/plain');
        echo $challenge;
    }

    return;
}

parse_str($rawBody, $payload);
header('Content-Type: application/json');
echo json_encode($router->handleSlashCommand($payload));
