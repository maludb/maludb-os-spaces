<?php
declare(strict_types=1);

function set_reminder(PDO $pdo, int $memberId, array $f): int
{
    $st = $pdo->prepare('INSERT INTO reminders (member_id, message_id, page_id, text, remind_at) VALUES (:m, :msg, CAST(:p AS uuid), :t, CAST(:at AS timestamptz)) RETURNING id');
    $st->execute(['m' => $memberId, 'msg' => $f['message_id'], 'p' => $f['page_id'], 't' => $f['text'], 'at' => $f['remind_at']]);
    return (int) $st->fetchColumn();
}

function reminder_done(PDO $pdo, int $id, int $memberId): void
{
    $st = $pdo->prepare('UPDATE reminders SET done_at = now() WHERE id = :id AND member_id = :m AND done_at IS NULL');
    $st->execute(['id' => $id, 'm' => $memberId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('That reminder is done already, or not yours.');
    }
}
