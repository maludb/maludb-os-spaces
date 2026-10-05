<?php
declare(strict_types=1);

/**
 * The one search (slice 6): sp_search() over the visible sets (it answers as the caller — a result the caller may not see never appears). PHP only names what the person typed:
 * a channel (`in:#ops`), a person (`from:@priya`, `@me`), a space. A name that matches nothing is told in words and finds nothing (never "everything").
 */
require_once __DIR__ . '/parse.php';

const SEARCH_PAGE = 25;

/**
 * The parsed query as sp_search()'s filters object, with the names resolved through the views. Answers ['filters' => …, 'notices' => […], 'dead' => bool] — dead: a name that names
 * nobody the caller may see, so nothing can match.
 */
function resolve_search_filters(PDO $pdo, array $p, int $me): array
{
    $f = [];
    $notices = $p['notices'] ?? [];
    $dead = false;
    if (($p['in'] ?? null) !== null) {
        $in = ltrim((string) $p['in'], '#');
        if (ctype_digit($in)) {
            $f['in'] = (int) $in;
        } else {
            $id = one_value($pdo, 'SELECT channel_id FROM mcp_channels WHERE name = :n ORDER BY i_follow DESC, channel_id LIMIT 1', ['n' => $in]);
            if ($id === null) { $notices[] = 'No channel called #' . mb_substr($in, 0, 40) . ' that you can read.'; $dead = true; } else { $f['in'] = (int) $id; }
        }
    }
    if (($p['from'] ?? null) !== null) {
        $h = ltrim((string) $p['from'], '@');
        if (strtolower($h) === 'me') {
            $f['from'] = $me;
        } elseif (ctype_digit($h)) {
            $f['from'] = (int) $h;
        } else {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($h));          // the handle as a LIKE pattern: a name, or any word of a name, begins with it
            $id = one_value($pdo, "SELECT member_id FROM mcp_members WHERE lower(display_name) = :h OR lower(display_name) LIKE :p1 OR lower(display_name) LIKE :p2
                                    ORDER BY (lower(display_name) = :h2) DESC, is_agent, lower(display_name) LIMIT 1", ['h' => strtolower($h), 'h2' => strtolower($h), 'p1' => $like . '%', 'p2' => '% ' . $like . '%']);
            if ($id === null) { $notices[] = 'Nobody called @' . mb_substr($h, 0, 40) . ' that you can see.'; $dead = true; } else { $f['from'] = (int) $id; }
        }
    }
    if (($p['space'] ?? null) !== null) {
        $s = (string) $p['space'];
        $id = ctype_digit($s) ? one_value($pdo, 'SELECT space_id FROM mcp_spaces WHERE space_id = :s', ['s' => (int) $s])
                              : one_value($pdo, 'SELECT space_id FROM mcp_spaces WHERE lower(name) = lower(:n) OR slug = lower(:n2) ORDER BY space_id LIMIT 1', ['n' => $s, 'n2' => $s]);
        if ($id === null) { $notices[] = 'No space called ' . mb_substr($s, 0, 40) . ' that you can see.'; $dead = true; } else { $f['space'] = (int) $id; }
    }
    foreach (['has', 'before', 'after', 'is'] as $k) {
        if (($p[$k] ?? null) !== null) { $f[$k] = (string) $p[$k]; }
    }
    return ['filters' => $f, 'notices' => $notices, 'dead' => $dead];
}

/**
 * sp_search() for a page of 25: ['rows' => [kind => [row…]], 'flat' => […], 'total' => N]. $f is resolve_search_filters()['filters']; $q the words. Rows keep sp_search()'s order (rank, then recency)
 * and are grouped by kind for the screen.
 */
function search(PDO $pdo, array $f, int $page, string $q = ''): array
{
    $st = $pdo->prepare('SELECT entity_kind, entity_uuid::text AS entity_uuid, entity_id, page_id::text AS page_id, space_id, channel_id, author_member_id, title, excerpt, occurred_at, rank, total
                           FROM sp_search(:q, CAST(:f AS jsonb), :l, :o)');
    $st->execute(['q' => $q, 'f' => json_encode((object) $f), 'l' => SEARCH_PAGE, 'o' => (max(1, $page) - 1) * SEARCH_PAGE]);
    $flat = $st->fetchAll();
    $total = $flat === [] ? 0 : (int) $flat[0]['total'];
    $grouped = ['page' => [], 'row' => [], 'message' => [], 'comment' => []];
    foreach ($flat as &$r) {
        $r['entity_id'] = $r['entity_id'] === null ? null : (int) $r['entity_id'];
        foreach (['space_id', 'channel_id', 'author_member_id'] as $k) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; }
        $grouped[$r['entity_kind']][] = $r;
    }
    unset($r);
    return ['rows' => $grouped, 'flat' => $flat, 'total' => $total];
}

/** What a result's presenter needs about its page, channel and author, fetched once for the whole page: ['pages' => id → row, 'channels' => id → row, 'members' => id → name, 'spaces' => id → name, 'crumbs' => id → [titles]]. */
function search_context(PDO $pdo, array $flat): array
{
    $pageIds = array_values(array_unique(array_filter(array_column($flat, 'page_id'))));
    $chanIds = array_values(array_unique(array_filter(array_column($flat, 'channel_id'))));
    $memIds = array_values(array_unique(array_filter(array_column($flat, 'author_member_id'))));
    $ctx = ['pages' => [], 'channels' => [], 'members' => [], 'spaces' => [], 'crumbs' => []];
    if ($pageIds !== []) {
        $st = $pdo->prepare('SELECT page_id::text AS page_id, plain_title, kind, space_id, parent_database_id::text AS parent_database_id, icon FROM mcp_pages WHERE page_id = ANY (CAST(:ids AS uuid[]))');
        $st->execute(['ids' => pg_array_literal($pageIds)]);
        foreach ($st->fetchAll() as $p) { $ctx['pages'][$p['page_id']] = $p; }
        $st = $pdo->prepare('SELECT x.page_id::text AS pid, a.plain_title FROM unnest(CAST(:ids AS uuid[])) AS x(page_id), LATERAL sp_page_ancestors(x.page_id) a ORDER BY x.page_id, a.depth DESC');
        $st->execute(['ids' => pg_array_literal($pageIds)]);
        foreach ($st->fetchAll() as $a) { $ctx['crumbs'][$a['pid']][] = $a['plain_title']; }
    }
    if ($chanIds !== []) {
        $st = $pdo->prepare('SELECT channel_id, kind, name, space_id FROM mcp_channels WHERE channel_id = ANY (CAST(:ids AS bigint[]))');
        $st->execute(['ids' => pg_array_literal(array_map('intval', $chanIds))]);
        foreach ($st->fetchAll() as $c) { $ctx['channels'][(int) $c['channel_id']] = $c; }
    }
    if ($memIds !== []) {
        $st = $pdo->prepare('SELECT member_id, display_name FROM mcp_members WHERE member_id = ANY (CAST(:ids AS bigint[]))');
        $st->execute(['ids' => pg_array_literal(array_map('intval', $memIds))]);
        foreach ($st->fetchAll() as $m) { $ctx['members'][(int) $m['member_id']] = $m['display_name']; }
    }
    foreach ($pdo->query('SELECT space_id, name FROM mcp_spaces')->fetchAll() as $s) { $ctx['spaces'][(int) $s['space_id']] = $s['name']; }
    return $ctx;
}
