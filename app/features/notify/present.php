<?php
declare(strict_types=1);

/** The words and the JSON shapes of the bell and the feed (slice 6). Whitelists over the views' rows. */

/** Each kind of notice: its icon (design vocabulary) and its word. */
const NOTICE_LOOK = [
    'mention' => ['feather-at-sign', 'mention', 'primary'], 'reply' => ['feather-corner-down-right', 'reply', 'primary'], 'reaction' => ['feather-smile', 'reaction', 'primary'],
    'comment' => ['feather-message-circle', 'comment', 'primary'], 'share' => ['feather-share-2', 'shared', 'primary'], 'page_changed' => ['feather-edit-3', 'page changed', 'primary'],
    'verification' => ['feather-check-circle', 'verification', 'warning'], 'reminder' => ['feather-bell', 'reminder', 'primary'], 'dm' => ['feather-mail', 'message', 'primary'],
    'channel' => ['feather-hash', 'channel', 'primary'], 'join_request' => ['feather-user-plus', 'join request', 'primary'], 'join_decided' => ['feather-user-check', 'join decided', 'primary'],
    'proposal' => ['feather-book', 'proposal', 'primary'], 'agent_replied' => ['feather-cpu', 'agent', 'primary'], 'digest' => ['feather-sunrise', 'digest', 'primary'],
];

function notice_icon(string $kind): string { return NOTICE_LOOK[$kind][0] ?? 'feather-bell'; }
function notice_word(string $kind): string { return NOTICE_LOOK[$kind][1] ?? $kind; }
function notice_tone(string $kind): string { return NOTICE_LOOK[$kind][2] ?? 'primary'; }

/** The screen a notice points at (null when none): a message → its channel or DM with ?message=, a page → the page, a reminder → /reminders/, a join request → the space's requests, a proposal → /proposals/. */
function notification_record_url(array $n): ?string
{
    $kind = (string) ($n['kind'] ?? '');
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    if ($kind === 'reminder') { return '/reminders/'; }
    if ($kind === 'join_request' && $id !== null) { return '/spaces/' . $id . '/requests'; }
    if ($kind === 'join_decided' && $id !== null) { return '/spaces/' . $id; }
    if ($kind === 'proposal') { return '/proposals/'; }
    if (($n['message_id'] ?? null) !== null && ($n['channel_id'] ?? null) !== null) {
        return (($n['channel_kind'] ?? '') === 'dm' || ($n['channel_kind'] ?? '') === 'group_dm' ? '/dm/' : '/channels/') . (int) $n['channel_id'] . '?message=' . (int) $n['message_id'];
    }
    if (($n['channel_id'] ?? null) !== null) {
        return (($n['channel_kind'] ?? '') === 'dm' || ($n['channel_kind'] ?? '') === 'group_dm' ? '/dm/' : '/channels/') . (int) $n['channel_id'];
    }
    if (($n['record_uuid'] ?? null) !== null && $n['record_uuid'] !== '') { return '/pages/' . $n['record_uuid']; }
    return match ($n['record_type'] ?? null) {
        'space' => $id === null ? null : '/spaces/' . $id,
        default => null,
    };
}

/** The body's first line (a notice's body is short; this is the row's second line). */
function notice_first_line(?string $body): string
{
    $line = trim((string) strtok((string) $body, "\n"));
    return mb_strlen($line) > 160 ? mb_substr($line, 0, 159) . '…' : $line;
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'icon' => notice_icon((string) $n['kind']), 'title' => $n['title'], 'body' => $n['body'],
            'who' => ($n['actor_member_id'] ?? null) === null ? null : ['member_id' => (int) $n['actor_member_id'], 'display_name' => $n['actor_name'] ?? null, 'is_agent' => (bool) ($n['actor_is_agent'] ?? false)],
            'record_type' => $n['record_type'], 'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'record_uuid' => $n['record_uuid'] ?? null,
            'channel_id' => isset($n['channel_id']) ? (int) $n['channel_id'] : null, 'message_id' => isset($n['message_id']) ? (int) $n['message_id'] : null, 'url' => notification_record_url($n),
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

/** An Activity row: who, what, the excerpt, when, and where it leads. */
function present_activity_row(array $r): array
{
    return ['kind' => $r['kind'], 'occurred_at' => json_ts($r['occurred_at']),
            'who' => ['member_id' => $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => $r['actor_is_agent']],
            'channel_id' => $r['channel_id'], 'message_id' => $r['message_id'], 'thread_root_id' => $r['thread_root_id'], 'page_id' => $r['page_id'], 'excerpt' => $r['excerpt'], 'url' => activity_url($r)];
}

const ACTIVITY_KINDS = ['mention' => 'Mentions', 'reply' => 'Replies', 'reaction' => 'Reactions', 'comment' => 'Comments'];

/** What an Activity row says, in words (the actor's name is shown beside it). */
function activity_words(array $r): string
{
    $where = $r['channel_name'] !== null ? ' in #' . $r['channel_name'] : (($r['channel_id'] ?? null) !== null ? ' in a direct message' : '');
    return match ($r['kind']) {
        'mention' => 'mentioned you' . $where,
        'reply' => 'replied in a thread you are in' . $where,
        'reaction' => 'reacted to your message' . $where,
        'comment' => 'commented on ' . ($r['page_title'] !== null && $r['page_title'] !== '' ? $r['page_title'] : 'your page'),
        default => $r['kind'],
    };
}

/** An Activity row's link: a thread reply → its thread, a message → its channel with ?message=, a comment → the page. */
function activity_url(array $r): ?string
{
    if ($r['page_id'] !== null) { return '/pages/' . $r['page_id']; }
    if ($r['channel_id'] !== null && $r['message_id'] !== null) {
        $dm = in_array($r['channel_kind'] ?? '', ['dm', 'group_dm'], true);
        if ($r['thread_root_id'] !== null && !$dm) { return '/channels/' . $r['channel_id'] . '/threads/' . $r['thread_root_id']; }
        return ($dm ? '/dm/' : '/channels/') . $r['channel_id'] . '?message=' . $r['message_id'];
    }
    return null;
}
