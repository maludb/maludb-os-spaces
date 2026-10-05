<?php
declare(strict_types=1);
/** /channels/scheduled — my scheduled messages with their channel and time; change the time; cancel (screen `scheduled-list`). */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$rows = my_scheduled($pdo, $me);
log_screen_view($pdo, 'scheduled-list');
if (wants_json()) {
    respond_screen(['messages' => array_map(static fn (array $m): array => present_message($m) + ['channel_name' => $m['channel_name'], 'channel_kind' => $m['channel_kind']], $rows)]);
}
render_screen('Scheduled messages', view('channels/scheduled.php', ['rows' => $rows, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['scheduled' => ['success', 'Saved the time.'], 'sent' => ['success', 'Sent now.'], 'cancelled' => ['success', 'Cancelled: the message is discarded.']])]),
    ['activeNav' => 'channels', 'screen' => 'scheduled-list', 'entity' => 'message']);
