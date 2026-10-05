<?php
declare(strict_types=1);
/** /dm/ — my DMs and group DMs as cards: the people (agents chipped), the last line, unread, when (screen `dm-list`). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_login();
require_human();
if (!has_right('dm.write') && !has_right('spaces.guest')) { require_right('dm.write'); }
$pdo = db();
$rows = my_dms($pdo);
$may = ['new' => has_right('dm.write') || is_guest()];
log_screen_view($pdo, 'dm-list');
if (wants_json()) {
    respond_screen(['dms' => array_map('present_dm', $rows), 'may' => $may]);
}
render_screen('Direct messages', view('dm/index.php', ['rows' => $rows, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['left' => ['success', 'You left the conversation.']])]),
    ['activeNav' => 'dms', 'screen' => 'dm-list', 'entity' => 'channel']);
