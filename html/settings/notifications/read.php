<?php
declare(strict_types=1);
/** Action `notification_read` (log `notification.read`; `count`): mark one of my notices read (`notification`), or every unread one (empty). Own rows only — another member's notice is a 404. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/notify/queries.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification');
if ($id !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE notification_id = :id', ['id' => $id]) === 0) {
    refuse(404, 'Notification not found.');
}
$n = sp_guard($pdo, static function () use ($pdo, $me, $id): int {
    $pdo->beginTransaction();
    $n = mark_notifications_read($pdo, $me, $id);
    log_activity($pdo, 'notification.read', $id === null ? null : 'notification', $id, ['after' => ['count' => $n, 'all' => $id === null]]);
    $pdo->commit();
    return $n;
});
sp_done($id === null ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read' : ($n === 1 ? 'Marked the notification read' : 'That notification was already read'), $id,
    return_path('/notifications'), 'notificationChanged', ['count' => $n]);
