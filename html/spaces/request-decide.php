<?php
declare(strict_types=1);
/** Action `space_join_decide` (log `space.join_decide`; confirm): an owner approves or declines a pending request; the requester is told. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$rid = request_integer('request') ?? request_integer('join_request') ?? refuse(422, 'Say which request.');
$row = null;
$st = $pdo->prepare('SELECT space_id, status FROM mcp_space_join_requests WHERE join_request_id = :id');
$st->execute(['id' => $rid]);
$row = $st->fetch() ?: refuse(404, 'Request not found.');
$s = find_space($pdo, (int) $row['space_id']) ?? refuse(404, 'Space not found.');
require_space_owner($s);
$decision = strtolower((string) (req_val('decision') ?? ''));
if (!in_array($decision, ['approve', 'decline'], true)) {
    sp_refuse_fields(['decision' => 'The decision is approve or decline.']);
}
$r = sp_guard($pdo, static function () use ($pdo, $me, $s, $rid, $decision): array {
    $pdo->beginTransaction();
    $r = decide_request($pdo, $rid, $decision === 'approve', $me);
    space_log($pdo, 'space.join_decide', $s['space_id'], ['after' => ['request_id' => $rid, 'member_id' => $r['member_id'], 'decision' => $r['decision']]], 'space_join_request', $rid);
    if ($r['decision'] === 'approved') {
        space_log($pdo, 'space.member_add', $s['space_id'], ['after' => ['member_id' => $r['member_id'], 'role' => 'member', 'via' => 'request']]);
    }
    $pdo->commit();
    return $r;
});
sp_done(ucfirst($r['decision']) . ' the request', $rid, sp_land(return_path('/spaces/' . $s['space_id'] . '/requests'), 'decided'), 'spaceChanged', ['space_id' => $s['space_id'], 'member_id' => $r['member_id']]);
