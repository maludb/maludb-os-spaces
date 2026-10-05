<?php
declare(strict_types=1);
/** Action `retention_set` (log `channel.retention_set`; confirm; agent approval `other`): retention.manage or channel.manage; days 1–3650, empty keeps forever. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$c = channel_from_request($pdo);
if (!has_right('retention.manage') && !has_right('channel.manage') && !is_sp_admin()) { refuse(403, 'You may not set retention.'); }
$days = null;
if (req_has('days') && trim((string) req_val('days')) !== '') {
    $days = request_integer('days');
    if ($days === null || $days < 1 || $days > 3650) { sp_refuse_fields(['days' => 'Days is 1 to 3650, or empty to keep forever.']); }
}
sp_guard($pdo, static function () use ($pdo, $c, $days): void {
    $pdo->beginTransaction();
    set_retention($pdo, $c['channel_id'], $days);
    channel_log($pdo, 'channel.retention_set', $c, ['before' => ['retention_days' => $c['retention_days']], 'after' => ['retention_days' => $days]]);
    $pdo->commit();
});
sp_done($days === null ? $c['label'] . ' keeps everything' : $c['label'] . ' keeps ' . $days . ' days', $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/edit'), 'retention'), 'channelChanged', ['retention_days' => $days]);
