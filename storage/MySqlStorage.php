<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Storage;

/**
 * StorageInterface implementation backed by MySQL, via PDO.
 *
 * As with every StorageInterface implementation, tier is never stored or
 * computed here — this class deals only in karma totals and events.
 */
final class MySqlStorage implements StorageInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function getKarma(string $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT karma FROM karma WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['userId' => $userId, 'karma' => (int) $row['karma']];
    }

    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $karma,
        string $channel,
        ?\DateTimeImmutable $occurredAt = null
    ): array {
        $timestamp = ($occurredAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $insertEvent = $this->pdo->prepare(
                'INSERT INTO events (from_user, to_user, karma, channel, timestamp)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertEvent->execute([$fromUser, $toUser, $karma, $channel, $timestamp]);

            $upsertKarma = $this->pdo->prepare(
                'INSERT INTO karma (user_id, karma) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE karma = karma + VALUES(karma)'
            );
            $upsertKarma->execute([$toUser, $karma]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getKarma($toUser) ?? ['userId' => $toUser, 'karma' => 0];
    }

    public function getEvents(string $userId, \DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, from_user, to_user, karma, channel, timestamp
             FROM events
             WHERE (from_user = ? OR to_user = ?) AND timestamp >= ?
             ORDER BY timestamp DESC'
        );
        $statement->execute([$userId, $userId, $since->format('Y-m-d H:i:s')]);

        $events = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => (int) $row['id'],
                'fromUser' => $row['from_user'],
                'toUser' => $row['to_user'],
                'karma' => (int) $row['karma'],
                'channel' => $row['channel'],
                'timestamp' => new \DateTimeImmutable($row['timestamp']),
            ];
        }

        return $events;
    }

    public function getTopKarma(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, karma FROM karma ORDER BY karma DESC LIMIT ?'
        );
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();

        $top = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $top[] = ['userId' => $row['user_id'], 'karma' => (int) $row['karma']];
        }

        return $top;
    }

    public function getRank(string $userId): ?int
    {
        $karma = $this->getKarma($userId);

        if ($karma === null) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM karma WHERE karma > ?');
        $statement->execute([$karma['karma']]);

        return ((int) $statement->fetchColumn()) + 1;
    }
}
