<?php
declare(strict_types=1);

/** The JSON shapes of a space, a member row, a request and a section — whitelists over the views' rows. */
function present_space(array $s): array
{
    return ['space_id' => $s['space_id'], 'name' => $s['name'], 'slug' => $s['slug'], 'icon' => $s['icon'], 'description' => $s['description'], 'kind' => $s['kind'], 'is_default' => $s['is_default'],
            'department' => $s['department_id'] === null ? null : ['department_id' => $s['department_id'], 'name' => $s['department_name']],
            'member_level' => $s['member_level'], 'everyone_level' => $s['everyone_level'], 'is_wiki' => $s['is_wiki'], 'wiki_default_verify_months' => $s['wiki_default_verify_months'],
            'default_channel_id' => $s['default_channel_id'], 'i_am_member' => $s['i_am_member'], 'i_am_owner' => $s['i_am_owner'], 'my_level' => $s['my_level'] ?? null,
            'owner_ids' => $s['owner_ids'] ?? null, 'owner_names' => $s['owner_names'], 'member_count' => $s['member_count'], 'page_count' => $s['page_count'], 'channel_count' => $s['channel_count'],
            'archived' => $s['archived_at'] !== null, 'archived_at' => json_ts($s['archived_at']), 'created_at' => json_ts($s['created_at']), 'updated_at' => json_ts($s['updated_at'])];
}

function present_space_member(array $m): array
{
    return ['member_id' => $m['member_id'], 'display_name' => $m['display_name'], 'is_agent' => $m['is_agent'], 'is_guest' => $m['is_guest'], 'role' => $m['role'], 'derived' => $m['derived'], 'joined_at' => json_ts($m['joined_at'])];
}

function present_join_request(array $r): array
{
    return ['join_request_id' => $r['join_request_id'], 'space_id' => $r['space_id'], 'member_id' => $r['member_id'], 'display_name' => $r['display_name'], 'message' => $r['message'], 'status' => $r['status'],
            'decided_by' => $r['decided_by'], 'decided_by_name' => $r['decided_by_name'] ?? null, 'decided_at' => json_ts($r['decided_at']), 'created_at' => json_ts($r['created_at'])];
}

function present_section(array $x): array
{
    return ['section_id' => $x['section_id'], 'name' => $x['name'], 'position' => $x['position'], 'page_count' => $x['page_count'] ?? null,
            'pages' => isset($x['pages']) ? array_map('present_root_page', $x['pages']) : null];
}

function present_root_page(array $p): array
{
    return ['page_id' => $p['page_id'], 'title' => $p['title'], 'icon' => $p['icon'], 'kind' => $p['kind'], 'section_id' => $p['section_id'] === null ? null : (int) $p['section_id'], 'child_count' => $p['child_count'] ?? null,
            'my_level' => $p['my_level'] ?? null, 'last_edited_at' => json_ts($p['last_edited_at'] ?? null)];
}

function present_space_channel(array $c): array
{
    return ['channel_id' => $c['channel_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'topic' => $c['topic'], 'is_default' => $c['is_default'], 'i_am_member' => $c['i_am_member'], 'member_count' => $c['member_count']];
}
