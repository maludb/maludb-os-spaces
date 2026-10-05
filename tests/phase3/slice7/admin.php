<?php
/** Proof — the admin pages (spec "Proof", 8): agents, dispatches, connections; a Member is refused in words. */
require __DIR__ . '/lib.php';
$w = agents_world();
$priya = as_member(26); $owner = as_member(1); $marco = as_member(27);
$launch = $w['launch'];
// make sure there is something to see: one answered, one failed, one pending
kernel_chat(['reply' => 'Admin proof reply from the agent.']);
[$c, $b] = post($priya, $launch, '@SMOKE Seamus admin proof question');
$mq = (int) $b['record_id']; $dq = dispatch_id_of($mq, 40);
worker_pass();
kernel_chat(['mode' => 'error500']);
[$c, $b] = post($priya, $launch, '@SMOKE Librarian admin proof failure');
$mf = (int) $b['record_id']; $df = dispatch_id_of($mf, 42);
for ($i = 0; $i < 6; $i++) { make_due($df); worker_pass(); }
ok(dispatch_db($df)['status'] === 'failed', 'set-up: a dispatch of the Librarian failed for good');
kernel_chat(['reply' => 'x']);
[$c, $b] = post($priya, $launch, '@SMOKE Seamus a pending one');
$mp = (int) $b['record_id']; $dp = dispatch_id_of($mp, 40);

echo "1. Agents\n";
$r = page($owner, '/admin/agents');
ok($r['code'] === 200 && str_contains($r['body'], 'id="agent-row-40"') && str_contains($r['body'], 'id="agent-row-42"') && !str_contains($r['body'], 'id="agent-row-41"'), 'the agents that are members: Seamus and the Librarian, not the Watcher');
ok(str_contains($r['body'], 'id="agent-row-40-spaces"') && str_contains($r['body'], 'SMOKE Product') && str_contains($r['body'], 'General'), 'their spaces');
ok(str_contains($r['body'], 'id="agent-row-40-channels"') && str_contains($r['body'], 'smoke-launch'), 'their channels');
ok(str_contains($r['body'], 'id="agent-row-40-last"') && str_contains($r['body'], 'Admin proof reply from the agent.'), 'Seamus\'s last reply with its excerpt');
ok(preg_match('#id="agent-row-40-pending"[^>]*>pending <b>(\d+)</b>#', $r['body'], $m) && (int) $m[1] >= 1, 'Seamus has pending dispatches counted');
ok(preg_match('#id="agent-row-42-failed"[^>]*>failed <b>(\d+)</b>#', $r['body'], $m) && (int) $m[1] >= 1, 'the Librarian has a failed one counted');
ok(str_contains($r['body'], 'id="agent-list-note"') && str_contains($r['body'], 'app.example.invalid/agents') && str_contains($r['body'], 'Agent HR'), 'a note: hiring, grants and duties are the kernel\'s, with a link to Agent HR');
[$c, $js] = screen($owner, '/admin/agents');
$ag = array_column($js['agents'] ?? [], null, 'member_id');
ok($c === 200 && isset($ag[40], $ag[42]) && !isset($ag[41]) && $ag[40]['pending'] >= 1 && $ag[42]['failed'] >= 1 && $ag[40]['last_reply']['excerpt'] !== null && count($ag[40]['spaces']) >= 2, 'as JSON: the same facts');

