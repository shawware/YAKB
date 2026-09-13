<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../yakb.php';

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
$signingSecret = (string) getenv('SLACK_SIGNING_SECRET');

$slackApi = new SlackApi(new GuzzleHttp\Client(), (string) getenv('SLACK_BOT_TOKEN'));

if (!$slackApi->verifySignature($signingSecret, $timestamp, $rawBody, $signature)) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Invalid signature';
    return;
}

$pdo = new PDO(
    (string) getenv('DB_DSN'),
    getenv('DB_USER') ?: null,
    getenv('DB_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$storage = new MySqlStorage($pdo);
$karma = new Karma(require $root . '/config/tiers.php');
$router = new Router(new Parser(), $karma, $storage, $slackApi);

if ($path === '/slack/events') {
    $payload = json_decode($rawBody, true);
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
