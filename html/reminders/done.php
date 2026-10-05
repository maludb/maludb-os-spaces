<?php
declare(strict_types=1);
/** Action `reminder_done` (log `reminder.done`): own. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reminders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reminders/write.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('reminder') ?? refuse(422, 'Say which reminder.');
if (!db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_reminders WHERE reminder_id = :r)', ['r' => $id])) { refuse(404, 'Reminder not found.'); }
sp_guard($pdo, static function () use ($pdo, $id, $me): void {
    $pdo->beginTransaction();
    reminder_done($pdo, $id, $me);
    log_activity($pdo, 'reminder.done', 'reminder', $id, ['after' => ['reminder_id' => $id]]);
    $pdo->commit();
});
sp_done('Done', $id, sp_land(return_path('/reminders/'), 'done'), 'reminderChanged');
