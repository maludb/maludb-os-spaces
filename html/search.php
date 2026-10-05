<?php
declare(strict_types=1);
/**
 * /search — the one search (screen `search`; params: q, in, from, has, before, after, is, space, page). `q` is what was typed — `rate limit in:#ops from:@priya has:link before:2026-09-01 is:page` — and
 * the selects send the same modifiers as their own params (a param wins over the same modifier typed in q). The Enter key searches; typing does not. Answers sp_search() as the caller: pages, rows,
 * messages, comments, grouped, 25 a page. An empty query with a modifier lists by recency; nothing at all shows the syntax. Logged: screen.view with the words (up to 200) and the modifiers used.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_once dirname(__DIR__) . '/app/features/search/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$parsed = parse_search_query(request_string('q'));
foreach (SEARCH_MODIFIERS as $mod) {                                     // the selects: a param is the same modifier, validated the same way
    $v = trim(request_string($mod));
    if ($v === '') { continue; }
    $again = parse_search_query($mod . ':' . (preg_match('/\s/', $v) ? '"' . $v . '"' : $v));
    $parsed['notices'] = array_merge($parsed['notices'], array_diff($again['notices'], $parsed['notices']));
    if ($again[$mod] !== null) { $parsed[$mod] = $again[$mod]; }
}
$page = max(1, (int) (request_integer('page') ?? 1));
$resolved = resolve_search_filters($pdo, $parsed, $me);
$result = ['rows' => ['page' => [], 'row' => [], 'message' => [], 'comment' => []], 'flat' => [], 'total' => 0];
$asked = !search_is_empty($parsed);
if ($asked && !$resolved['dead']) {
    $result = search($pdo, $resolved['filters'], $page, $parsed['q']);
}
$ctx = search_context($pdo, $result['flat']);
$groups = [];
foreach ($result['rows'] as $kind => $rows) {
    $groups[$kind] = array_map(static fn (array $r): array => present_search_row($ctx, $r), $rows);
}
if ((!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1')) {
    $used = array_values(array_filter(SEARCH_MODIFIERS, static fn (string $m): bool => $parsed[$m] !== null));
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'search', 'after' => ['q' => mb_substr($parsed['q'], 0, 200), 'filters' => $used]]);
}
$data = ['parsed' => $parsed, 'asked' => $asked, 'groups' => $groups, 'total' => $result['total'], 'page' => $page, 'notices' => $resolved['notices'], 'dead' => $resolved['dead'], 'tz' => member_timezone(), 'here' => here_url()];
if (wants_json()) {
    respond_screen(['q' => $parsed['q'], 'filters' => $resolved['filters'], 'notices' => $resolved['notices'], 'page' => $page, 'page_size' => SEARCH_PAGE, 'total' => $result['total'],
        'results' => array_map(static fn (array $g): array => array_map('present_search_json', $g), $groups)]);
}
$data['options'] = [
    'spaces' => $pdo->query('SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL ORDER BY lower(name) LIMIT 200')->fetchAll(),
    'channels' => $pdo->query("SELECT channel_id, name FROM mcp_channels WHERE kind IN ('public', 'private') AND archived_at IS NULL ORDER BY lower(name) LIMIT 300")->fetchAll(),
    'members' => $pdo->query('SELECT member_id, display_name FROM mcp_members ORDER BY is_agent, lower(display_name) LIMIT 300')->fetchAll(),
];
render_screen('Search', view('search/page.php', $data), ['activeNav' => 'search', 'screen' => 'search']);
