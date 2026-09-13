<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Shawware\Yakb\Storage\MigrationRunner;

$dotenvPath = __DIR__ . '/..';
if (is_file($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->load();
}

$dsn = getenv('DB_DSN');
$user = getenv('DB_USER') ?: null;
$pass = getenv('DB_PASS') ?: null;

if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "DB_DSN is not set (check your .env or environment).\n");
    exit(1);
}

$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$runner = new MigrationRunner($pdo, __DIR__ . '/../storage/migrations');
$applied = $runner->run();

if ($applied === []) {
    echo "Already up to date.\n";
} else {
    foreach ($applied as $filename) {
        echo "Applied {$filename}\n";
    }
}
