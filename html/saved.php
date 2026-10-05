<?php
declare(strict_types=1);
/** /saved — the messages I saved (Later), newest first, each linking to its channel and thread; my reminders summary (screen `saved`). */
require_once dirname(__DIR__) . '/app/features/messages/handler.php';
require_once dirname(__DIR__) . '/app/features/reminders/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$rows = my_saved($pdo, $me);
$reminders = my_reminders($pdo, $me, false);
log_screen_view($pdo, 'saved');
if (wants_json()) {
    respond_screen(['saved' => array_map(static fn (array $m): array => present_message($m) + ['channel_name' => $m['channel_name'], 'channel_kind' => $m['channel_kind'], 'saved_at' => json_ts($m['saved_at'])], $rows), 'reminders' => array_map('present_reminder', $reminders)]);
}
render_screen('Saved', view('saved.php', ['rows' => $rows, 'reminders' => $reminders, 'here' => here_url(), 'tz' => member_timezone(), 'me' => $me, 'notice' => sp_notice($_GET['notice'] ?? null, ['unsaved' => ['success', 'Removed from Saved.']])]),
    ['activeNav' => 'saved', 'screen' => 'saved', 'entity' => 'message']);
