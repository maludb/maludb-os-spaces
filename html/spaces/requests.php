<?php
declare(strict_types=1);
/** /spaces/{id}/requests — the pending requests to join a closed space, to approve or decline; the decided ones below (screen `space-requests`; the owner). */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
require_space_owner($s);
$rows = space_requests($pdo, $id);
log_screen_view($pdo, 'space-requests');
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'requests' => array_map('present_join_request', $rows)]);
}
render_screen($s['name'] . ' · Requests', view('spaces/requests.php', ['s' => $s, 'rows' => $rows, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, space_notices($s))]),
    ['activeNav' => 'spaces', 'screen' => 'space-requests', 'entity' => 'space', 'recordId' => (string) $id]);
