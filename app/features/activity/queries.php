<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log, db/017: their own rows; rows about pages, channels and spaces they may see; everything for the admin). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days), space, channel, message (ints), page (a UUID). */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['space' => 'space_id', 'channel' => 'channel_id', 'message' => 'message_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = "$col = :$k";
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['page'] ?? null) !== null && is_uuid($filters['page'])) {
        $where[] = 'entity_uuid = CAST(:page AS uuid)';
        $args['page'] = (string) $filters['page'];
        $keyed = true;
    }
    if (($filters['entity_type'] ?? null) !== null) {
        if (is_uuid($filters['entity_id'] ?? null)) {
            $where[] = 'entity_type = :etype AND entity_uuid = CAST(:eid AS uuid)';
            $args['eid'] = (string) $filters['entity_id'];
        } else {
            $where[] = 'entity_type = :etype AND entity_id = :eid';
            $args['eid'] = (int) $filters['entity_id'];
        }
        $args['etype'] = (string) $filters['entity_type'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, entity_type, entity_id, entity_uuid, after, space_id, channel_id, message_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by its key column where it has one (space, channel, message, page), else by entity. */
function find_record_activity(PDO $pdo, string $type, int|string $id, int $limit = 30): array
{
    $f = match ($type) { 'space' => ['space' => $id], 'channel' => ['channel' => $id], 'message' => ['message' => $id], 'page' => ['page' => $id], default => ['entity_type' => $type, 'entity_id' => $id] };
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'], 'entity_uuid' => $r['entity_uuid'],
            'space_id' => $r['space_id'] === null ? null : (int) $r['space_id'], 'channel_id' => $r['channel_id'] === null ? null : (int) $r['channel_id'],
            'message_id' => $r['message_id'] === null ? null : (int) $r['message_id'], 'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id']];
}

/** The record a row concerns, as a link: [url, label] or null. Later slices add their screens. */
function activity_record_link(array $r): ?array
{
    if (($r['message_id'] ?? null) !== null && ($r['channel_id'] ?? null) !== null) { return ['/channels/' . (int) $r['channel_id'] . '?message=' . (int) $r['message_id'], 'message']; }
    if (($r['channel_id'] ?? null) !== null) { return ['/channels/' . (int) $r['channel_id'], 'channel']; }
    if (($r['entity_uuid'] ?? null) !== null && in_array($r['entity_type'], ['page', 'database', 'block', 'comment'], true)) { return ['/pages/' . $r['entity_uuid'], $r['entity_type']]; }
    if (($r['space_id'] ?? null) !== null) { return ['/spaces/' . (int) $r['space_id'], 'space']; }
    return null;
}

/** One line for a row, in words: "Priya created a page: Welcome". Falls back to the event name. Later slices add their events. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'Spaces';
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $title = isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : '');
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
        'space.create' => 'created a space', 'space.update' => 'changed a space', 'space.archive' => 'archived a space', 'space.delete' => 'deleted a space', 'space.member_add' => 'added someone to a space',
        'space.member_remove' => 'removed someone from a space', 'space.kind_set' => 'changed who may see a space', 'space.admin_view' => 'opened a private space as admin',
        'page.create' => 'created a page', 'page.update' => 'changed a page', 'page.move' => 'moved a page', 'page.lock' => 'locked a page', 'page.unlock' => 'unlocked a page', 'page.archive' => 'moved a page to the trash',
        'page.restore' => 'restored a page', 'page.delete' => 'deleted a page for good', 'page.share' => 'shared a page', 'page.unshare' => 'stopped sharing a page', 'page.publish' => 'published a page',
        'page.unpublish' => 'unpublished a page', 'page.verify' => 'verified a page', 'page.favorite' => 'favourited a page', 'page.view' => 'opened a page', 'page.public_view' => 'a visitor opened a published page',
        'page.version_save' => 'saved a version', 'page.version_restore' => 'restored a version', 'page.import' => 'imported pages', 'page.export' => 'exported pages',
        'block.append' => 'added to a page', 'block.update' => 'edited a page', 'block.move' => 'rearranged a page', 'block.delete' => 'removed from a page',
        'database.create' => 'created a database', 'database.schema_save' => 'changed a database', 'database.delete' => 'deleted a database', 'row.create' => 'added a row', 'row.update' => 'changed a row', 'row.delete' => 'removed a row',
        'view.save' => 'changed a view', 'view.delete' => 'removed a view',
        'channel.create' => 'created a channel', 'channel.update' => 'changed a channel', 'channel.join' => 'joined a channel', 'channel.leave' => 'left a channel', 'channel.archive' => 'archived a channel',
        'channel.unarchive' => 'unarchived a channel', 'channel.delete' => 'deleted a channel', 'channel.pin' => 'pinned something', 'channel.unpin' => 'unpinned something', 'channel.retention_set' => 'set a retention',
        'message.post' => 'posted a message', 'message.edit' => 'edited a message', 'message.delete' => 'deleted a message', 'message.schedule' => 'scheduled a message', 'message.react' => 'reacted', 'message.save' => 'saved a message',
        'message.also_to_channel' => 'sent a reply to the channel', 'thread.reply' => 'replied in a thread',
        'comment.add' => 'commented', 'comment.edit' => 'edited a comment', 'comment.resolve' => 'resolved a discussion', 'comment.delete' => 'deleted a comment',
        'reminder.set' => 'set a reminder', 'reminder.done' => 'finished a reminder', 'agent.dispatch' => 'asked an agent', 'agent.reply' => 'an agent replied', 'agent.fail' => 'an agent could not answer',
        'librarian.propose' => 'proposed something', 'librarian.accept' => 'accepted a proposal', 'librarian.dismiss' => 'dismissed a proposal',
        'attachment.add' => 'attached a file', 'attachment.delete' => 'removed an attachment', 'export.download' => 'downloaded an export', 'share.read' => 'a sibling application read our data',
        'worker.pass' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
