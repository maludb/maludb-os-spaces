<?php
declare(strict_types=1);
/** Action `space_kind_set` (log `space.kind_set`; confirm; agent approval `other`): an owner changes a space's kind. General stays open (the guard's words). */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$kind = (string) (req_val('kind') ?? '');
if (!isset(SPACE_KINDS[$kind])) {
    sp_refuse_fields(['kind' => 'The kind is open, closed or private.']);
}
sp_guard($pdo, static function () use ($pdo, $me, $s, $kind): void {
    $pdo->beginTransaction();
    set_space_kind($pdo, $s['space_id'], $kind, $me);
    space_log($pdo, 'space.kind_set', $s['space_id'], ['before' => ['kind' => $s['kind']], 'after' => ['kind' => $kind, 'everyone_level' => space_state($pdo, $s['space_id'])['everyone_level']]]);
    $pdo->commit();
});
sp_done($s['name'] . ' is now ' . $kind, $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id']), 'kind'), 'spaceChanged');
