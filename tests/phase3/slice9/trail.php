<?php
/** Proof — The trail (spec "Proof", 5): my rows; a page's history by `page=` (uuid), a channel's by `channel=`; the filters; every manifest event has a sentence (a fixture row per event; none says "unknown"). */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/activity/present.php';
$w = home_world();
$run = run_id();
$owner = as_member(1); $marco = as_member(27); $priya = as_member(26); $dana = as_member(30);
$product = $w['product'];
$trail = function (string $jar, string $qs = '') { $r = req('GET', '/trail' . ($qs === '' ? '' : '?' . $qs), ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? []]; };

echo "1. My rows\n";
$pg = mk_in($marco, $product, "SMOKE trail page $run");
act($marco, '/blocks/append.php', ['page' => $pg, 'markdown' => 'Some words for the trail.']);
act($marco, '/pages/save.php', ['page' => $pg, 'title' => "SMOKE trail page renamed $run"]);
act($priya, '/pages/comments/add.php', ['page' => $pg, 'markdown' => 'SMOKE a comment for the trail']);
post($marco, $w['launch'], "SMOKE trail message $run");
[$c, $d] = $trail($marco);
ok($c === 200 && count($d['rows']) >= 4 && array_filter($d['rows'], fn ($r) => $r['actor']['member_id'] !== 27) === [], 'Marco\'s own trail: his rows and only his (' . count($d['rows']) . ')');
ok($d['rows'][0]['activity_id'] > $d['rows'][1]['activity_id'], 'newest first');
$acts = array_column($d['rows'], 'action');
ok(in_array('page.create', $acts, true) && in_array('message.post', $acts, true), 'page.create and message.post are among them');
$sent = array_column($d['rows'], 'sentence', 'action');
ok(str_contains($sent['page.create'] ?? '', 'created a page') && str_contains($sent['page.create'] ?? '', 'SMOKE Marco'), 'a sentence: "' . ($sent['page.create'] ?? '') . '"');
$html = req('GET', '/trail', ['jar' => $marco])['body'];
ok(str_contains($html, 'id="trail-results"') && str_contains($html, 'id="trail-table"') && str_contains($html, 'id="trail-filters"') && str_contains($html, 'created a page'), 'the screen: the results region, the filters, the sentences');
[$c, $dp] = $trail($priya);
ok(array_filter($dp['rows'], fn ($r) => $r['actor']['member_id'] !== 26) === [], 'Priya\'s own trail is hers, not Marco\'s');

echo "2. A page's history by page=\n";
[$c, $d] = $trail($priya, 'page=' . $pg);
$pa = array_column($d['rows'], 'action');
ok($c === 200 && in_array('page.create', $pa, true) && in_array('page.update', $pa, true), 'the page\'s history, as Priya (she may see the page): create, update: ' . implode(',', $pa));
ok(array_unique(array_column(array_column($d['rows'], 'actor'), 'member_id')) !== [26] && array_filter($d['rows'], fn ($r) => $r['actor']['member_id'] === 27) !== [], 'it holds other people\'s rows too (Marco\'s), not just hers');
$html = req('GET', '/trail?page=' . $pg, ['jar' => $priya])['body'];
ok(str_contains($html, 'The page&#039;s history') || str_contains($html, "The page's history"), 'the screen says it is the page\'s history');
ok(str_contains($html, 'href="/pages/' . $pg . '?back='), 'each row leads to its record');
[$c, $d] = $trail($priya, 'page=' . $w['hidden']);
ok($c === 200 && $d['rows'] === [], 'a page she may not see: its history is empty (mcp_activity_log decides)');
[$c, $d] = $trail($marco, 'page=' . $w['hidden']);
ok(count($d['rows']) >= 1, 'for Marco, who may, it is not');
[$c, $d] = $trail($priya, 'page=not-a-uuid');
ok($c === 200 && array_filter($d['rows'], fn ($r) => $r['actor']['member_id'] !== 26) === [], 'a bad page id falls back to her own rows');

