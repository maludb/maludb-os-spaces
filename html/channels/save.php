<?php
declare(strict_types=1);
/** Actions `channel_create` (log `channel.create`: name, kind, topic) and `channel_update` (log `channel.update`: the changed fields): create → a member of the space with channels.create; update → topic and purpose for a member, the rest an owner or channel.manage. */
require_once dirname(__DIR__, 2) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('channel');
$cur = $id === null ? null : (find_channel($pdo, $id) ?? refuse(404, 'Channel not found.'));
if ($cur !== null) {
    if (in_array($cur['kind'], ['dm', 'group_dm'], true)) { refuse(422, 'A conversation has nothing to edit.'); }
    require_channel_member($cur);
    if ($cur['archived_at'] !== null) { refuse(422, 'Channel ' . $cur['label'] . ' is archived: unarchive it to change it.'); }
} else {
    require_right('channels.create');
}
$errors = [];
$keep = $cur ?? [];
$spaceId = $cur === null ? sp_ref($pdo, 'space', null, 'SELECT 1 FROM mcp_spaces WHERE space_id = :id AND i_am_member AND archived_at IS NULL', 'a space you are in', $errors, false) : $cur['space_id'];
$name = req_has('name') ? strtolower(trim((string) req_val('name'))) : (string) ($keep['name'] ?? '');
$name = ltrim($name, '#');
if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $name)) { $errors['name'] = 'A name is lowercase letters, digits, dashes and underscores (up to 80).'; }
$kind = req_has('kind') ? (string) req_val('kind') : (string) ($keep['kind'] ?? 'public');
if (!in_array($kind, ['public', 'private'], true)) { $errors['kind'] = 'The kind is public or private.'; }
$topic = req_has('topic') ? mb_substr(trim((string) req_val('topic')), 0, 250) : (string) ($keep['topic'] ?? '');
$purpose = req_has('purpose') ? mb_substr(trim((string) req_val('purpose')), 0, 250) : (string) ($keep['purpose'] ?? '');
if ($errors !== []) { sp_refuse_fields($errors); }
if ($cur !== null && ($name !== $cur['name'] || $kind !== $cur['kind'])) { require_channel_owner($cur); }
if ($cur !== null && $cur['is_default'] && ($name !== $cur['name'] || $kind !== 'public')) { refuse(422, 'The space\'s default channel keeps its name and stays public.'); }
$f = ['space_id' => $spaceId, 'name' => $name, 'kind' => $kind, 'topic' => $topic === '' ? null : $topic, 'purpose' => $purpose === '' ? null : $purpose];
$id = sp_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur): int {
    $pdo->beginTransaction();
    $newId = save_channel($pdo, $id, $f, $me);
    if ($cur === null) {
        log_activity($pdo, 'channel.create', 'channel', $newId, ['channel_id' => $newId, 'space_id' => $f['space_id'], 'after' => ['name' => $f['name'], 'kind' => $f['kind'], 'topic' => $f['topic']]]);
    } else {
        $d = sp_diff(['name' => $cur['name'], 'kind' => $cur['kind'], 'topic' => $cur['topic'], 'purpose' => $cur['purpose']], ['name' => $f['name'], 'kind' => $f['kind'], 'topic' => $f['topic'], 'purpose' => $f['purpose']]);
        channel_log($pdo, 'channel.update', $cur, $d);
    }
    $pdo->commit();
    return $newId;
});
sp_done($cur === null ? 'Made #' . $f['name'] : 'Saved #' . $f['name'], $id, sp_land(return_path('/channels/' . $id), $cur === null ? 'created' : 'saved'), 'channelChanged', ['channel_id' => $id, 'name' => $f['name']]);
