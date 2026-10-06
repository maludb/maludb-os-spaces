<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-spaces.json + mcp/action_registry.json, composed as the installer writes mcp/registries/spaces.json, are loaded by the kernel's
 * loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does (application_actions.register): 118 built actions, a tool each, approval categories the kernel's policies know, the `confirmed`
 * guard on destructive ones, `_partial` on the partial updates; every resolve entity is answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp) with the caller's token; and a few
 * whole actions are driven through the kernel's tool (the approval hook and the handler stubbed). Then a REAL partial update over HTTP under an action token, and maludb-os.json: the agents' grants name tools and actions that exist, every approval is an
 * action with that category and log event, the skills' directories exist and every tool a skill or a job description names exists. The kernel's python runs the loader; nothing of the kernel is written.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$root = dirname(__DIR__, 2);
$S = surface();
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-spaces.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];      // the kernel's approval_policies CHECK and bin/app_install.php's mapping

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'spaces' && count($wrapper['resolve']) === 12 && $reg['generated_from'] !== null, 'the wrapper: schema maludb-os.registry/1, app_key spaces, twelve resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry is current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 118 && count(array_filter($actions, fn ($a) => !empty($a['built']))) === 118, 'the registry holds 118 actions, every one built');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($a) => $a['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat === 32, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $k => $a) { $p = $root . '/html' . parse_url($a['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p))) { $missing[] = $k . ' → ' . $a['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $k; } } }
ok($phantom === [], 'no action carries a phantom parameter (a comma or semicolon inside a note splits a manifest cell: the tool would lose its real fields — page_delete, channel_create, thread_reply … were fixed in Phase 4)' . ($phantom ? ' — ' . implode(', ', array_unique($phantom)) : ''));
foreach (['page_delete' => 'page', 'channel_create' => 'name', 'channel_archive' => 'channel', 'channel_delete' => 'channel', 'thread_reply' => 'message', 'database_delete' => 'database', 'row_create' => 'properties', 'import_start' => 'file'] as $act => $param) {
    ok(in_array($param, array_column($actions[$act]['params'], 'name'), true), "$act takes `$param` (it was lost to a comma in the manifest's note)");
}
$noLog = array_filter($actions, fn ($a) => empty($a['log_event']));
ok($noLog === [], 'every action names the log event the approval hook asks about');
$dupe = array_diff_key(array_column($actions, 'action'), array_unique(array_column($actions, 'action')));
ok($dupe === [] && count(array_unique(array_keys($actions))) === 118, 'no two actions share a name (the kernel skips a collision)');

echo "2. Every resolve entity: its tool exists on the records server, answers the plain list\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['records'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} exists on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$used = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { $n = $p['name'] ?? null; if ($n !== null && isset($pm[$n])) { $used[$n] = ($used[$n] ?? 0) + 1; } } }
ok(count($used) >= 15, 'the resolved params are really used by actions (' . count($used) . ' distinct names)');
$idsOnly = ['block', 'comment', 'attachment', 'bookmark', 'reminder', 'section', 'request', 'dispatch', 'token', 'notification', 'shortcode'];
ok(count(array_intersect($idsOnly, array_keys($pm))) === 0, 'the ids-only params (block, comment, attachment, bookmark, reminder, section, request, dispatch, token, notification, shortcode) are never resolved by name');
$ghost = [];
foreach (array_keys($pm) as $n) { if (count(array_filter($actions, fn ($a) => in_array($n, array_column($a['params'], 'name'), true))) === 0) { $ghost[] = $n; } }
sort($ghost);
ok($ghost === ['agent', 'parent_page', 'person', 'root', 'thread', 'to_member'], 'the resolved params no action uses are the ones the surface names for the kernel\'s and the agents\' own tools (agent, parent_page, person, root, thread, to_member): harmless — found: ' . implode(', ', $ghost));