echo "3. A channel's history by channel=\n";
[$c, $d] = $trail($priya, 'channel=' . $w['launch']);
$ca = array_column($d['rows'], 'action');
ok($c === 200 && in_array('message.post', $ca, true) && array_filter($d['rows'], fn ($r) => $r['channel_id'] !== null && $r['channel_id'] !== $w['launch']) === [], 'the launch channel\'s history: messages, only that channel\'s');
$html = req('GET', '/trail?channel=' . $w['launch'], ['jar' => $priya])['body'];
ok(preg_match('#href="/channels/' . $w['launch'] . '\?message=\d+&amp;back=#', $html) === 1, 'a message row links to the message in its channel');
[$c, $d] = $trail($dana, 'channel=' . $w['leads_channel']);
ok($c === 200 && array_filter($d['rows'], fn ($r) => $r['actor']['member_id'] !== 30) === [], 'a private channel she is not in: nothing of it (only her own rows, if any)');
[$c, $d] = $trail($owner, 'channel=' . $w['leads_channel']);
ok(count($d['rows']) >= 1, 'the admin sees it');
[$c, $d] = $trail($marco, 'space=' . $product);
ok(array_filter($d['rows'], fn ($r) => $r['space_id'] !== null && $r['space_id'] !== $product) === [] && count($d['rows']) >= 1, 'a space\'s history by space=');

