<?php
declare(strict_types=1);
/** Action `channel_notify_set` (log `channel.notify_set`): own — notify (all, mentions, none), muted_until (a time; empty unmutes), starred, section. */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo, false);
if (!$c['i_am_member']) { refuse(403, 'You are not in ' . $c['label'] . '.'); }
$f = [];
if (req_has('notify')) { $n = (string) req_val('notify'); if (!in_array($n, ['all', 'mentions', 'none'], true)) { sp_refuse_fields(['notify' => 'notify is all, mentions or none.']); } $f['notify'] = $n; }
if (req_has('muted_until')) { $f['muted_until'] = request_time('muted_until'); }
if (req_has('starred')) { $f['starred'] = sp_yes('starred'); }
if (req_has('section')) { $s = mb_substr(trim((string) req_val('section')), 0, 40); $f['section'] = $s === '' ? null : $s; }
if ($f === []) { sp_refuse_fields(['notify' => 'Say what changes: notify, muted_until, starred or section.']); }
sp_guard($pdo, static function () use ($pdo, $c, $me, $f): void {
    $pdo->beginTransaction();
    set_channel_notify($pdo, $c['channel_id'], $me, $f);
    channel_log($pdo, 'channel.notify_set', $c, ['after' => $f + ['member_id' => $me]]);
    $pdo->commit();
});
sp_done('Saved how ' . $c['label'] . ' tells you', $c['channel_id'], sp_land(return_path(channel_path($c)), 'notify'), 'channelChanged', $f);
