<?php
declare(strict_types=1);
/** Action `reminder_set` (log `reminder.set`): own; remind_at (a time), what: a message, a page or text. */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reminders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reminders/write.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$at = request_time('remind_at') ?? sp_refuse_fields(['remind_at' => 'Give a time to be reminded at.']);
$mid = request_integer('message');
$pid = is_uuid($_POST['page'] ?? ($_GET['page'] ?? null)) ? (string) ($_POST['page'] ?? $_GET['page']) : null;
$text = mb_substr(trim((string) (req_val('text') ?? '')), 0, 500);
if ($mid === null && $pid === null && $text === '') { sp_refuse_fields(['text' => 'Say what to be reminded of: a message, a page or words.']); }
if ($mid !== null && message_row($pdo, $mid) === null) { refuse(404, 'Message not found.'); }
if ($pid !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:p AS uuid))', ['p' => $pid])) { refuse(404, 'Page not found.'); }
$id = sp_guard($pdo, static function () use ($pdo, $me, $at, $mid, $pid, $text): int {
    $pdo->beginTransaction();
    $id = set_reminder($pdo, $me, ['remind_at' => $at, 'message_id' => $mid, 'page_id' => $pid, 'text' => $text === '' ? null : $text]);
    log_activity($pdo, 'reminder.set', 'reminder', $id, ['message_id' => $mid, 'after' => ['reminder_id' => $id, 'remind_at' => $at, 'message_id' => $mid, 'page_id' => $pid, 'has_text' => $text !== '']]);
    $pdo->commit();
    return $id;
});
sp_done('Reminder set for ' . format_ts($at, member_timezone(), 'M j, g:i A'), $id, sp_land(return_path('/reminders/'), 'set'), 'reminderChanged', ['reminder_id' => $id, 'remind_at' => $at]);
