<?php
declare(strict_types=1);
/** /spaces/ — the spaces as cards in three groups: Mine, Open to join, Closed (screen `space-list`; params kind, q). The admin sees every space, the private ones marked. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_right('spaces.join');
require_human();
$pdo = db();
$me = (int) current_member_id();
$filters = ['kind' => in_array(request_string('kind'), ['open', 'closed', 'private'], true) ? request_string('kind') : '', 'q' => mb_substr(request_string('q'), 0, 80), 'include_archived' => request_bool('archived')];
$rows = find_spaces($pdo, $filters);
$pending = [];
$pr = $pdo->prepare("SELECT space_id FROM mcp_space_join_requests WHERE member_id = :m AND status = 'pending'");
$pr->execute(['m' => $me]);
foreach ($pr->fetchAll(PDO::FETCH_COLUMN) as $sid) { $pending[(int) $sid] = true; }
$groups = ['mine' => [], 'open' => [], 'closed' => []];
foreach ($rows as $s) {
    if ($s['i_am_member'] || ($s['kind'] === 'private' && !$s['i_am_member'])) { $groups['mine'][] = $s; }
    elseif ($s['kind'] === 'open') { $groups['open'][] = $s; }
    else { $groups['closed'][] = $s; }
}
$may = ['create' => has_right('spaces.join'), 'admin' => is_sp_admin()];
log_screen_view($pdo, 'space-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'mine' => array_map('present_space', $groups['mine']), 'open' => array_map('present_space', $groups['open']), 'closed' => array_map('present_space', $groups['closed']),
        'pending_space_ids' => array_keys($pending), 'may' => $may]);
}
$notices = ['deleted' => ['success', 'The space was deleted.'], 'left' => ['success', 'You left the space.'], 'withdrawn' => ['success', 'Your request is withdrawn.']];
render_screen('Spaces', view('spaces/index.php', ['groups' => $groups, 'filters' => $filters, 'pending' => $pending, 'may' => $may, 'here' => here_url(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'spaces', 'screen' => 'space-list', 'entity' => 'space']);
