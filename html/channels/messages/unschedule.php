<?php
declare(strict_types=1);
/** Action `message_unschedule` (log `message.unschedule`; confirm): own; a scheduled message discarded. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
if ((int) $m['author_member_id'] !== $me) { refuse(403, 'Only the author cancels a scheduled message.'); }
sp_guard($pdo, static function () use ($pdo, $c, $m): void {
    $pdo->beginTransaction();
    message_log($pdo, 'message.unschedule', $c, (int) $m['message_id'], ['scheduled_for' => $m['scheduled_for']]);
    unschedule_message($pdo, (int) $m['message_id']);
    $pdo->commit();
});
sp_done('Cancelled', (int) $m['message_id'], sp_land(return_path('/channels/scheduled'), 'cancelled'), 'messageChanged');
