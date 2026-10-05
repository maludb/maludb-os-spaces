<?php
declare(strict_types=1);
/** /saved — the messages I saved (Later), newest saved first, rendered with where each is and Unsave; and my reminders due and coming, with Done (screen `saved`; param tab = saved|reminders — the two are tabs on a phone, side by side from 768 px). */
require_once dirname(__DIR__) . '/app/features/messages/handler.php';
require_once dirname(__DIR__) . '/app/features/notify/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$tab = request_string('tab') === 'reminders' ? 'reminders' : 'saved';
$rows = find_saved($pdo, $me);
$reminders = find_my_reminders($pdo, $me, false);
log_screen_view($pdo, 'saved');
if (wants_json()) {
    respond_screen(['saved' => array_map(static fn (array $m): array => present_message($m) + ['channel_name' => $m['channel_name'], 'channel_kind' => $m['channel_kind'], 'saved_at' => json_ts($m['saved_at'])], $rows), 'reminders' => array_map('present_reminder', $reminders)]);
}
render_screen('Saved', view('saved/page.php', ['rows' => $rows, 'reminders' => $reminders, 'tab' => $tab, 'here' => here_url(), 'tz' => member_timezone(), 'me' => $me,
    'notice' => sp_notice($_GET['notice'] ?? null, ['unsaved' => ['success', 'Removed from Saved.'], 'done' => ['success', 'Done.']])]),
    ['activeNav' => 'saved', 'screen' => 'saved', 'entity' => 'message']);
