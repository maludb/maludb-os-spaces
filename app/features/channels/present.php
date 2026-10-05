<?php
declare(strict_types=1);

/** The JSON shapes of a channel, a member row, a pin, a bookmark, a DM card — whitelists over the views' rows. */
function present_channel(array $c): array
{
    return ['channel_id' => $c['channel_id'], 'space_id' => $c['space_id'], 'space_name' => $c['space_name'] ?? null, 'kind' => $c['kind'], 'name' => $c['name'], 'label' => $c['label'], 'topic' => $c['topic'], 'purpose' => $c['purpose'],
            'is_default' => $c['is_default'], 'archived' => $c['archived_at'] !== null, 'archived_at' => json_ts($c['archived_at']), 'retention_days' => $c['retention_days'], 'member_count' => $c['member_count'], 'message_count' => $c['message_count'],
            'last_message_at' => json_ts($c['last_message_at']), 'i_am_member' => $c['i_am_member'], 'i_follow' => $c['i_follow'], 'starred' => $c['starred'], 'notify' => $c['notify'], 'muted_until' => json_ts($c['muted_until']), 'section' => $c['section'] ?? null,
            'pin_count' => $c['pin_count'] ?? 0, 'other_names' => $c['other_names'] ?? null, 'has_agent' => $c['has_agent'] ?? false, 'created_at' => json_ts($c['created_at'])];
}

function present_channel_member(array $m): array
{
    return ['member_id' => $m['member_id'], 'display_name' => $m['display_name'], 'is_agent' => $m['is_agent'], 'is_guest' => $m['is_guest'], 'joined_at' => json_ts($m['joined_at']), 'added_by' => $m['added_by'] === null ? null : (int) $m['added_by']];
}

function present_pin(array $p): array
{
    return ['pin_id' => (int) $p['pin_id'], 'channel_id' => (int) $p['channel_id'], 'message_id' => $p['message_id'] === null ? null : (int) $p['message_id'], 'page_id' => $p['page_id'], 'page_title' => $p['page_title'] ?? null,
            'message_text' => $p['message_text'] ?? null, 'message_author' => $p['message_author'] ?? null, 'pinned_by' => $p['pinned_by'] === null ? null : (int) $p['pinned_by'], 'created_at' => json_ts($p['created_at'])];
}

function present_bookmark(array $b): array
{
    return ['bookmark_id' => (int) $b['bookmark_id'], 'channel_id' => (int) $b['channel_id'], 'title' => $b['title'], 'url' => $b['url'], 'page_id' => $b['page_id'], 'page_title' => $b['page_title'] ?? null, 'emoji' => $b['emoji'], 'position' => $b['position']];
}

function present_dm(array $d): array
{
    return ['channel_id' => $d['channel_id'], 'kind' => $d['kind'], 'member_ids' => $d['member_ids'], 'names' => $d['names'], 'last_message_at' => json_ts($d['last_message_at']), 'last_line' => $d['last_line'], 'unread_count' => $d['unread_count'], 'has_agent' => $d['has_agent']];
}
