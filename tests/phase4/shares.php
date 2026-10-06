<?php
/**
 * Proof — the two shares[] as TOOLS (design §8; spec docs/build-specs/mcp-servers.md): pages_index (os.spaces-pages/1) and page_markdown (os.spaces-page/1), called through the records server as the KERNEL's token (flat arguments, as the kernel sends them,
 * the agent named in as_agent; `share.read` logged once a call) and as a PERSON (their own world). One truth, two doors: every document equals, row for row, what the PHP function (app/features/shares/queries.php, called by the internal door
 * html/api/v1/shares/read.php) answers for the same member. The door itself: signed, or refused. The documents are people-free.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
q("UPDATE members SET capability = 'write' WHERE id = 41");                  // the Watcher: an agent, admitted, in no space
/** The PHP function, as a subprocess: ['doc' => …] | ['refused' => …] | ['error' => …]. */
function php_share(string $fn, int $member, array $args): array
{
    $out = trim((string) shell_exec('php ' . escapeshellarg(__DIR__ . '/share_call.php') . ' ' . escapeshellarg($fn) . ' ' . $member . ' ' . escapeshellarg(json_encode($args)) . ' 2>&1'));
    $line = '';
    foreach (explode("\n", $out) as $l) { if (str_starts_with(ltrim($l), '{')) { $line = $l; } }
    return json_decode($line, true) ?? ['error' => 'no answer: ' . $out];
}
function bare(?array $doc): ?array { if ($doc === null) { return null; } unset($doc['generated_at']); return $doc; }
/** The kernel's call: an `as_agent` in $args is sent the way the kernel sends it since K26 — as the header X-OS-Consumer-Agent, with X-OS-Consumer naming a consumer. */
function kcall(string $tool, array $args): array
{
    $headers = ['X-OS-Consumer: smoke_consumer'];
    if (array_key_exists('as_agent', $args)) { $headers[] = 'X-OS-Consumer-Agent: ' . $args['as_agent']; unset($args['as_agent']); }
    return mcp_tool(REC, ktoken(), $tool, $args, true, $headers);
}
function share_log_rows(int $since): array { return q("SELECT * FROM activity_log WHERE action = 'share.read' AND id > :s ORDER BY id", ['s' => $since]); }
/** A request to the internal door, signed as the records server signs it (or not). */
function door(array $body, bool $sign = true, ?int $time = null, string $method = 'POST'): array
{
    $json = json_encode($body);
    $ts = (string) ($time ?? time());
    $h = ['Content-Type: application/json'];
    if ($sign) { $h[] = 'X-Share-Time: ' . $ts; $h[] = 'X-Share-Signature: ' . hash_hmac('sha256', 'share:' . $ts . ':' . hash('sha256', $json), need('ACTIONS_RELAY_KEY')); }
    $r = req($method, '/api/v1/shares/read.php', ['headers' => $h, 'raw' => $json]);
    return [$r['code'], json_decode($r['body'], true) ?? []];
}

echo "1. The manifest, the surface and the server agree on the two\n";
$spec = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
$tools = array_column($spec['shares'], 'tool'); sort($tools);
ok($tools === $S['shares'] && count($tools) === 2 && $spec['reads'] === [], 'maludb-os.json shares[] = pages_index and page_markdown (reads[] empty in v1)');
ok(count(array_diff($S['shares'], mcp_tools(REC, ktoken()) ?? [])) === 0, 'the kernel is offered both');
ok(count(array_diff($S['shares'], mcp_tools(REC, ptoken(26)) ?? [])) === 0, 'a person is offered them too (they read as themselves)');

