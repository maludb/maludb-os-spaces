<?php
declare(strict_types=1);
/** /reminders/ — my reminders: due, coming, done; mark done; set one (screen `reminder-list`). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reminders/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$rows = my_reminders($pdo, $me, true);
log_screen_view($pdo, 'reminder-list');
if (wants_json()) {
    respond_screen(['reminders' => array_map('present_reminder', $rows)]);
}
render_screen('Reminders', view('reminders/index.php', ['rows' => $rows, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['set' => ['success', 'Reminder set.'], 'done' => ['success', 'Done.']])]),
    ['activeNav' => 'saved', 'screen' => 'reminder-list', 'entity' => 'reminder']);
