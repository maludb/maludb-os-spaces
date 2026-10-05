<?php
declare(strict_types=1);
/** /dm/new (the vhost's canonical form.php) — the people picker (members, agents, the guests I may see): one person → dm_open, several → group_dm_open (screen `dm-new`). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_login();
require_human();
if (!has_right('dm.write') && !is_guest()) { require_right('dm.write'); }
$pdo = db();
$me = (int) current_member_id();
$people = dm_candidates($pdo, $me);
$max = (int) (one_value($pdo, 'SELECT group_dm_max_members FROM sp_settings WHERE id = 1') ?: 9);
log_screen_view($pdo, 'dm-new');
if (wants_json()) {
    respond_screen(['people' => array_map(static fn (array $m): array => ['member_id' => $m['member_id'], 'display_name' => $m['display_name'], 'is_agent' => $m['is_agent'], 'is_guest' => $m['is_guest']], $people), 'group_max' => $max]);
}
render_screen('New message', view('dm/new.php', ['people' => $people, 'max' => $max, 'here' => here_url()]), ['activeNav' => 'dms', 'screen' => 'dm-new', 'entity' => 'channel']);
