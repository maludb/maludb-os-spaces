<?php
declare(strict_types=1);
/** /channels/{id}/members — who is in (private) or follows (public): a table; add a member or an agent, add a guest, remove (screen `channel-members`). */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$members = channel_members($pdo, $id);
$may = ['add' => $c['i_am_member'] && $c['archived_at'] === null && !in_array($c['kind'], ['dm'], true), 'guest' => channel_owner($c) && has_right('share.guest') && $c['archived_at'] === null && $c['kind'] !== 'dm', 'remove' => channel_owner($c) && $c['kind'] !== 'dm'];
$cands = $may['add'] ? channel_candidates($pdo, $c) : ['members' => [], 'guests' => []];
log_screen_view($pdo, 'channel-members');
if (wants_json()) {
    respond_screen(['channel' => present_channel($c), 'members' => array_map('present_channel_member', $members), 'candidates' => array_map(static fn (array $m): array => ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name'], 'is_agent' => (bool) $m['is_agent']], $cands['members']),
        'guest_candidates' => array_map(static fn (array $m): array => ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name']], $cands['guests']), 'may' => $may]);
}
render_screen('Members of ' . $c['label'], view('channels/members.php', ['c' => $c, 'members' => $members, 'cands' => $cands, 'may' => $may, 'me' => (int) current_member_id(), 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, channel_notices($c))]),
    ['activeNav' => 'channels', 'screen' => 'channel-members', 'entity' => 'channel', 'recordId' => (string) $id]);