$resolve = [
    ['space', 'SMOKE Product', atoken(27)], ['page', 'SMOKE Runbook', atoken(27)], ['page', $w['target_id'], atoken(27)], ['database', 'SMOKE p4 Work', atoken(27)], ['database', $w['tasks'], atoken(27)], ['view', 'SMOKE p4 Open', atoken(27)],
    ['channel', 'smoke-launch', atoken(27)], ['message', 'starts Monday', atoken(27)], ['member', 'SMOKE Dana', atoken(27)], ['department', 'accounting', atoken(27)], ['template', 'meeting notes', atoken(27)],
    ['proposal', 'a duplicate', atoken(27)], ['version', 'SMOKE p4 Spec', atoken(27)], ['export', 'spec', atoken(27)],
];
$drive = [
    ['page_trash', ['page' => 'SMOKE p4 Public', 'confirmed' => true], atoken(27)],
    ['page_trash', ['page' => 'SMOKE p4 Public', 'confirmed' => true], atoken(27), 'pending_approval'],
    ['page_trash', ['page' => 'SMOKE p4 Public'], atoken(27)],
    ['page_publish', ['page' => 'SMOKE p4 Public', 'include_subpages' => 'yes', 'confirmed' => true], atoken(1)],
    ['share_guest', ['page' => 'SMOKE Runbook', 'guest' => 'SMOKE Ann', 'level' => 'view', 'confirmed' => true], atoken(27), 'pending_approval'],
    ['message_post', ['channel' => 'smoke-launch', 'markdown' => 'SMOKE p4 never posted'], atoken(26)],
    ['thread_reply', ['message' => 'starts Monday', 'markdown' => 'SMOKE p4 never replied'], atoken(26)],
    ['page_update', ['page' => 'SMOKE Runbook', 'icon' => '🧪'], atoken(27)],
    ['space_join', ['space' => 'smoke'], atoken(27)],
    ['channel_create', ['space' => 'SMOKE Product', 'name' => 'smoke-never'], atoken(27)],
    ['page_delete', ['page' => $w['target_id'], 'confirmed' => true], atoken(27)],
    ['block_append', ['page' => 'nonesuch page', 'markdown' => 'x'], atoken(27)],
    ['row_create', ['database' => 'SMOKE p4 Work', 'title' => 'SMOKE p4 never made', 'properties' => '{"Status":"Todo"}'], atoken(27)],
    ['page_move', ['page' => 'SMOKE Runbook', 'parent' => 'SMOKE Product handbook'], atoken(27)],
    ['space_member_add', ['space' => 'SMOKE Product', 'member' => 'SMOKE Lee', 'role' => 'member'], atoken(27)],
];
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = escapeshellarg($kpy) . ' ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('SP_DEV_ENV')])) . ' 2>/tmp/sp-registry-check.err';
$out = (string) shell_exec($cmd);
$k = json_decode($out, true);
ok(is_array($k), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($k) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/sp-registry-check.err'), -300)));
if (!is_array($k)) { finish(); }
ok($k['loaded'] === ['spaces'] && $k['registered'] === 118, 'application_actions.load_registries() accepts it and register() adds 118 tools');
ok(count($k['tools']) === 118 && count(array_diff(array_keys($actions), array_keys($k['tools']))) === 0, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $a) { if ($a['approval'] !== null && !str_contains($k['tools'][$key]['description'], 'category: ' . $a['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $a) { $txt = json_encode($k['tools'][$key]['schema']); if (!empty($a['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [] && count(array_filter($actions, fn ($a) => !empty($a['confirm']))) >= 20, 'every destructive action carries the `confirmed` guard (' . count(array_filter($actions, fn ($a) => !empty($a['confirm']))) . ' of them)');
$noParam = [];
foreach ($actions as $key => $a) { $props = array_keys($k['tools'][$key]['schema']['$defs'][array_key_first($k['tools'][$key]['schema']['$defs'] ?? [])]['properties'] ?? []); foreach ($a['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
$partial = array_keys(array_filter($actions, fn ($a) => !empty($a['partial'])));
ok(count($partial) >= 5 && !array_diff(['page_update', 'space_update', 'channel_update', 'database_update', 'row_update'], $partial), '`_partial` is on the *_update actions (' . implode(', ', $partial) . ')');
$noPart = [];
foreach ($partial as $key) { if (!str_contains($k['tools'][$key]['description'], 'only what changes')) { $noPart[] = $key; } }
ok($noPart === [], 'their tools tell an agent to send the record and only what changes');
foreach ($k['resolved'] as $i => $r) { }
$byKey = [];
foreach ($resolve as $i => [$entity, $value]) { $byKey[] = [$entity, $value]; }
echo "   resolving through the kernel's resolver, as the person who asks:\n";
$n = 0;
foreach ($resolve as [$entity, $value]) {
    $r = $k['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    $want = $entity === 'version' ? 2 : 1;
    ok($r !== null && !isset($r['error']) && $r['matches'] === $want && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true), "resolver: $entity '" . substr((string) $value, 0, 24) . "' → $want match" . ($want > 1 ? 'es (several versions: the kernel asks which)' : '') . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 40) . '"' : ' — ' . json_encode($r)));
}
$id = fn ($e, $v) => (string) $k['resolved'][$e . '|' . $v]['id'];
$d = $k['driven'];
echo "3. Whole actions through the kernel's tool\n";
if (getenv('SP_DEBUG')) { foreach ([3, 4] as $i) { fwrite(STDERR, json_encode($d[$i]) . "\n"); } }
ok(($d[0]['answer']['status'] ?? '') === 'success' && $d[0]['answer']['resolved']['page'] === 'SMOKE p4 Public' && count($d[0]['posts']) === 2 && $d[0]['posts'][0]['path'] === '/approvals/hook.php' && $d[0]['posts'][1]['path'] === '/pages/trash.php', 'page_trash by NAME with confirmed: the name resolves, the kernel\'s hook is asked first, then the handler');
$hook = json_decode($d[0]['posts'][0]['fields']['parameters'], true);
ok($d[0]['posts'][0]['fields']['action_key'] === 'page_trash' && $d[0]['posts'][0]['fields']['log_event'] === 'page.trash' && $hook === ['page' => $w['public']] && $d[0]['posts'][1]['fields'] === ['page' => $w['public']], 'the hook is asked about the LOG EVENT page.trash, with the RESOLVED page id (a UUID); the handler gets the same');
ok(($d[1]['answer']['status'] ?? '') === 'pending_approval' && count($d[1]['posts']) === 1 && $d[1]['answer']['application'] === 'spaces', 'when the kernel pauses it (pending_approval): nothing is posted to the application');
ok(($d[2]['answer']['status'] ?? '') === 'needs_confirmation' && $d[2]['posts'] === [], 'without confirmed=true: needs_confirmation, nothing posted, nothing asked');
ok(($d[3]['answer']['status'] ?? '') === 'success' && $d[3]['posts'][0]['fields']['action_key'] === 'page_publish' && $d[3]['posts'][1]['path'] === '/pages/publish.php' && $d[3]['posts'][1]['fields'] === ['page' => $w['public'], 'include_subpages' => 'yes'], 'page_publish (external_send): the hook, then /pages/publish.php with the page id and include_subpages');
ok(($d[4]['answer']['status'] ?? '') === 'pending_approval' && count($d[4]['posts']) === 1 && $d[4]['posts'][0]['fields']['log_event'] === 'page.share_guest' && $d[4]['answer']['resolved']['guest'] === 'SMOKE Ann', 'share_guest paused for approval: the guest resolved by name to Ann, nothing posted to Spaces');
ok(($d[5]['answer']['status'] ?? '') === 'success' && count($d[5]['posts']) === 1 && $d[5]['posts'][0]['path'] === '/channels/messages/post.php' && $d[5]['posts'][0]['fields'] === ['channel' => $id('channel', 'smoke-launch'), 'markdown' => 'SMOKE p4 never posted'], 'message_post (no approval): the channel resolves to its id, one post');
ok(($d[6]['answer']['status'] ?? '') === 'success' && $d[6]['posts'][0]['path'] === '/channels/messages/reply.php' && $d[6]['posts'][0]['fields']['message'] === $id('message', 'starts Monday') && str_contains($d[6]['answer']['resolved']['message'], 'SMOKE Marco, '), 'thread_reply: "starts Monday" resolves to the message ("SMOKE Marco, 6 Oct: …")');
ok(($d[7]['answer']['status'] ?? '') === 'success' && $d[7]['posts'][0]['path'] === '/pages/save.php' && $d[7]['posts'][0]['fields'] === ['page' => $w['runbook'], 'icon' => '🧪', '_partial' => '1'], 'page_update posts the record and what changes with `_partial` = 1');
ok(($d[8]['answer']['status'] ?? '') === 'error' && str_contains($d[8]['answer']['message'], 'Several match') && str_contains($d[8]['answer']['message'], 'SMOKE Product (id ') && $d[8]['posts'] === [], 'an ambiguous name (space "smoke"): the kernel lists the candidates and posts nothing');
ok(($d[9]['answer']['status'] ?? '') === 'success' && $d[9]['posts'][0]['path'] === '/channels/save.php' && $d[9]['posts'][0]['fields'] === ['space' => (string) $w['product'], 'name' => 'smoke-never'], 'channel_create: the space and the NAME of the channel reach the handler (the manifest\'s note had swallowed `name`)');
ok(($d[10]['answer']['status'] ?? '') === 'success' && $d[10]['posts'][1]['path'] === '/pages/purge.php' && $d[10]['posts'][1]['fields'] === ['page' => $w['target_id']] && $d[10]['posts'][0]['fields']['log_event'] === 'page.delete', 'page_delete by a UUID read from `trash`: the resolver accepts the id (a page in the trash resolves by its id)');
ok(($d[11]['answer']['status'] ?? '') === 'error' && str_contains($d[11]['answer']['message'], "No page matching 'nonesuch page'") && $d[11]['posts'] === [], 'an unknown name: "No page matching \'nonesuch page\' in Spaces that you can see."');
ok(($d[12]['answer']['status'] ?? '') === 'success' && $d[12]['posts'][0]['path'] === '/databases/rows/save.php' && $d[12]['posts'][0]['fields'] === ['database' => $id('database', 'SMOKE p4 Work'), 'title' => 'SMOKE p4 never made', 'properties' => '{"Status":"Todo"}'], 'row_create: the database by name, the properties as JSON reach the handler');
ok(($d[13]['answer']['status'] ?? '') === 'success' && $d[13]['posts'][0]['fields'] === ['page' => $w['runbook'], 'parent' => $w['handbook']], 'page_move: the page and the new parent both resolved by name');
ok(($d[14]['answer']['status'] ?? '') === 'success' && $d[14]['posts'][0]['fields'] === ['space' => (string) $w['product'], 'member' => '31', 'role' => 'member'], 'space_member_add: a space and a member by name, the role as given');
$wrote = (int) one("SELECT count(*) FROM messages WHERE plain_text LIKE 'SMOKE p4 never%'") + (int) one("SELECT count(*) FROM channels WHERE name = 'smoke-never'") + (int) one("SELECT count(*) FROM pages WHERE plain_title = 'SMOKE p4 never made'");
ok($wrote === 0 && (string) one('SELECT archived_at FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $w['public']]) === '', 'and nothing was written to the application: the handler posts were stubs');

echo "4. A real partial update, over HTTP, under an action token\n";
$title = (string) one('SELECT plain_title FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $w['spec']]);
$blocks = (int) one('SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid)', ['p' => $w['spec']]);
$tok = person_token(27);
[$c, $b] = act_token('/pages/save.php', ['page' => $w['spec'], 'icon' => '📝', '_partial' => '1'], as_agent($tok));
ok($c === 200 && (string) one('SELECT icon FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $w['spec']]) === '📝', 'page_update with `page`, `icon` and _partial: 200, the icon is set');
ok((string) one('SELECT plain_title FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $w['spec']]) === $title && (int) one('SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid)', ['p' => $w['spec']]) === $blocks, '...and the title and the body are what they were (the handler filled the rest from the base-table row)');
[$c, $b] = act_token('/pages/save.php', ['page' => $w['spec'], 'icon' => '📝'], as_agent($tok));
ok($c === 200 || $c === 422 || $c === 400, 'the same without _partial is judged as a whole record: ' . $c . ' (never a silent blank of the title)');
ok((string) one('SELECT plain_title FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $w['spec']]) === $title, '...and the title is still there');

echo "5. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'librarian'] && $agents['librarian']['duty']['schedule_cron'] === '30 6 * * 1' && $agents['librarian']['duty']['name'] === 'The Monday wiki report', 'two agents hired on install: the expert and the Librarian, whose duty is the Monday wiki report at 06:30 (30 6 * * 1)');
$badTool = [];
foreach ($manifest['agents'] as $a) {
    foreach ($a['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['records'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t]) && !in_array($t, ['message_send', 'inbox_read'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry (or the kernel\'s own message tools)' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
ok(count(array_filter($agents['expert']['tool_grants']['Records MCP'], fn ($t) => in_array($t, $S['shares'], true) || $t === 'my_tokens' || $t === 'my_exports')) === 0, 'the expert is granted no share and no one\'s tokens or exports');
$lib = $agents['librarian']['tool_grants'];
ok(!array_intersect(['page_publish', 'share_guest', 'page_trash', 'page_delete', 'trash_purge', 'channel_announce'], $lib['Actions MCP']) && !array_intersect(['page_publish', 'share_guest'], $agents['expert']['tool_grants']['Actions MCP'] ?? []) === false, 'the Librarian is granted nothing that publishes, shares, shouts or deletes; the expert\'s publish and guest share are the two that pause');
$appr = array_column($manifest['approvals'], 'category', 'action');
$bad = [];
foreach ($manifest['approvals'] as $a) { if (!isset($actions[$a['action']]) || $actions[$a['action']]['approval'] !== $a['category']) { $bad[] = $a['action']; } }
ok($bad === [] && count($appr) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
ok((int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/sync_approvals.php --check >/dev/null 2>&1; echo $?') === 0, 'and bin/sync_approvals.php --check agrees');
$pausedForAgent = array_filter($actions, fn ($a) => $a['approval'] === 'external_send');
ok(isset($appr['page_publish']) && $appr['page_publish'] === 'external_send' && $appr['share_guest'] === 'external_send' && $appr['channel_guest_add'] === 'external_send' && $appr['page_delete'] === 'deletion' && $appr['retention_set'] === 'other', 'design §5\'s pauses: publish and guest shares external_send, deletes deletion, retention other');
foreach ($manifest['skills'] as $s) { ok(is_file($root . '/' . $s . '/SKILL.md'), "skill $s has its SKILL.md"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), ['thread_to_page', 'is_empty', 'message_send']);       // a proposal kind, a filter operator, the kernel's tool
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/*.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) { if (!in_array($name, $known, true)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; } }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', $unknown) : ''));
finish();