echo "2. Dispatches\n";
$r = page($owner, '/admin/dispatches');
ok($r['code'] === 200 && str_contains($r['body'], 'id="dispatch-row-' . $dq . '"') && str_contains($r['body'], 'id="dispatch-row-' . $df . '"') && str_contains($r['body'], 'id="dispatch-row-' . $dp . '"'), 'every dispatch is a row');
ok(str_contains($r['body'], 'id="dispatch-row-' . $dq . '-status">answered') && str_contains($r['body'], 'id="dispatch-row-' . $df . '-status">failed') && str_contains($r['body'], 'id="dispatch-row-' . $dp . '-status">pending'), 'status chips: answered, failed, pending');
ok(str_contains($r['body'], 'id="dispatch-row-' . $dq . '-reply"') && str_contains($r['body'], 'Admin proof reply from the agent.') && str_contains($r['body'], 'admin proof question'), 'the reply\'s excerpt and the message\'s first line');
ok(str_contains($r['body'], 'id="dispatch-row-' . $df . '-detail"') && str_contains($r['body'], 'attempts 6') && str_contains($r['body'], 'id="dispatch-row-' . $df . '-retry-btn"') && !str_contains($r['body'], 'id="dispatch-row-' . $dq . '-retry-btn"'), 'attempts, the detail, Retry on the failed one only');
ok(str_contains($r['body'], 'id="dispatch-row-' . $dq . '-run"') && str_contains($r['body'], 'app.example.invalid/ai/runs/'), 'the run id links to the OS');
$r = page($owner, '/admin/dispatches?status=failed');
ok(str_contains($r['body'], 'id="dispatch-row-' . $df . '"') && !str_contains($r['body'], 'id="dispatch-row-' . $dq . '"'), 'filtered by status');
$r = page($owner, '/admin/dispatches?agent=42');
ok(str_contains($r['body'], 'id="dispatch-row-' . $df . '"') && !str_contains($r['body'], 'id="dispatch-row-' . $dq . '"'), 'filtered by agent');
$r = page($owner, '/admin/dispatches?status=pending&agent=40');
ok(str_contains($r['body'], 'id="dispatch-row-' . $dp . '"') && !str_contains($r['body'], 'id="dispatch-row-' . $df . '"'), 'both filters');
[$c, $js] = screen($owner, '/admin/dispatches?status=failed');
ok($c === 200 && $js['total'] >= 1 && $js['dispatches'][0]['status'] === 'failed' && $js['dispatches'][0]['may_retry'] === true && isset($js['dispatches'][0]['agent']['display_name']), 'as JSON: a failed one may be retried');
$r = req('GET', '/admin/dispatches?list=1&status=&agent=0&page=1&h=nope', ['jar' => $owner]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="dispatch-list"'), 'the poll (list=1) answers the rows when the hash differs');
preg_match('#h=([0-9a-f]{12})#', $r['body'], $hm);
$r = req('GET', '/admin/dispatches?list=1&status=&agent=&page=1&h=' . ($hm[1] ?? ''), ['jar' => $owner]);
ok($r['code'] === 204, 'and 204 when nothing changed');
$r = page($owner, '/admin/dispatches?page=9999');
ok($r['code'] === 200, 'a page past the end is the last page, not an error');

echo "3. Connections\n";
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, after) VALUES (NULL, 'application', 'share.read', 'application', '{\"application\": \"smoke-hr\", \"tool\": \"pages_index\", \"rows\": 12}')");
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, after) VALUES (NULL, 'application', 'share.read', 'application', '{\"application\": \"smoke-projects\", \"tool\": \"page_markdown\", \"rows\": 1}')");
$r = page($owner, '/admin/connections');
ok($r['code'] === 200 && str_contains($r['body'], 'id="share-pages_index"') && str_contains($r['body'], 'id="share-page_markdown"'), 'the two shares, from maludb-os.json');
ok(str_contains($r['body'], 'id="share-reads"') && str_contains($r['body'], 'smoke-hr') && str_contains($r['body'], 'pages_index') && str_contains($r['body'], '12 rows') && str_contains($r['body'], 'smoke-projects') && str_contains($r['body'], '1 row '), 'the share.read rows the fixture wrote: application, tool, rows');
ok(str_contains($r['body'], 'id="connection-list-note"') && str_contains($r['body'], 'super-admin approved') && str_contains($r['body'], 'app.example.invalid/applications'), 'a note: approving is the super-admin\'s in the OS, with a link');
[$c, $js] = screen($owner, '/admin/connections');
ok($c === 200 && count($js['shares']) === 2 && count($js['reads']) >= 2 && $js['reads'][0]['tool'] !== '', 'as JSON');

echo "4. A Member\n";
foreach (['/admin/agents', '/admin/dispatches', '/admin/connections'] as $path) {
    $r = page($priya, $path);
    ok($r['code'] === 403 && str_contains(html_entity_decode($r['body'], ENT_QUOTES), "You may not see the agents' settings."), "$path: a Member is refused in words");
    $r = req('GET', $path, ['jar' => $priya, 'headers' => JSONH]);
    ok($r['code'] === 403, "$path as JSON: 403");
}
$r = page($marco, '/admin/dispatches');
ok($r['code'] === 403, 'a space owner without agents.settings is refused too');
finish();
