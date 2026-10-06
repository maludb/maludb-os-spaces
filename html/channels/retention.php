<?php
declare(strict_types=1);
/** Action `retention_set` (log `channel.retention_set`: before.days, after.days; confirm; agent approval `other`): retention.manage anywhere, or channel.manage as the owner of the channel's space; days 1–3650, empty keeps forever; a direct message has no retention (422). The worker's hourly step deletes the older messages. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/channels/retention.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo);
if (!has_right('retention.manage') && !is_sp_admin() && !(has_right('channel.manage') && !empty($c['i_own_space']))) { refuse(403, 'Only an owner of the space, or someone who manages retention, sets how long a channel keeps its messages.'); }
$days = null;
if (req_has('days') && trim((string) req_val('days')) !== '') {
    $days = request_integer('days');
    if ($days === null || $days < 1 || $days > 3650) { sp_refuse_fields(['days' => 'Days is 1 to 3650, or empty to keep forever.']); }
}
sp_guard($pdo, static function () use ($pdo, $c, $days): void {
    $pdo->beginTransaction();
    set_retention($pdo, $c['channel_id'], $days, (int) current_member_id());
    channel_log($pdo, 'channel.retention_set', $c, ['before' => ['retention_days' => $c['retention_days']], 'after' => ['retention_days' => $days]]);
    $pdo->commit();
});
sp_done($days === null ? $c['label'] . ' keeps everything' : $c['label'] . ' keeps ' . $days . ' days', $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/edit'), 'retention'), 'channelChanged', ['retention_days' => $days]);
