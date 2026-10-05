<?php
declare(strict_types=1);
/**
 * /trail — the activity trail the caller may see (screen `trail`; params: space, channel, message (ints), page (a UUID), action, period): their own rows,
 * or one record's history (mcp_activity_log decides what they may see of it; the slices link here). Pattern B on #trail-results. JSON: the rows with their sentences.
 * (/activity is the feed — mentions, replies, reactions to me — built by slice 6.)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_login();
$pdo = db();
$record = null;
foreach (['space', 'channel', 'message'] as $k) {
    if (($v = request_integer($k)) !== null) { $record = [$k, $v]; break; }
}
if ($record === null && is_uuid($_GET['page'] ?? null)) { $record = ['page', (string) $_GET['page']]; }
$filters = ['own' => $record === null, 'action' => request_string('action'), 'since' => request_integer('period') ?? request_integer('since')];
if ($record !== null) { $filters[$record[0]] = $record[1]; }
if (!preg_match('/^[a-z_]+(\.[a-z_]+)*\.?$/', $filters['action'])) { $filters['action'] = ''; }
if (!in_array($filters['since'], [1, 7, 30, 90], true)) { $filters['since'] = null; }
$page = max(1, (int) (request_integer('page_no') ?? 1));
$result = find_my_activity($pdo, (int) current_member_id(), $page, $filters);
$rows = $result['rows'];
foreach ($rows as &$r) { $r['sentence'] = activity_sentence($r); }
unset($r);
$query = array_filter([($record[0] ?? 'x') => $record[1] ?? null, 'action' => $filters['action'], 'period' => $filters['since']], static fn ($v) => $v !== null && $v !== '');
log_screen_view($pdo, 'trail');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_activity_row', $rows), 'page' => $page, 'more' => $result['more'], 'filters' => $query]);
}
$data = ['rows' => $rows, 'page' => $page, 'more' => $result['more'], 'filters' => $filters, 'query' => $query, 'tz' => member_timezone(), 'record' => $record];
$resultsHtml = view('trail/partials/rows.php', $data);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'trail-results') {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
render_screen('My trail', view('trail/page.php', $data + ['resultsHtml' => $resultsHtml]), ['activeNav' => 'trail', 'screen' => 'trail', 'entity' => $record !== null ? $record[0] : '', 'recordId' => $record !== null ? (string) $record[1] : '']);
