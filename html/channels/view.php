<?php
declare(strict_types=1);
/**
 * /channels/{id}?message=&before= — the conversation (screen `channel-view`; `dm-view` includes this for /dm/{id}): the header, the last 50 messages
 * (or 50 before a given one), day dividers, the unread line, the composer; the poll is /channels/{id}/since. Opening marks nothing read: the
 * client does when it reaches the bottom.
 */
require_once dirname(__DIR__, 2) . '/app/features/messages/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? request_integer('channel') ?? refuse(404, 'Channel not found.');
$c = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
$isDm = in_array($c['kind'], ['dm', 'group_dm'], true);
$screen = $isDm ? 'dm-view' : 'channel-view';
$before = request_integer('before');
$rows = channel_history($pdo, $id, $before, null, 50);
$unread = channel_unread($pdo, $id);
$may = message_may($c) + ['join' => !$c['i_follow'] && $c['kind'] === 'public' && !$isDm && $c['archived_at'] === null && !is_guest(), 'leave' => $c['i_follow'] && !$c['is_default'] && !$isDm,
    'edit' => !$isDm && $c['i_am_member'] && $c['archived_at'] === null, 'members' => !$isDm, 'archive' => !$isDm && channel_owner($c) && !$c['is_default'], 'delete' => is_sp_admin() && $c['archived_at'] !== null && !$isDm,
    'announce' => !$isDm && $c['i_am_member'] && $c['archived_at'] === null && (current_member()['member_kind'] ?? '') === 'human', 'guest' => is_guest()];
$members = $isDm ? channel_members($pdo, $id) : [];
$running = running_dispatches($pdo, $id);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['channel' => present_channel($c), 'messages' => array_map('present_message', $rows), 'unread' => $unread, 'members' => array_map('present_channel_member', $members), 'running' => $running, 'hashes' => row_hashes($rows), 'may' => $may]);
}
render_screen($c['label'], view('channels/view.php', ['c' => $c, 'rows' => $rows, 'unread' => $unread, 'members' => $members, 'running' => $running, 'may' => $may, 'me' => $me, 'before' => $before, 'focus' => request_integer('message'),
    'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, channel_notices($c))]), ['activeNav' => $isDm ? 'dms' : 'channels', 'screen' => $screen, 'entity' => 'channel', 'recordId' => (string) $id]);
