<?php
declare(strict_types=1);
/** /spaces/{id}/members — who is in the space and who owns it (screen `space-members`): a table; the owner adds a member, a department's members or an agent, makes owners, removes. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
if (!$s['i_am_member'] && $s['kind'] === 'private') { refuse(404, 'Space not found.'); }
$members = space_members($pdo, $id);
$in = array_column($members, 'member_id');
$picks = array_values(array_filter(members_for_pick($pdo), static fn (array $m): bool => !in_array((int) $m['member_id'], $in, true)));
$departments = find_live_departments($pdo);
$may = ['owner' => $s['i_am_owner'] && $s['archived_at'] === null];
log_screen_view($pdo, 'space-members');
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'members' => array_map('present_space_member', $members), 'may' => $may]);
}
render_screen($s['name'] . ' · Members', view('spaces/members.php', ['s' => $s, 'members' => $members, 'picks' => $picks, 'departments' => $departments, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => sp_notice($_GET['notice'] ?? null, space_notices($s))]), ['activeNav' => 'spaces', 'screen' => 'space-members', 'entity' => 'space', 'recordId' => (string) $id]);
