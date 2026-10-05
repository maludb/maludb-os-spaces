<?php
declare(strict_types=1);

/** The JSON shapes of a page, a permission row, a version, a trash card, a template, a wiki row — whitelists over the views' rows. */
function present_page(array $p): array
{
    return ['page_id' => $p['page_id'], 'title' => $p['plain_title'], 'icon' => $p['icon'], 'cover_attachment_id' => $p['cover_attachment_id'], 'kind' => $p['kind'], 'is_row' => $p['is_row'],
            'space' => $p['space_id'] === null ? null : ['space_id' => $p['space_id'], 'name' => $p['space_name']], 'private' => $p['is_private'], 'parent_page_id' => $p['parent_page_id'],
            'owner' => $p['owner_member_id'] === null ? null : ['member_id' => $p['owner_member_id'], 'display_name' => $p['owner_name']],
            'wiki_owner' => $p['wiki_owner_member_id'] === null ? null : ['member_id' => $p['wiki_owner_member_id'], 'display_name' => $p['wiki_owner_name']],
            'verification' => ['state' => $p['verification_state'], 'verified_at' => json_ts($p['verified_at']), 'verify_until' => json_ts($p['verify_until'])],
            'is_locked' => $p['is_locked'], 'is_template' => $p['is_template'], 'is_published' => $p['is_published'], 'is_favorite' => $p['is_favorite'], 'restricted' => $p['permission_root_id'] === $p['page_id'],
            'my_level' => $p['my_level'], 'child_count' => $p['child_count'], 'open_comments' => $p['open_comment_count'], 'content_rev' => $p['content_rev'], 'version_no' => $p['version_no'],
            'breadcrumb' => $p['breadcrumb'] ?? null, 'last_edited' => ['by' => $p['last_edited_by'], 'name' => $p['editor_name'], 'at' => json_ts($p['last_edited_at'])],
            'created_at' => json_ts($p['created_at']), 'trashed' => $p['archived_at'] !== null, 'archived_at' => json_ts($p['archived_at'])];
}

function present_permission(array $r): array
{
    return ['source' => $r['source'], 'source_page_id' => $r['source_page_id'], 'source_title' => $r['source_title'], 'principal_kind' => $r['principal_kind'], 'principal_id' => $r['principal_id'], 'principal_name' => $r['principal_name'], 'level' => $r['level']];
}

function present_version(array $v): array
{
    return ['version_id' => $v['version_id'], 'version_no' => $v['version_no'], 'reason' => $v['reason'], 'saved_by' => $v['saved_by'] === null ? null : (int) $v['saved_by'], 'saved_by_name' => $v['saved_by_name'], 'created_at' => json_ts($v['created_at']), 'text_length' => $v['text_length']];
}

function present_trash(array $t): array
{
    return ['page_id' => $t['page_id'], 'title' => $t['plain_title'], 'icon' => $t['icon'], 'kind' => $t['kind'], 'space' => $t['space_id'] === null ? null : ['space_id' => $t['space_id'], 'name' => $t['space_name']],
            'archived_by' => $t['archived_by'] === null ? null : (int) $t['archived_by'], 'archived_by_name' => $t['archived_by_name'], 'archived_at' => json_ts($t['archived_at']), 'purge_at' => json_ts($t['purge_at'])];
}

function present_template(array $t): array
{
    return ['page_id' => $t['page_id'], 'title' => $t['plain_title'], 'icon' => $t['icon'], 'kind' => $t['kind'], 'space' => $t['space_id'] === null ? null : ['space_id' => $t['space_id'], 'name' => $t['space_name'], 'is_default' => $t['is_default']], 'last_edited_at' => json_ts($t['last_edited_at'])];
}

function present_wiki_row(array $w): array
{
    return ['page_id' => $w['page_id'], 'title' => $w['title'], 'state' => $w['verification_state'], 'verified_at' => json_ts($w['verified_at']), 'verify_until' => json_ts($w['verify_until']),
            'owner' => $w['wiki_owner_member_id'] === null ? null : ['member_id' => $w['wiki_owner_member_id'], 'display_name' => $w['owner_name']], 'last_edited_at' => json_ts($w['last_edited_at']), 'days_since_edit' => $w['days_since_edit']];
}

function present_publication(?array $pb): ?array
{
    return $pb === null ? null : ['publication_id' => $pb['publication_id'], 'include_subpages' => $pb['include_subpages'], 'noindex' => $pb['noindex'], 'layout' => $pb['layout'], 'public_properties' => $pb['public_properties'],
        'published_by' => $pb['published_by'] === null ? null : (int) $pb['published_by'], 'published_by_name' => $pb['published_by_name'], 'published_at' => json_ts($pb['published_at']), 'views' => $pb['views'], 'last_viewed_at' => json_ts($pb['last_viewed_at'])];
}