echo "4. The filters\n";
[$c, $d] = $trail($marco, 'action=page.');
ok($c === 200 && count($d['rows']) >= 2 && array_filter($d['rows'], fn ($r) => !str_starts_with($r['action'], 'page.')) === [], 'action=page. : only page events');
[$c, $d] = $trail($marco, 'action=message.post');
ok(count($d['rows']) >= 1 && array_filter($d['rows'], fn ($r) => $r['action'] !== 'message.post') === [], 'an exact event');
[$c, $d] = $trail($marco, 'action=' . rawurlencode("x' OR 1=1 --"));
ok($c === 200 && count($d['rows']) >= 1, 'a hostile action filter is ignored, not run');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, occurred_at) VALUES (27, 'web', 'page.view', 'page', 1, now() - interval '40 days'), (27, 'web', 'page.view', 'page', 1, now() - interval '8 days'), (27, 'web', 'page.view', 'page', 1, now() - interval '2 hours')");
$counts = [];
foreach ([1, 7, 30, 90] as $p) { [, $d] = $trail($marco, "action=page.view&period=$p"); $counts[$p] = count($d['rows']); }
[, $all] = $trail($marco, 'action=page.view');
ok($counts[1] < $counts[7] || $counts[1] === $counts[7], 'period 1 day');
ok($counts[1] >= 1 && $counts[7] > $counts[1] - 1 && $counts[30] >= $counts[7] + 1 && $counts[90] >= $counts[30] && count($all['rows']) >= $counts[90], 'the periods widen: ' . json_encode($counts) . ', all ' . count($all['rows']));
[, $d] = $trail($marco, 'action=page.view&period=999');
ok(count($d['rows']) === count($all['rows']), 'a period that is not offered is ignored');
$html = req('GET', '/trail?action=page.&period=7', ['jar' => $marco])['body'];
ok(str_contains($html, 'id="trail-filter-action"') && str_contains($html, 'id="trail-filter-period"') && preg_match('/<option value="page\." selected/', $html) === 1 && preg_match('/<option value="7" selected/', $html) === 1, 'the filter controls keep their values');
$r = req('GET', '/trail?action=page.', ['jar' => $marco, 'headers' => ['HX-Request: true', 'HX-Target: trail-results']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div id="trail-results"') && !str_contains($r['body'], 'id="trail-filters"'), 'an HTMX filter change swaps only #trail-results');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id) SELECT 30, 'web', 'page.view', 'page', g FROM generate_series(1, 60) g");
[, $p1] = $trail($dana, 'action=page.view');
[, $p2] = $trail($dana, 'action=page.view&page_no=2');
ok(count($p1['rows']) === 50 && $p1['more'] === true && count($p2['rows']) >= 10 && array_intersect(array_column($p1['rows'], 'activity_id'), array_column($p2['rows'], 'activity_id')) === [], 'fifty a page, a second page, no overlap');
$html = req('GET', '/trail?action=page.view', ['jar' => $dana])['body'];
ok(str_contains($html, 'id="trail-pagination"') && str_contains($html, 'Older'), 'the screen offers Older');

echo "5. Every event has a sentence\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$events = [];
foreach ($reg['actions'] as $a) { if (!empty($a['log_event'])) { $events[$a['log_event']] = true; } }
foreach (['screen.view', 'worker.pass', 'page.public_view', 'share.read', 'member.sign_on', 'member.sign_out', 'directory.sync', 'agent.dispatch', 'agent.reply', 'agent.fail', 'attachment.add', 'export.download', 'notification.send', 'librarian.accept', 'librarian.propose', 'librarian.dismiss', 'token.mint', 'token.revoke', 'prefs.save', 'thread.reply', 'comment.add', 'import.file_expire', 'space.admin_view'] as $e) { $events[$e] = true; }
$events = array_keys($events);
ok(count($events) >= 110, 'the manifest and the code log ' . count($events) . ' events');
$missing = array_values(array_filter($events, fn ($e) => activity_event_words($e) === null));
ok($missing === [], 'every event has its own sentence' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
as_postgres("DELETE FROM activity_log WHERE entity_type = 'smoke_event'");
foreach ($events as $e) { pdo()->prepare("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, after) VALUES (30, 'web', :a, 'smoke_event', 1, '{\"title\": \"SMOKE x\"}')")->execute(['a' => $e]); }
$seen = [];
for ($p = 1; $p <= 6; $p++) {
    [, $d] = $trail($dana, "period=1&page_no=$p");
    foreach ($d['rows'] as $r) { if ($r['entity_type'] === 'smoke_event') { $seen[$r['action']] = $r['sentence']; } }
    if (!$d['more']) { break; }
}
$unknown = array_filter($seen, fn ($s, $a) => str_contains(strtolower($s), 'unknown') || $s === 'SMOKE Dana ' . str_replace(['.', '_'], ' ', $a) . ($a === 'screen.view' ? '' : ': SMOKE x'), ARRAY_FILTER_USE_BOTH);
ok(count($seen) === count($events) && $unknown === [], 'a fixture row per event came back through the screen (' . count($seen) . '/' . count($events) . '), none "unknown" or left as the bare event name' . ($unknown ? ': ' . json_encode(array_slice($unknown, 0, 3)) : ''));
ok(str_contains($seen['settings.save'] ?? '', 'changed the workspace settings') && str_contains($seen['space.admin_view'] ?? '', 'opened a private space as admin') && str_contains($seen['trash.purge'] ?? '', 'emptied the trash'), 'sample sentences read well: ' . ($seen['trash.purge'] ?? ''));
$ins = pdo()->prepare("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_uuid, agent_run_id, after) VALUES (:a, :s, :act, 'smoke_event2', CAST(:u AS uuid), :r, CAST(:j AS jsonb))");
$ins->execute(['a' => 40, 's' => 'agent', 'act' => 'page.update', 'u' => $pg, 'r' => 4801, 'j' => json_encode(['title' => 'SMOKE by an agent'])]);
$ins->execute(['a' => null, 's' => 'cron', 'act' => 'page.delete', 'u' => $pg, 'r' => null, 'j' => json_encode(['via' => 'trash'])]);
[, $d] = $trail($priya, 'page=' . $pg);
$rowsBy = array_column($d['rows'], 'sentence', 'source');
ok(array_filter($d['rows'], fn ($r) => $r['actor']['is_agent'] && str_contains($r['sentence'], '(agent)')) !== [], 'an agent\'s row says "(agent)": ' . ($rowsBy['agent'] ?? ''));
ok(array_filter($d['rows'], fn ($r) => $r['source'] === 'cron' && str_starts_with($r['sentence'], 'The worker deleted a page for good')) !== [], 'a cron row says "The worker deleted a page for good": ' . ($rowsBy['cron'] ?? ''));
ok(activity_sentence(['actor_name' => null, 'actor_is_agent' => false, 'source' => 'cron', 'action' => 'worker.pass', 'after' => null]) === 'The worker ran a pass', 'and a worker pass reads "The worker ran a pass"');
$html = req('GET', '/trail?page=' . $pg, ['jar' => $priya])['body'];
ok(str_contains($html, 'bg-soft-info text-info">agent') && str_contains($html, 'run #4801') && str_contains($html, 'bg-soft-warning text-warning">cron'), 'the source column chips the agent (with its run) and the worker');
as_postgres("DELETE FROM activity_log WHERE entity_type IN ('smoke_event', 'smoke_event2')");
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'trail'") >= 1, 'the trail logs screen.view');
finish();
