<?php
declare(strict_types=1);
/** /spaces/{id}/templates — the templates of this space and the workspace's as cards (screen `space-templates`; slice 2 builds apply and publish — here the list and links). */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
if (!$s['i_am_member'] && $s['kind'] === 'private') { refuse(404, 'Space not found.'); }
$rows = space_templates($pdo, $id);
log_screen_view($pdo, 'space-templates');
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'templates' => array_map(static fn (array $t): array => ['page_id' => $t['page_id'], 'title' => $t['title'], 'icon' => $t['icon'], 'kind' => $t['kind'], 'space_id' => $t['space_id'] === null ? null : (int) $t['space_id'], 'last_edited_at' => json_ts($t['last_edited_at'])], $rows)]);
}
render_screen($s['name'] . ' · Templates', view('spaces/templates.php', ['s' => $s, 'rows' => $rows, 'here' => here_url(), 'tz' => member_timezone()]),
    ['activeNav' => 'spaces', 'screen' => 'space-templates', 'entity' => 'space', 'recordId' => (string) $id]);
