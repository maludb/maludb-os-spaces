<?php
declare(strict_types=1);

/** A search result as the screen and the JSON show it (slice 6): the kind, the title, the excerpt (the match marked), where it is, who, when, and where it leads. */

/** The excerpt sp_search() made — ts_headline's text with <mark> around the match — made safe: everything escaped except the two mark tags. */
function search_excerpt_html(?string $excerpt): string
{
    $safe = e((string) $excerpt);
    return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], $safe);
}

/** The excerpt as text, the marks removed (JSON). */
function search_excerpt_text(?string $excerpt): string
{
    return str_replace(['<mark>', '</mark>'], '', (string) $excerpt);
}

/** One result: ['kind', 'title', 'excerpt_html', 'excerpt', 'url', 'where' => [label, …], 'who', 'at', …ids]. $ctx is search_context(). */
function present_search_row(array $ctx, array $r): array
{
    $kind = (string) $r['entity_kind'];
    $pid = $r['page_id'];
    $page = $pid !== null ? ($ctx['pages'][$pid] ?? null) : null;
    $chan = $r['channel_id'] !== null ? ($ctx['channels'][$r['channel_id']] ?? null) : null;
    $where = [];
    $url = null;
    $title = (string) ($r['title'] ?? '');
    if ($kind === 'page' || $kind === 'row' || $kind === 'comment') {
        $space = $r['space_id'] !== null ? ($ctx['spaces'][$r['space_id']] ?? null) : null;
        if ($space !== null) { $where[] = $space; }
        foreach ($ctx['crumbs'][$pid] ?? [] as $c) { $where[] = $c !== '' ? $c : 'Untitled'; }
        if ($kind === 'row' && $page !== null && $page['parent_database_id'] !== null) {
            $url = '/databases/' . $page['parent_database_id'] . '/rows/' . $pid;
        } elseif ($kind === 'comment') {
            $url = '/pages/' . $pid . '#comment-' . $r['entity_uuid'];
            $title = ($title !== '' ? $title : 'Untitled') . ' — comment';
        } else {
            $url = '/pages/' . $pid;
        }
        if ($title === '') { $title = 'Untitled'; }
    } else {
        $dm = $chan !== null && in_array($chan['kind'], ['dm', 'group_dm'], true);
        $where[] = $chan === null ? 'a conversation' : ($chan['name'] !== null && !$dm ? '#' . $chan['name'] : 'a direct message');
        $url = ($dm ? '/dm/' : '/channels/') . (int) $r['channel_id'] . '?message=' . (int) $r['entity_id'];
        $title = $where[0];
    }
    return ['kind' => $kind, 'title' => $title, 'excerpt_html' => search_excerpt_html($r['excerpt']), 'excerpt' => (string) $r['excerpt'], 'url' => $url, 'where' => $where,
            'who' => $r['author_member_id'] === null ? null : ['member_id' => $r['author_member_id'], 'display_name' => $ctx['members'][$r['author_member_id']] ?? null],
            'at' => $r['occurred_at'], 'page_id' => $pid, 'message_id' => $kind === 'message' ? $r['entity_id'] : null, 'channel_id' => $r['channel_id'], 'space_id' => $r['space_id'],
            'comment_id' => $kind === 'comment' ? $r['entity_uuid'] : null];
}

/** The JSON of a result (a whitelist: no internal rank). `excerpt` keeps the <mark> around the match, as sp_search() made it. */
function present_search_json(array $p): array
{
    return ['kind' => $p['kind'], 'title' => $p['title'], 'excerpt' => $p['excerpt'], 'url' => $p['url'], 'where' => $p['where'],
            'who' => $p['who'], 'at' => json_ts($p['at']), 'page_id' => $p['page_id'], 'message_id' => $p['message_id'], 'channel_id' => $p['channel_id'], 'space_id' => $p['space_id'], 'comment_id' => $p['comment_id']];
}

/** The URL of a parsed query: `q` the words and each modifier as its own param (what the chips and the pager link to); $drop names the modifiers (or 'q') left out, $extra adds params. */
function search_url(array $p, array $drop = [], array $extra = []): string
{
    $args = [];
    if (($p['q'] ?? '') !== '' && !in_array('q', $drop, true)) { $args['q'] = $p['q']; }
    foreach (SEARCH_MODIFIERS as $mod) {
        if (($p[$mod] ?? null) !== null && !in_array($mod, $drop, true)) { $args[$mod] = $p[$mod]; }
    }
    $args = $extra + $args;
    return '/search' . ($args === [] ? '' : '?' . http_build_query($args));
}

const SEARCH_GROUPS = ['page' => ['Pages', 'feather-file-text'], 'row' => ['Rows', 'feather-database'], 'message' => ['Messages', 'feather-message-square'], 'comment' => ['Comments', 'feather-message-circle']];
