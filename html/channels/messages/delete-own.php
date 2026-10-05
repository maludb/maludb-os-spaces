<?php
declare(strict_types=1);
/** Action `message_delete_own` (log `message.delete_own`; confirm): own; a tombstone keeps the thread's shape. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
if ((int) $m['author_member_id'] !== $me) { refuse(403, 'That is not your message: a channel owner deletes others\' messages.'); }
sp_guard($pdo, static function () use ($pdo, $c, $m, $me): void {
    $pdo->beginTransaction();
    delete_message($pdo, (int) $m['message_id'], $me);
    message_log($pdo, 'message.delete_own', $c, (int) $m['message_id'], ['thread_root_id' => $m['thread_root_id']]);
    $pdo->commit();
});
message_reply($pdo, (int) $m['message_id'], $c, 'Deleted');
