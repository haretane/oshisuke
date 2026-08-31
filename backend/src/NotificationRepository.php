<?php

declare(strict_types=1);

function createNotification(PDO $pdo, int $userId, string $type, ?int $cardId, string $title, string $body, string $dedupeKey): void
{
    $existingStatement = $pdo->prepare(
        "SELECT id FROM notifications
         WHERE user_id = :user_id AND dedupe_key = :dedupe_key AND read_at IS NULL
           AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $existingStatement->execute(['user_id' => $userId, 'dedupe_key' => $dedupeKey]);
    $existingId = $existingStatement->fetchColumn();
    if ($existingId !== false) {
        $updateStatement = $pdo->prepare(
            'UPDATE notifications SET title = :title, body = :body, created_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $updateStatement->execute(['title' => $title, 'body' => $body, 'id' => $existingId]);
        return;
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO notifications (user_id, notification_type, card_id, title, body, dedupe_key)
         VALUES (:user_id, :notification_type, :card_id, :title, :body, :dedupe_key)'
    );
    $insertStatement->execute([
        'user_id' => $userId,
        'notification_type' => $type,
        'card_id' => $cardId,
        'title' => $title,
        'body' => $body,
        'dedupe_key' => $dedupeKey,
    ]);
}

function notifyOshiFollowers(PDO $pdo, int $oshiId, int $excludeUserId, int $cardId, string $title): void
{
    $statement = $pdo->prepare(
        'SELECT user_id FROM user_oshis WHERE oshi_id = :oshi_id AND is_active = 1 AND user_id <> :exclude_user_id'
    );
    $statement->execute(['oshi_id' => $oshiId, 'exclude_user_id' => $excludeUserId]);
    foreach ($statement->fetchAll() as $row) {
        createNotification($pdo, (int) $row['user_id'], 'new_card', $cardId, '新しいカードが追加されました', $title, "new_card:$cardId");
    }
}

function notifyCardScheduleUsers(PDO $pdo, int $cardId, int $excludeUserId, string $title): void
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT us.user_id
         FROM user_schedules us
         INNER JOIN occurrences oc ON oc.id = us.occurrence_id
         WHERE oc.card_id = :card_id AND us.user_id <> :exclude_user_id'
    );
    $statement->execute(['card_id' => $cardId, 'exclude_user_id' => $excludeUserId]);
    foreach ($statement->fetchAll() as $row) {
        createNotification($pdo, (int) $row['user_id'], 'card_updated', $cardId, '登録中のカードが更新されました', $title, "card_updated:$cardId");
    }
}
