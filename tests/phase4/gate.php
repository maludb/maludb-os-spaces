<?php
/**
 * Proof — the door. Who gets in and what they are offered: no token, a forged, an expired one -> 401; a person's own token, and the command bar's action token -> every tool of the surface and no other; an agent's RUN token -> exactly the tools
 * the kernel's run-facts call grants on THIS endpoint (fail closed: facts that say invalid, facts for another application, a kernel that cannot be reached, an endpoint the agent was not granted); an agent the directory has not admitted is admitted
 * at first contact only when the kernel vouches for it; an evaluation run may read; the kernel's own token reaches app_roles and the two shares only. Servers: records :8404, activity :8405, records-with-a-dead-kernel :8410.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$run = fn () => random_int(700000, 799999);      // a fresh run id every time: the servers cache a run's facts
$S = surface();

echo "1. Tokens that do not get in\n";
foreach ([REC, ACT] as $port) {
    $n = $port === REC ? 'records' : 'activity';
    ok(mcp_tools($port, '') === null, "$n: no token -> 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a made-up mcp_ token -> 401");
    $t = rtoken(40, $run());
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature -> 401");
    ok(mcp_tools($port, rtoken(40, $run(), -5)) === null, "$n: an expired run token -> 401");
    ok(mcp_tools($port, atoken(30, -5)) === null, "$n: an expired action token -> 401");
    ok(mcp_tools($port, atoken(9999)) === null, "$n: an action token for a member the mirror does not know -> 401 (never created)");
    ok(mcp_tools($port, ktoken('someone_else')) === null, "$n: the kernel's token for ANOTHER application -> 401");
    ok(mcp_tools($port, ktoken('spaces', -5)) === null, "$n: an expired kernel token -> 401");
}
ok(one('SELECT count(*) FROM members WHERE id = 9999') == 0, 'and the unknown member was not created');

echo "2. People: the whole surface, and nothing it does not name\n";
$rec = mcp_tools(REC, ptoken(27));
ok($rec === $S['records'] && count($rec) === 62, 'Marco is offered exactly the surface\'s records tools: ' . count($rec ?? []) . ' (every table row of the surface, with app_roles)');
$act = mcp_tools(ACT, ptoken(27));
ok($act === $S['activity'] && count($act) === 6, 'and exactly the 6 activity tools');
ok($S['shares'] === ['page_markdown', 'pages_index'] && !array_diff($S['shares'], $rec), 'the two shares[] of maludb-os.json are among them');
ok(mcp_tools(REC, ptoken(26)) === $rec && mcp_tools(REC, atoken(26)) === $rec && mcp_tools(REC, atoken(1)) === $rec && mcp_tools(REC, ptoken(29)) === $rec, 'a Member (own token), the command bar\'s action token, the owner and a GUEST get the same tool list: the world differs, the tools do not');
$r = mcp_tool(REC, atoken(26), 'my_dms', []);
ok(!$r['error'] && is_array($r['data']) && count($r['data']) === 1 && str_contains((string) $r['data'][0]['last_line'], 'SMOKE p4 a direct line'), 'the action token acts as its member: my_dms is Priya\'s (the one DM, Marco\'s line)');
$r = mcp_tool(REC, atoken(30), 'my_dms', []);
ok(!$r['error'] && $r['data'] === [], 'and Dana has none');
$defs = mcp_tool_defs(REC, ptoken(27));
$ro = array_filter($defs, fn ($d) => ($d['annotations']['readOnlyHint'] ?? false) === true && ($d['annotations']['openWorldHint'] ?? true) === false);
ok(count($ro) === count($defs) && count($defs) === 62, 'every records tool carries readOnlyHint true and openWorldHint false');
$ad = mcp_tool_defs(ACT, ptoken(27));
ok(count(array_filter($ad, fn ($d) => ($d['annotations']['readOnlyHint'] ?? false) === true)) === 6, '...and so does every activity tool');
$noDesc = array_filter($defs, fn ($d) => strlen((string) ($d['description'] ?? '')) < 40);
ok(count($noDesc) === 0, 'every tool describes itself (when to call it, what it answers)');
$noTool = array_diff(array_keys(array_merge(...array_map(fn ($a) => $a['tool_grants']['Records MCP'] ?? [], json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true)['agents']))), []);
$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
$bad = [];
foreach ($manifest['agents'] as $a) {
    foreach ((array) ($a['tool_grants']['Records MCP'] ?? []) as $tl) { if (!in_array($tl, $S['records'], true)) { $bad[] = $a['key'] . ':' . $tl; } }
    foreach ((array) ($a['tool_grants']['Activity MCP'] ?? []) as $tl) { if (!in_array($tl, $S['activity'], true)) { $bad[] = $a['key'] . ':' . $tl; } }
}
ok($bad === [], 'every tool an agent is granted in maludb-os.json exists on the server it is granted on' . ($bad ? ' — missing: ' . implode(', ', $bad) : ''));

echo "3. An agent sees exactly what the kernel granted on this endpoint\n";
$exp = agent_grants('expert');
$r1 = $run();
facts($r1, 40, $exp);                           // Seamus presenting the expert's grants
$tok = rtoken(40, $r1);
$want = array_keys($exp['Records MCP']); sort($want);
ok(mcp_tools(REC, $tok) === $want, 'the expert\'s grants: exactly its ' . count($want) . ' granted records tools (maludb-os.json)');
$wantA = array_keys($exp['Activity MCP']); sort($wantA);
ok(mcp_tools(ACT, $tok) === $wantA, 'activity: exactly its ' . count($wantA) . ' (a grant on one endpoint is not a grant on the other)');
$c = mcp_tool(REC, $tok, 'find_spaces', ['q' => 'product']);
ok(!$c['error'] && count($c['data']) === 1 && $c['data'][0]['name'] === 'SMOKE Product', 'a granted tool answers as the agent (Seamus is a member of Product)');
$c = mcp_tool(REC, $tok, 'my_tokens', []);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($c['text'], 0, 100));
ok(mcp_tool(REC, $tok, 'pages_index', ['q' => 'x'])['error'], 'a share it was not granted is refused for an agent too');
$lib = agent_grants('librarian');
$r2 = $run(); facts($r2, 42, $lib);
$want = array_keys($lib['Records MCP']); sort($want);
ok(mcp_tools(REC, rtoken(42, $r2)) === $want, 'the Librarian: exactly its ' . count($want) . ' granted records tools');
$c = mcp_tool(REC, rtoken(42, $r2), 'wiki_status', []);
ok(!$c['error'] && count($c['data']) > 0, 'wiki_status answers as the Librarian (a Member of Product, which is a wiki space)');

echo "4. Fail closed\n";
$r = $run(); facts($r, 40, ['Records MCP' => ['find_spaces' => []]], ['valid' => false]);
$t = rtoken(40, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
ok(mcp_tool(REC, $t, 'find_spaces', [])['error'], 'and cannot call anything');
$r = $run(); facts($r, 40, []);
ok(mcp_tools(REC, rtoken(40, $r)) === [], "facts with no endpoint for this application (another application's agent): nothing offered");
ok(mcp_tools(REC, rtoken(40, $run())) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = $run(); facts($r, 40, ['Records MCP' => ['find_spaces' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, rtoken(40, $r)) ?? []) === 62, 'facts that say is_agent false are a person: never filtered');
$r = $run(); facts($r, 40, ['Records MCP' => ['find_spaces' => []]]);
$t = rtoken(40, $r);
ok(mcp_tools(REC_DEAD, $t) === [], 'the kernel cannot be reached (:8410, dead URL): an admitted agent is offered nothing');
ok(mcp_tool(REC_DEAD, $t, 'find_spaces', [])['error'], 'and cannot call');
ok(count(mcp_tools(REC_DEAD, ptoken(27)) ?? []) === 62 && count(mcp_tools(REC_DEAD, atoken(27)) ?? []) === 62, "while a person's own token and the command bar's action token still work with no kernel");

echo "5. An agent the directory has not admitted yet\n";
$unadm = 41;                                    // the Watcher: in the mirror, never vouched for
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => $unadm]);
ok(one('SELECT capability FROM members WHERE id = 41') === null, 'the Watcher (41) is in the mirror with no capability');
$r = $run(); $t = rtoken($unadm, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 41') === null, '...capability still null');
facts($r, $unadm, ['Records MCP' => ['find_spaces' => [], 'get_settings' => []]]);
ok(mcp_tools(REC, $t) === ['find_spaces', 'get_settings'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 41') === 'write', '...and its capability is now write');
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => $unadm]);
$r = $run(); facts($r, $unadm, []);
ok(mcp_tools(REC, rtoken($unadm, $r)) === null && one('SELECT capability FROM members WHERE id = 41') === null, 'facts with NO endpoint here do not admit: 401, capability still null');

echo "6. An evaluation run may read, and marks nothing\n";
$before = [(int) one('SELECT count(*) FROM activity_log'), (int) one('SELECT count(*) FROM pages'), (int) one('SELECT count(*) FROM messages'), (int) one('SELECT count(*) FROM notification_outbox')];
$r = $run(); facts($r, 40, $exp, ['trigger' => 'eval']);
$te = rtoken(40, $r);
$c = mcp_tool(REC, $te, 'find_pages', ['q' => 'runbook'], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && count($c['data']) >= 1, 'an eval run (facts trigger eval, X-Eval-Run header): a read tool answers');
$c = mcp_tool(REC, $te, 'channel_history', ['channel' => $w['launch']], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && count($c['data']['messages']) > 0, 'and a conversation as well');
$after = [(int) one('SELECT count(*) FROM activity_log'), (int) one('SELECT count(*) FROM pages'), (int) one('SELECT count(*) FROM messages'), (int) one('SELECT count(*) FROM notification_outbox')];
ok($before === $after, 'nothing was written: the log, the pages, the messages and the outbox are as they were');

echo "7. The kernel's own token\n";
$kt = ktoken();
$ktools = mcp_tools(REC, $kt);
$exp7 = array_merge($S['shares'], ['app_roles']); sort($exp7);
ok($ktools === $exp7, 'the kernel is offered exactly app_roles and the two shares: ' . implode(', ', $ktools ?? []));
ok(mcp_tools(ACT, ktoken()) === [], 'and nothing on the activity server');
$c = mcp_tool(REC, ktoken(), 'find_spaces', [], true);
ok($c['error'] && str_contains($c['text'], "reaches"), "the kernel's token calling anything else is refused: " . substr($c['text'], 0, 90));
$c = mcp_tool(REC, ktoken(), 'app_roles', [], true);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'app_roles answers it: os.app-roles/1');

echo "8. app_roles — the catalogue (kernel db/145, plugin 0.4.0)\n";
$ar = $c['data'];
$roles = array_column($ar['roles'], null, 'key');
ok(array_keys($roles) === ['guest', 'user', 'space_owner', 'admin'], 'four roles in order: guest, user (Member), space_owner, admin');
ok(count($ar['rights']) === 21 && count(array_filter($ar['roles'], fn ($r) => $r['is_admin'])) === 1 && $roles['admin']['is_admin'] === true, 'twenty-one rights, one admin role');
$dbr = q('SELECT role_key, rights FROM mcp_app_roles ORDER BY sort_order');
$same = true;
foreach ($dbr as $x) { $set = array_filter(explode(',', trim((string) $x['rights'], '{}'))); sort($set); $mine = $roles[$x['role_key']]['rights']; sort($mine); if ($set !== $mine) { $same = false; } }
ok($same, 'each role\'s rights are exactly the database\'s (mcp_app_roles)');
ok($roles['guest']['capability'] === 'read' && $roles['user']['capability'] === 'write' && $roles['admin']['capability'] === 'admin', 'capabilities read, write and admin as the integration contract names them');
$pr = mcp_tool(REC, ptoken(26), 'app_roles', []);
ok(!$pr['error'] && ($pr['data']['schema'] ?? '') === 'os.app-roles/1', 'a person may read the catalogue too (it is about no one)');
finish();
