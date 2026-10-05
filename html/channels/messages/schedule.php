<?php
declare(strict_types=1);
/** Action `message_schedule` (log `message.schedule`): own, not yet sent; a new time, or empty sends now. */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$m, $c] = message_from_request($pdo);
if ((int) $m['author_member_id'] !== $me) { refuse(403, 'Only the author reschedules a message.'); }
if ($m['sent_at'] !== null) { refuse(422, 'A sent message is not rescheduled.'); }
$when = request_time('schedule_for');
sp_guard($pdo, static function () use ($pdo, $c, $m, $when): void {
    $pdo->beginTransaction();
    schedule_message($pdo, (int) $m['message_id'], $when);
    message_log($pdo, 'message.schedule', $c, (int) $m['message_id'], ['scheduled_for' => $when]);
    $pdo->commit();
});
sp_done($when === null ? 'Sent now' : 'Scheduled for ' . format_ts($when, member_timezone(), 'M j, g:i A'), (int) $m['message_id'], sp_land(return_path('/channels/scheduled'), $when === null ? 'sent' : 'scheduled'), 'messageChanged', ['scheduled_for' => $when]);
