<?php
declare(strict_types=1);
/** The home's JSON: the same regions as the screen, whitelisted (home_summary's rows are already narrow). A region the caller does not have is null. */
require_once dirname(__DIR__) . '/notify/present.php';

function present_home(array $s): array
{
    $sb = $s['sidebar'] ?? [];
    $out = [
        'unread' => array_map(static fn (array $u): array => $u + ['url' => ($u['kind'] === 'dm' || $u['kind'] === 'group_dm' ? '/dm/' : '/channels/') . $u['channel_id'] . ($u['first_message_id'] !== null ? '?message=' . $u['first_message_id'] : '')], $s['unread']),
        'mentions' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'occurred_at' => json_ts($r['occurred_at']), 'actor_name' => $r['actor_name'], 'actor_is_agent' => $r['actor_is_agent'], 'excerpt' => $r['excerpt'], 'url' => activity_url($r)], $s['mentions']),
        'recent_pages' => array_map(static fn (array $p): array => ['page_id' => $p['page_id'], 'title' => $p['title'], 'space_id' => $p['space_id'], 'space_name' => $p['space_name'], 'last_edited_at' => json_ts($p['last_edited_at']), 'editor_name' => $p['editor_name']], $s['recent_pages']),
        'favorites' => $s['favorites'],
        'verification_due' => array_map(static fn (array $p): array => ['page_id' => $p['page_id'], 'title' => $p['title'], 'space_id' => $p['space_id'], 'space_name' => $p['space_name'], 'state' => $p['verification_state'], 'verify_until' => json_ts($p['verify_until'])], $s['verification_due']),
        'librarian_note' => $s['librarian_note'] === null ? null : ['message_id' => $s['librarian_note']['message_id'], 'channel_id' => $s['librarian_note']['channel_id'], 'channel_name' => $s['librarian_note']['channel_name'], 'author_name' => $s['librarian_note']['author_name'], 'excerpt' => $s['librarian_note']['excerpt'], 'sent_at' => json_ts($s['librarian_note']['sent_at'])],
        'pending_joins' => $s['pending_joins'],
        'unanswered' => $s['unanswered'] === null ? null : array_map(static fn (array $u): array => ['message_id' => $u['message_id'], 'channel_id' => $u['channel_id'], 'channel_name' => $u['channel_name'], 'space_id' => $u['space_id'], 'space_name' => $u['space_name'], 'author_name' => $u['author_name'], 'asked_at' => json_ts($u['asked_at']), 'excerpt' => $u['excerpt'], 'hours_open' => $u['hours_open']], $s['unanswered']),
        'admin' => $s['admin'] === null ? null : ['dispatches' => $s['admin']['dispatches'], 'published' => ['count' => $s['admin']['published']['count'], 'views' => $s['admin']['published']['views'], 'last_opened' => json_ts($s['admin']['published']['last_opened'])], 'trash' => ['count' => $s['admin']['trash']['count'], 'next_purge' => json_ts($s['admin']['trash']['next_purge'])]],
        'shared' => array_map(static fn (array $p): array => ['page_id' => (string) $p['page_id'], 'title' => $p['title'], 'icon' => $p['icon'] ?? null], $sb['shared'] ?? []),
        'spaces' => array_map(static fn (array $sp): array => ['space_id' => (int) $sp['space_id'], 'name' => $sp['name'], 'icon' => $sp['icon'] ?? null, 'kind' => $sp['kind'], 'is_owner' => !empty($sp['is_owner'])], $sb['spaces'] ?? []),
        'sidebar' => $sb,
        'since' => json_ts($s['since']),
        'may' => $s['may'],
    ];
    return $out;
}