echo "2. As the kernel: pages_index is the PHP function's, row for row\n";
$last = (int) one('SELECT COALESCE(max(id), 0) FROM activity_log');
$k = kcall('pages_index', ['q' => 'runbook', 'as_agent' => 40]);
ok(!$k['error'] && ($k['data']['schema'] ?? '') === 'os.spaces-pages/1' && $k['data']['application'] === 'spaces' && $k['data']['as_member'] === 40 && preg_match('/^\d{4}-\d\d-\d\dT[\d:]+Z$/', $k['data']['generated_at']) === 1, 'flat arguments, the agent named: os.spaces-pages/1, application spaces, as_member 40 (Seamus)');
$php = php_share('share_pages_index', 40, ['runbook', 25, 0]);
ok(isset($php['doc']) && bare($k['data']) === bare($php['doc']), 'the tool\'s document is the PHP function\'s, field for field');
$rows = $k['data']['rows'];
ok(count($rows) === 1 && $rows[0]['title'] === 'SMOKE Runbook' && $rows[0]['kind'] === 'page' && $rows[0]['space'] === ['space_id' => $w['product'], 'name' => 'SMOKE Product'] && $rows[0]['path'] === [] && $rows[0]['is_wiki'] === true && array_keys($rows[0]) === ['page_id', 'title', 'kind', 'space', 'path', 'last_edited_at', 'verification_state', 'is_wiki'], 'a row: page_id, title, kind, space, path (breadcrumb), last_edited_at, verification_state, is_wiki — nothing of the body');
$all = kcall('pages_index', ['as_agent' => 40, 'limit' => 100])['data'];
$vis = sql_as(40, "SELECT count(*) AS n FROM mcp_pages WHERE archived_at IS NULL AND NOT is_template");
ok($all['total'] === (int) $vis[0]['n'] && $all['total'] >= 15 && $all['next_cursor'] === null, 'with no words: every page the agent may see and no other (' . $all['total'] . ' = the view\'s count for member 40)');
$sub = find_row($all['rows'], 'page_id', $w['pricing']);
ok($sub['path'] === ['SMOKE Product handbook'] && find_row($all['rows'], 'page_id', $w['fix'])['kind'] === 'row' && find_row($all['rows'], 'page_id', $w['tasks'])['kind'] === 'database', 'a subpage carries its breadcrumb; a database row is kind row, a database kind database');
ok(count(array_filter($all['rows'], fn ($r) => str_contains(json_encode($r), 'template'))) === 0 && find_row($all['rows'], 'page_id', $w['target_id']) === null, 'templates and the trash are not indexed');
$p1 = kcall('pages_index', ['as_agent' => 40, 'limit' => 5])['data'];
$p2 = kcall('pages_index', ['as_agent' => 40, 'limit' => 5, 'cursor' => $p1['next_cursor']])['data'];
ok(count($p1['rows']) === 5 && $p1['next_cursor'] === '5' && count($p2['rows']) === 5 && count(array_intersect(array_column($p1['rows'], 'page_id'), array_column($p2['rows'], 'page_id'))) === 0 && $p1['total'] === $all['total'], 'paged by cursor: five and the next five, no overlap');
ok($p1['rows'][0]['last_edited_at'] >= $p1['rows'][4]['last_edited_at'], 'newest edit first');
$w41 = kcall('pages_index', ['as_agent' => 41, 'limit' => 100])['data'];
ok($w41['as_member'] === 41 && $w41['total'] < $all['total'] && find_row($w41['rows'], 'page_id', $w['runbook']) === null && find_row($w41['rows'], 'page_id', $w['handbook']) === null, 'another agent, another world: the Watcher is in no space and sees none of Product\'s pages (' . $w41['total'] . ')');
$none = kcall('pages_index', ['q' => 'runbook']);
ok(!$none['error'] && $none['data']['rows'] === [] && $none['data']['total'] === 0 && $none['data']['as_member'] === null && str_contains($none['data']['note'], 'No requesting expert agent'), 'no agent named: the index is EMPTY (an application with no expert reads nothing), with a note');
foreach ([[26, 'a person'], [9999, 'a member that does not exist'], [41, null]] as [$a, $what]) {
    if ($what === null) { q("UPDATE members SET capability = NULL WHERE id = 41"); $what = 'an agent the directory has not admitted'; }
    $r = kcall('pages_index', ['q' => 'x', 'as_agent' => $a]);
    ok($r['error'] && str_contains($r['text'], 'not a member here'), "as_agent naming $what: refused, in words (" . substr($r['text'], 0, 80) . ')');
}
q("UPDATE members SET capability = 'write' WHERE id = 41");
$r = kcall('pages_index', ['q' => 'x', 'as_agent' => 'abc']);
ok(!$r['error'] && $r['data']['as_member'] === null && $r['data']['total'] === 0, 'an X-OS-Consumer-Agent that is not a member id is ignored by the gate: no agent, the index empty');
ok(!has_key($all, 'author') && !has_key($all, 'author_member_id') && !has_key($all, 'email') && !has_key($all, 'last_edited_by') && !str_contains(json_encode($all), 'member_id'), 'people-free: no author, no editor, no id of a person (the only member id is the acting agent\'s own, as_member)');

