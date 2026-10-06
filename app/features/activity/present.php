<?php
declare(strict_types=1);

/**
 * The trail's words (slice 9): one sentence for every event the manifest and the code log, the actor chipped when an agent (the sentence ends "(agent)"), a cron row "the worker", the record a row concerns as a link,
 * and a row's JSON. A page's text and a message's body are never in a payload, so a sentence names only what a payload carries — a title or a name.
 */

const ACTIVITY_WORDS = [
    'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'directory.sync' => 'refreshed the directory',
    'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
    'space.create' => 'created a space', 'space.update' => 'changed a space', 'space.archive' => 'archived a space', 'space.delete' => 'deleted a space', 'space.member_add' => 'added someone to a space',
    'space.member_remove' => 'removed someone from a space', 'space.kind_set' => 'changed who may see a space', 'space.admin_view' => 'opened a private space as admin',
    'page.create' => 'created a page', 'page.update' => 'changed a page', 'page.move' => 'moved a page', 'page.lock' => 'locked a page', 'page.unlock' => 'unlocked a page', 'page.archive' => 'moved a page to the trash',
    'page.restore' => 'restored a page', 'page.delete' => 'deleted a page for good', 'page.share' => 'shared a page', 'page.unshare' => 'stopped sharing a page', 'page.publish' => 'published a page',
    'page.unpublish' => 'unpublished a page', 'page.verify' => 'verified a page', 'page.favorite' => 'favourited a page', 'page.view' => 'opened a page', 'page.public_view' => 'had a visitor open a published page',
    'page.version_save' => 'saved a version', 'page.version_restore' => 'restored a version', 'page.import' => 'imported pages', 'page.export' => 'exported pages',
    'block.append' => 'added to a page', 'block.update' => 'edited a page', 'block.move' => 'rearranged a page', 'block.delete' => 'removed from a page',
    'database.create' => 'created a database', 'database.schema_save' => 'changed a database', 'database.delete' => 'deleted a database', 'row.create' => 'added a row', 'row.update' => 'changed a row', 'row.delete' => 'removed a row',
    'view.save' => 'changed a view', 'view.delete' => 'removed a view',
    'channel.create' => 'created a channel', 'channel.update' => 'changed a channel', 'channel.join' => 'joined a channel', 'channel.leave' => 'left a channel', 'channel.archive' => 'archived a channel',
    'channel.unarchive' => 'unarchived a channel', 'channel.delete' => 'deleted a channel', 'channel.pin' => 'pinned something', 'channel.unpin' => 'unpinned something', 'channel.retention_set' => 'set a retention',
    'message.post' => 'posted a message', 'message.edit' => 'edited a message', 'message.delete' => 'deleted a message', 'message.schedule' => 'scheduled a message', 'message.react' => 'reacted', 'message.save' => 'saved a message',
    'message.also_to_channel' => 'sent a reply to the channel', 'thread.reply' => 'replied in a thread',
    'comment.add' => 'commented', 'comment.edit' => 'edited a comment', 'comment.resolve' => 'resolved a discussion', 'comment.delete' => 'deleted a comment',
    'reminder.set' => 'set a reminder', 'reminder.done' => 'finished a reminder', 'agent.dispatch' => 'asked an agent', 'agent.reply' => 'replied', 'agent.fail' => 'could not answer',
    'librarian.propose' => 'proposed something', 'librarian.accept' => 'accepted a proposal', 'librarian.dismiss' => 'dismissed a proposal',
    'attachment.add' => 'attached a file', 'attachment.delete' => 'removed an attachment', 'export.download' => 'downloaded an export', 'share.read' => 'had a sibling application read its data',
    'worker.pass' => 'ran a pass', 'notification.send' => 'sent a notice',
    'assistant.ask' => 'asked the assistant', 'block.insert' => 'added to a page', 'channel.bookmark_delete' => 'removed a bookmark', 'channel.bookmark_save' => 'saved a bookmark', 'channel.export' => 'exported a channel',
    'channel.guest_add' => 'added a guest to a channel', 'channel.member_add' => 'added someone to a channel', 'channel.member_remove' => 'removed someone from a channel', 'channel.notify_set' => 'changed how a channel tells them',
    'channel.read' => 'caught up on a channel', 'database.property_remove' => 'removed a property', 'database.property_save' => 'changed a property', 'database.update' => 'changed a database',
    'emoji.save' => 'saved an emoji', 'emoji.delete' => 'removed an emoji', 'export.all' => 'exported everything', 'export.delete' => 'deleted an export', 'export.own' => 'exported their own pages', 'export.space' => 'exported a space',
    'import.file_expire' => 'let an import\'s file expire', 'message.announce' => 'announced to a channel', 'message.delete_own' => 'deleted their own message', 'message.unreact' => 'took back a reaction', 'message.unschedule' => 'cancelled a scheduled message',
    'notification.digest' => 'was sent the morning digest', 'notification.fail' => 'could not be told', 'notification.skip' => 'was not told', 'page.duplicate' => 'duplicated a page', 'page.nudge' => 'nudged a page\'s owner',
    'page.owner_set' => 'changed a page\'s owner', 'page.publish_rotate' => 'made a new link for a published page', 'page.restrict' => 'made a page private to itself', 'page.section_set' => 'moved a page to a section',
    'page.share_guest' => 'shared a page with a guest', 'page.template_apply' => 'made a page from a template', 'page.template_publish' => 'saved a page as a template', 'page.trash' => 'moved a page to the trash',
    'page.unrestrict' => 'opened a page to its parent\'s people', 'reminder.fire' => 'was reminded', 'retention.manage' => 'changed a retention', 'row.relation_set' => 'linked rows', 'section.delete' => 'removed a section',
    'section.save' => 'saved a section', 'settings.save' => 'changed the workspace settings', 'share.guest' => 'shared with a guest', 'share.member' => 'shared with a member', 'space.export' => 'exported a space',
    'space.join_decide' => 'decided a request to join', 'space.join_request' => 'asked to join a space', 'space.join_withdraw' => 'withdrew a request to join', 'space.owner_set' => 'changed who owns a space', 'space.restore' => 'restored a space',
    'space.wiki_set' => 'changed a space\'s wiki settings', 'status.set' => 'changed their status', 'trash.purge' => 'emptied the trash', 'view.reorder' => 'rearranged the views of a database',
    'screen.view' => 'opened a screen',
];

/** The words for an event (null when the event is not one we log). */
function activity_event_words(string $action): ?string
{
    return ACTIVITY_WORDS[$action] ?? null;
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_trail_row(array $r): array
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
    $who = $r['actor_name'] ?? (($r['source'] ?? '') === 'cron' ? 'The worker' : 'Spaces');
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $title = isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : '');
    return $who . ' ' . (activity_event_words((string) $r['action']) ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . ($r['action'] === 'screen.view' ? '' : $title);
}
