<?php
declare(strict_types=1);
/**
 * Action `export_channel` (log `channel.export`: export_id, channel_id, format, from, to; confirm; agent approval `external_send`): a channel's messages as JSON (threads nested, authors by name, reactions and attachment
 * names), Markdown (a heading a day) or CSV, for a period `from`..`to` (dates, inclusive; both optional). A channel of a space, by its owner with export.space (or the admin); a direct message is never exported.
 * Queued for the worker. Params: channel, format, from, to.
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo, false);
if (in_array($c['kind'], ['dm', 'group_dm'], true) || $c['space_id'] === null) { refuse(422, 'A direct message is never exported.'); }
if (!is_sp_admin() && empty($c['i_own_space'])) { refuse(403, 'Only an owner of the space exports ' . $c['label'] . '.'); }
if (!has_right('export.space') && !is_sp_admin()) { refuse(403, 'You may not export a channel.'); }
$format = (string) (req_val('format') ?? 'json');
$from = (string) (req_val('from') ?? '');
$to = (string) (req_val('to') ?? '');
$id = (int) sp_guard($pdo, static function () use ($pdo, $c, $format, $from, $to, $me): int {
    $pdo->beginTransaction();
    $id = start_export($pdo, 'channel', $format === '' ? 'json' : $format, ['channel' => $c['channel_id'], 'from' => $from, 'to' => $to], $me);
    $pdo->commit();
    return $id;
});
channel_log($pdo, 'channel.export', $c, ['after' => ['export_id' => $id, 'channel_id' => $c['channel_id'], 'format' => $format === '' ? 'json' : $format, 'from' => $from === '' ? null : $from, 'to' => $to === '' ? null : $to]], 'export', $id);
sp_done('Queued the export of ' . $c['label'], $id, sp_land('/exports/', 'queued'), '', ['status' => 'queued', 'export_id' => $id]);
