<?php

declare(strict_types=1);

namespace Shawware\Yakb\Storage;

/**
 * StorageInterface implementation backed by MySQL, via PDO.
 *
 * As with every StorageInterface implementation, tier is never stored or
 * computed here — this class deals only in scores and events.
 */
final class MySqlStorage implements StorageInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function getScore(string $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT score FROM scores WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['userId' => $userId, 'score' => (int) $row['score']];
    }

    public function recordEvent(
        string $fromUser,
        string $toUser,
        int $points,
        string $channel,
        ?\DateTimeImmutable $occurredAt = null
    ): array {
        $timestamp = ($occurredAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $insertEvent = $this->pdo->prepare(
                'INSERT INTO events (from_user, to_user, points, channel, timestamp)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertEvent->execute([$fromUser, $toUser, $points, $channel, $timestamp]);

            $upsertScore = $this->pdo->prepare(
                'INSERT INTO scores (user_id, score) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE score = score + VALUES(score)'
            );
            $upsertScore->execute([$toUser, $points]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getScore($toUser) ?? ['userId' => $toUser, 'score' => 0];
    }

    public function getEvents(string $userId, \DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, from_user, to_user, points, channel, timestamp
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
                'points' => (int) $row['points'],
                'channel' => $row['channel'],
                'timestamp' => new \DateTimeImmutable($row['timestamp']),
            ];
        }

        return $events;
    }

    public function getTopScores(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, score FROM scores ORDER BY score DESC LIMIT ?'
        );
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();

        $top = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $top[] = ['userId' => $row['user_id'], 'score' => (int) $row['score']];
        }

        return $top;
    }
}