echo "3. As the kernel: page_markdown\n";
$last2 = (int) one('SELECT COALESCE(max(id), 0) FROM activity_log');
$pm = kcall('page_markdown', ['page' => $w['runbook'], 'as_agent' => 40]);
$phpm = php_share('share_page_markdown', 40, [$w['runbook']]);
ok(!$pm['error'] && ($pm['data']['schema'] ?? '') === 'os.spaces-page/1' && bare($pm['data']) === bare($phpm['doc']), 'os.spaces-page/1, and the same document as the PHP function');
$pg = $pm['data']['page'];
ok($pg['title'] === 'SMOKE Runbook' && $pg['kind'] === 'page' && $pg['path'] === [] && str_contains($pg['markdown'], '# Runbook heading') && $pg['properties'] === null && $pg['verification_state'] === 'none', 'the page as Markdown with its breadcrumb, verification and last edit');
ok($pg['markdown'] === val_as(40, "SELECT sp_page_markdown(CAST('{$w['runbook']}' AS uuid), false)"), 'the Markdown is sp_page_markdown\'s, as that agent reads it');
$row = kcall('page_markdown', ['page' => $w['fix'], 'as_agent' => 40])['data']['page'];
ok($row['kind'] === 'row' && $row['properties']['status']['name'] === 'Done' && $row['properties']['owner'] === [['name' => 'SMOKE Priya', 'is_agent' => false]] && $row['properties']['title'] === 'SMOKE Fix the login' && $row['path'] === ['SMOKE Tasks'], 'a database row: its properties as text and data — a person only by NAME (no id), the breadcrumb the database');
ok(str_contains(json_encode($row['properties']), '"id"') === false || count(array_filter(array_keys($row['properties']), fn ($x) => $x === 'id')) === 0, 'no `id` beside a name in a property value');
$rf = kcall('page_markdown', ['page' => $w['handbook'], 'as_agent' => 41]);
ok($rf['error'] && str_contains($rf['text'], 'No page that agent can see'), 'a page the agent may not see is refused in words: ' . substr($rf['text'], 0, 80));
$ru = kcall('page_markdown', ['page' => '11111111-2222-3333-4444-555555555555', 'as_agent' => 40]);
ok($ru['error'] && str_contains($ru['text'], 'No page that agent can see'), 'an unknown page: the same sentence');
ok(kcall('page_markdown', ['page' => 'nonsense', 'as_agent' => 40])['error'] && kcall('page_markdown', ['page' => $w['runbook']])['error'], 'a bad id, and no agent named, are refused');
$tr = kcall('page_markdown', ['page' => $w['target_id'], 'as_agent' => 40]);
ok($tr['error'], 'a page in the trash is not readable');
$logs = share_log_rows($last);
$mine = array_values(array_filter($logs, fn ($l) => ($l['source'] ?? '') === 'application'));
ok(count($mine) >= 8 && count(array_filter($mine, fn ($l) => $l['actor_member_id'] !== null)) === 0, 'every kernel call logged `share.read` (source application, no actor): ' . count($mine) . ' rows');
$l0 = json_decode($mine[0]['after'], true);
ok($l0['tool'] === 'pages_index' && $l0['direction'] === 'in' && $l0['keys'] === ['q'] && $l0['rows'] === 1 && $l0['agent_member_id'] === 40, 'a row says the tool, the argument KEYS given, the rows answered and the agent it ran as');
ok(!str_contains(json_encode($logs), 'Runbook') && !str_contains(json_encode($logs), 'runbook'), 'never a title or a word searched in the trail');

