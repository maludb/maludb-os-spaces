<?php
declare(strict_types=1);
/** The admin pages' JSON: whitelists over the rows (the settings with the attachment limit in both units; the lists the screens show). */

function present_settings(array $s): array
{
    $o = $s;
    $o['updated_at'] = json_ts($s['updated_at']);
    return $o;
}

function present_admin_space(array $s): array
{
    return ['space_id' => $s['space_id'], 'name' => $s['name'], 'slug' => $s['slug'], 'icon' => $s['icon'], 'kind' => $s['kind'], 'is_default' => $s['is_default'], 'is_wiki' => $s['is_wiki'], 'private' => $s['kind'] === 'private',
            'archived' => $s['archived_at'] !== null, 'archived_at' => json_ts($s['archived_at']), 'member_count' => $s['member_count'], 'page_count' => $s['page_count'], 'channel_count' => $s['channel_count'], 'owners' => $s['owner_names'], 'i_am_member' => $s['i_am_member']];
}

function present_publication(array $p): array
{
    return ['publication_id' => $p['publication_id'], 'page_id' => $p['page_id'], 'title' => $p['title'], 'space' => $p['space_id'] === null ? null : ['space_id' => $p['space_id'], 'name' => $p['space_name']],
            'published_by' => $p['published_by'] === null ? null : (int) $p['published_by'], 'published_by_name' => $p['published_by_name'], 'published_at' => json_ts($p['published_at']), 'include_subpages' => $p['include_subpages'],
            'noindex' => $p['noindex'], 'views' => $p['views'], 'last_viewed_at' => json_ts($p['last_viewed_at'])];
}
