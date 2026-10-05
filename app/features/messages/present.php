<?php
declare(strict_types=1);

/** The JSON shape of a message (over sp_message_row()). Never a tombstone's words. */
function present_message(array $m): array
{
    return ['message_id' => $m['message_id'], 'channel_id' => $m['channel_id'], 'thread_root_id' => $m['thread_root_id'], 'kind' => $m['kind'] ?? 'message',
            'author' => ['member_id' => $m['author_member_id'], 'display_name' => $m['author_name'] ?? null, 'is_agent' => (bool) ($m['author_is_agent'] ?? false)],
            'markdown' => (string) ($m['markdown'] ?? ''), 'plain_text' => (string) ($m['plain_text'] ?? ''), 'reply_count' => $m['reply_count'], 'last_reply_at' => json_ts($m['last_reply_at'] ?? null), 'also_to_channel' => (bool) ($m['also_to_channel'] ?? false),
            'edited_at' => json_ts($m['edited_at'] ?? null), 'deleted' => ($m['deleted_at'] ?? null) !== null, 'scheduled_for' => json_ts($m['scheduled_for'] ?? null), 'sent_at' => json_ts($m['sent_at'] ?? null), 'created_at' => json_ts($m['created_at'] ?? null),
            'agent_run_id' => $m['agent_run_id'], 'reactions' => array_map(static fn (array $r): array => ['emoji' => $r['emoji'], 'count' => (int) $r['count'], 'mine' => (bool) ($r['mine'] ?? false)], $m['reactions']),
            'attachments' => array_map(static fn (array $a): array => ['attachment_id' => (int) $a['attachment_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => (int) $a['byte_size'], 'url' => '/files/' . (int) $a['attachment_id']], $m['attachments']),
            'is_saved' => (bool) ($m['is_saved'] ?? false), 'is_pinned' => (bool) ($m['is_pinned'] ?? false)];
}
