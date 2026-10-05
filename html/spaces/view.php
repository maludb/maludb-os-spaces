<?php
declare(strict_types=1);
/** /spaces/{id} — a space's home (screen `space-view`): its sections and root pages, channels, members, the wiki's status; Join / Request / Leave by my standing; the owner's buttons. The admin opening a private space they are not in is logged. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
if ($s['kind'] === 'private' && !$s['i_am_member'] && is_sp_admin()) {
    space_log($pdo, 'space.admin_view', $id, ['after' => ['name' => $s['name']]]);
}
$home = space_home($pdo, $id);
$request = $s['i_am_member'] ? null : my_request($pdo, $id, $me);
$pendingCount = $s['i_am_owner'] ? count(space_requests($pdo, $id, true)) : 0;
$timeline = $s['i_am_owner'] ? space_timeline($pdo, $id, 20) : [];
$may = ['owner' => $s['i_am_owner'], 'join' => !$s['i_am_member'] && $s['kind'] === 'open' && has_right('spaces.join') && $s['archived_at'] === null,
        'request' => !$s['i_am_member'] && $s['kind'] === 'closed' && has_right('spaces.join') && $s['archived_at'] === null && ($request === null || $request['status'] !== 'pending'),
        'leave' => $s['i_am_member'] && !$s['is_default'] && $s['archived_at'] === null, 'delete' => is_sp_admin() && $s['archived_at'] !== null, 'write' => in_array($s['my_level'], ['edit', 'full'], true) && $s['archived_at'] === null];
log_screen_view($pdo, 'space-view');
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'sections' => array_map('present_section', $home['sections']), 'pages' => array_map('present_root_page', $home['pages']), 'channels' => array_map('present_space_channel', $home['channels']),
        'members' => array_map('present_space_member', $home['members']), 'wiki' => $home['wiki'], 'my_request' => $request, 'pending_requests' => $pendingCount, 'timeline' => array_map('present_activity_row', $timeline), 'may' => $may]);
}
render_screen($s['name'], view('spaces/view.php', ['s' => $s, 'home' => $home, 'request' => $request, 'pendingCount' => $pendingCount, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => sp_notice($_GET['notice'] ?? null, space_notices($s))]), ['activeNav' => 'spaces', 'screen' => 'space-view', 'entity' => 'space', 'recordId' => (string) $id]);
