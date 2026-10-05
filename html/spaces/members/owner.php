<?php
declare(strict_types=1);
/** Action `space_owner_set` (log `space.owner_set`; confirm): an owner makes a member an owner (owner=yes) or steps one down (no); the last owner stays (the guard). */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$errors = [];
$member = sp_ref($pdo, 'member', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors, false);
if ($errors !== []) { sp_refuse_fields($errors); }
$owner = sp_yes('owner', true);
sp_guard($pdo, static function () use ($pdo, $me, $s, $member, $owner): void {
    $pdo->beginTransaction();
    set_space_owner($pdo, $s['space_id'], $member, $owner, $me);
    space_log($pdo, 'space.owner_set', $s['space_id'], ['after' => ['member_id' => $member, 'owner' => $owner]]);
    $pdo->commit();
});
sp_done($owner ? 'Now an owner of ' . $s['name'] : 'No longer an owner of ' . $s['name'], $member, sp_land(return_path('/spaces/' . $s['space_id'] . '/members'), 'owner'), 'spaceChanged', ['space_id' => $s['space_id']]);