echo "4. As a person: their own world, never another\n";
$pp = mcp_tool(REC, ptoken(26), 'pages_index', ['q' => 'runbook'])['data'];
$pa = mcp_tool(REC, ptoken(26), 'pages_index', ['limit' => 100])['data'];
ok($pp['as_member'] === 26 && count($pp['rows']) === 1 && $pa['total'] === (int) val_as(26, 'SELECT count(*) FROM mcp_pages WHERE archived_at IS NULL AND NOT is_template'), 'Priya reads as Priya: her pages (' . $pa['total'] . ')');
$pe = mcp_tool(REC, ptoken(26), 'pages_index', ['limit' => 100, 'as_agent' => 41])['data'];
ok($pe['as_member'] === 26 && $pe['total'] === $pa['total'], 'a person naming as_agent changes nothing: it is the kernel\'s argument, never theirs');
$pg2 = mcp_tool(REC, ptoken(29), 'pages_index', ['limit' => 100])['data'];
ok($pg2['total'] >= 1 && count(array_diff(array_column(array_filter($pg2['rows'], fn ($r) => true), 'page_id'), [$w['runbook']])) === 0, 'the guest\'s index holds only the page shared with her');
ok(mcp_tool(REC, ptoken(29), 'page_markdown', ['page' => $w['runbook']])['data']['page']['title'] === 'SMOKE Runbook' && mcp_tool(REC, ptoken(29), 'page_markdown', ['page' => $w['handbook']])['error'], 'the guest reads the Runbook and is refused the handbook');
$rid = random_int(700000, 799999);
facts($rid, 40, ['Records MCP' => ['pages_index' => [], 'page_markdown' => []]]);
$ag = mcp_tool(REC, rtoken(40, $rid), 'pages_index', ['q' => 'runbook']);
ok(!$ag['error'] && $ag['data']['as_member'] === 40 && count($ag['data']['rows']) === 1, 'an agent granted the share reads it as itself');

echo "5. The door: signed or refused\n";
$body = ['tool' => 'pages_index', 'caller' => 'person', 'member_id' => 26, 'arguments' => ['q' => 'runbook']];
[$c, $b] = door($body, false);
ok($c === 401 && $b['error'] === 'unauthorized', 'an unsigned request: 401');
[$c, $b] = door($body, true, time() - 400);
ok($c === 401, 'a signed request 400 seconds old: 401 (±120 s)');
[$c, $b] = door($body);
ok($c === 200 && $b['schema'] === 'os.spaces-pages/1' && $b['as_member'] === 26, 'a request signed now: 200');
[$c, $b] = door(['tool' => 'mystery', 'caller' => 'person', 'member_id' => 26, 'arguments' => []]);
ok($c === 422 && str_contains($b['error'], 'not one of the two'), 'another tool: 422 in words');
[$c, $b] = door(['tool' => 'pages_index', 'caller' => 'person', 'member_id' => 9999, 'arguments' => []]);
ok($c === 403 && str_contains($b['error'], 'not admitted'), 'a person that is not admitted: 403');
[$c, $b] = door(['tool' => 'pages_index', 'caller' => 'stranger', 'arguments' => []]);
ok($c === 422, 'a caller that is neither kernel nor person: 422');
[$c] = door($body, true, null, 'GET');
ok($c === 405, 'GET: 405');
$sv = mcp_tool(REC, ptoken(26), 'page_markdown', ['page' => 'x']);
ok($sv['error'], 'and a bad page id through the tool is a refusal, not a crash');
finish();
