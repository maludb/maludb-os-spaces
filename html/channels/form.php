<?php
declare(strict_types=1);
/** /channels/new?space= and /channels/{id}/edit (screens `channel-add`, `channel-edit`): space, name (the slug shown as you type), kind, topic, purpose; retention for the owner. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('channel');
$cur = null;
if ($id !== null) {
    $cur = find_channel($pdo, $id) ?? refuse(404, 'Channel not found.');
    if (in_array($cur['kind'], ['dm', 'group_dm'], true)) { refuse(422, 'A conversation has nothing to edit.'); }
    require_channel_member($cur);
    if ($cur['archived_at'] !== null) { refuse(422, 'Channel ' . $cur['label'] . ' is archived: unarchive it to change it.'); }
} else {
    require_right('channels.create');
}
$screen = $cur === null ? 'channel-add' : 'channel-edit';
$spaces = spaces_for_channel_create($pdo);
$may = ['manage' => $cur !== null && channel_owner($cur), 'retention' => $cur !== null && !in_array($cur['kind'], ['dm', 'group_dm'], true) && (has_right('retention.manage') || is_sp_admin() || (has_right('channel.manage') && !empty($cur['i_own_space'])))];
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['channel' => $cur === null ? null : present_channel($cur), 'spaces' => array_map(static fn (array $s): array => ['space_id' => (int) $s['space_id'], 'name' => $s['name']], $spaces), 'kinds' => ['public', 'private'], 'may' => $may]);
}
render_screen($cur === null ? 'Make a channel' : 'Change ' . $cur['label'], view('channels/form.php', ['cur' => $cur, 'spaces' => $spaces, 'space' => request_integer('space'), 'may' => $may, 'here' => here_url()]),
    ['activeNav' => 'channels', 'screen' => $screen, 'entity' => 'channel', 'recordId' => $id === null ? '' : (string) $id]);
