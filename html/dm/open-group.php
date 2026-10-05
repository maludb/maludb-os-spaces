<?php
declare(strict_types=1);
/** Action `group_dm_open` (log `channel.create`: kind group_dm, member_ids): dm.write; two to eight others; an existing group with exactly these people is found again. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!has_right('dm.write') && !is_guest()) { require_right('dm.write'); }
$raw = $_POST['members'] ?? ($_GET['members'] ?? []);
if (is_string($raw)) { $raw = array_filter(array_map('trim', explode(',', $raw))); }
$members = array_values(array_unique(array_filter(array_map('intval', (array) $raw), static fn (int $v) => $v > 0 && $v !== $me)));
if (count($members) < 2) { sp_refuse_fields(['members' => 'A group message needs at least two others.']); }
if (count($members) > 8) { sp_refuse_fields(['members' => 'A group message holds at most eight others.']); }
foreach ($members as $m) {
    if (!db_bool($pdo, 'SELECT CAST(:m AS bigint) IN (SELECT sp_visible_member_ids())', ['m' => $m])) { sp_refuse_fields(['members' => 'Someone there is not a member you can see.']); }
}
$before = (int) one_value($pdo, 'SELECT count(*) FROM channels');
$id = sp_guard($pdo, static function () use ($pdo, $members, $me, $before): int {
    $pdo->beginTransaction();
    $id = open_group_dm($pdo, $members);
    if ((int) one_value($pdo, 'SELECT count(*) FROM channels') > $before) { log_activity($pdo, 'channel.create', 'channel', $id, ['channel_id' => $id, 'after' => ['kind' => 'group_dm', 'member_ids' => array_merge([$me], $members)]]); }
    $pdo->commit();
    return $id;
});
sp_done('Your group message', $id, '/dm/' . $id, 'channelChanged', ['channel_id' => $id]);
