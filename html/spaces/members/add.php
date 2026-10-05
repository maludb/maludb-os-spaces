<?php
declare(strict_types=1);
/** Action `space_member_add` (log `space.member_add` — member_id or department_id, role, rows): an owner adds a member (a person or an agent) or every live member of a department, as member or owner. */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$errors = [];
$member = sp_ref($pdo, 'member', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors);
$department = sp_ref($pdo, 'department', null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'department', $errors);
$role = strtolower((string) (req_val('role') ?? 'member')) ?: 'member';
if (!in_array($role, ['member', 'owner'], true)) { $errors['role'] = 'The role is member or owner.'; }
if ($member === null && $department === null && $errors === []) { $errors['member'] = 'Say whom to add: a member or a department.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
$rows = sp_guard($pdo, static function () use ($pdo, $me, $s, $member, $department, $role): int {
    $pdo->beginTransaction();
    $rows = add_space_member($pdo, $s['space_id'], $member, $department, $role, $me);
    space_log($pdo, 'space.member_add', $s['space_id'], ['after' => array_filter(['member_id' => $member, 'department_id' => $department, 'role' => $role, 'rows' => $rows], static fn ($v) => $v !== null)] + ($department !== null ? ['department_id' => $department] : []));
    $pdo->commit();
    return $rows;
});
sp_done($rows === 0 ? 'Already in ' . $s['name'] : 'Added ' . $rows . ' to ' . $s['name'], $member ?? $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id'] . '/members'), 'member'), 'spaceChanged', ['space_id' => $s['space_id'], 'rows' => $rows]);
