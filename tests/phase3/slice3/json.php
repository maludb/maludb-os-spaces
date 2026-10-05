<?php
/** Proof — JSON mode (spec "Proof", 8): every action under a signed action token answers the contract; the expert appends and is refused in words. */
require __DIR__ . '/lib.php';
$w = editor_world();
$tok = ['X-Action-Token: ' . person_token(27)];
$contract = fn (array $b) => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']);
pdo()->exec("SELECT set_config('app.member_id', '27', false)");   // the proof's own reads through the MCP views are Marco's

echo "1. Every action under the action token\n";
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Token editor page', 'space' => $w['product']], $tok);
$tp = (string) $b['record_id'];
[$c, $b] = act_token('/blocks/append.php', ['page' => $tp, 'markdown' => "one\n\ntwo"], $tok);
ok($c === 200 && $contract($b) && ($b['refresh'] ?? '') === 'blockChanged' && count($b['block_ids']) === 2, 'block_append: the contract, block_ids');
$b1 = $b['block_ids'][0]; $b2 = $b['block_ids'][1];
foreach ([['/blocks/insert.php', ['page' => $tp, 'type' => 'paragraph', 'content' => para('three'), 'after' => $b2]], ['/blocks/update.php', ['block' => $b1, 'version' => 1, 'markdown' => 'one more']], ['/blocks/move.php', ['block' => $b2, 'after' => '']],
          ['/blocks/delete.php', ['block' => $b2]], ['/pages/versions/save.php', ['page' => $tp]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $tok);
    ok($c === 200 && $contract($b), "$path: 200 and the contract" . ($c !== 200 ? ' — ' . msg($b) : ''));
    if ($path === '/pages/versions/save.php') { $vid = (int) $b['record_id']; }
}
[$c, $b] = act_token('/pages/versions/restore.php', ['version' => $vid], $tok);
ok($c === 200 && $contract($b) && ($b['version_no'] ?? 0) === 3, '/pages/versions/restore.php: the contract, version_no 3');
[$c, $b] = act_token('/pages/comments/add.php', ['page' => $tp, 'markdown' => 'a token comment'], $tok);
$cid = (string) $b['record_id'];
ok($c === 200 && $contract($b) && is_uuid($cid), '/pages/comments/add.php: the contract, a UUID');
foreach ([['/pages/comments/edit.php', ['comment' => $cid, 'markdown' => 'edited']], ['/pages/comments/resolve.php', ['comment' => $cid]], ['/pages/comments/resolve.php', ['comment' => $cid, 'resolved' => 'no']], ['/pages/comments/delete.php', ['comment' => $cid]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $tok);
    ok($c === 200 && $contract($b), "$path: 200 and the contract" . ($c !== 200 ? ' — ' . msg($b) : ''));
}
[$c, $b] = act_token('/files/delete.php', ['attachment' => 999999], $tok);
ok($c === 404, '/files/delete.php on nothing: 404');
foreach (['/pages/' . $tp, '/pages/' . $tp . '/history', '/pages/' . $tp . '/versions/1', '/pages/comments.php?page=' . $tp, '/blocks/get.php?block=' . $b1, '/pages/mentions.php?q=smoke&kind=member'] as $path) {
    [$c, $d] = screen(as_member(27), $path);
    ok($c === 200 && $d !== [], "$path answers JSON");
}
[$c, $d] = screen(as_member(27), '/pages/mentions.php?q=smoke&kind=page');
ok($c === 200 && count($d['candidates']) >= 3 && ($d['candidates'][0]['kind'] ?? '') === 'page', 'the page picker finds SMOKE pages');
[$c, $d] = screen(as_member(27), '/pages/mentions.php?q=smile&kind=emoji');
ok($c === 200 && ($d['candidates'][0]['kind'] ?? '') === 'emoji', 'the emoji picker');
[$c, $d] = screen(as_member(27), '/pages/' . $tp);
ok(($d['editor'] ?? false) === true && is_array($d['comment_counts'] ?? null) && is_array($d['blocks'] ?? null), 'the page screen as JSON says editor and carries the counts');

echo "2. The expert (a run token with the relay)\n";
kernel_state(function ($s) { $s['facts']['602'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 602, 'request_id' => 'req-602', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = run_token(40, 602);
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Agent editor page', 'space' => $w['general']], as_agent($rt));
$ap = (string) $b['record_id'];
$since = last_activity_id();
[$c, $b] = act_token('/blocks/append.php', ['page' => $ap, 'markdown' => "# Written by the expert\n\n- a\n- b"], as_agent($rt));
ok($c === 200 && ($b['count'] ?? 0) === 3, 'Seamus appends Markdown to a page it may edit');
$log = activity('block.append', $since);
ok(count($log) === 3 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 602, 'block.append (three rows): source agent, run 602');
[$c, $b] = act_token('/blocks/append.php', ['page' => $w['runbook'], 'markdown' => 'intruding'], as_agent($rt));
ok($c === 404, 'on a Product page (it cannot see): 404');
act(as_member(27), '/pages/share.php', ['page' => $w['runbook'], 'member' => 40, 'level' => 'view']);
[$c, $b] = act_token('/blocks/append.php', ['page' => $w['runbook'], 'markdown' => 'intruding'], as_agent($rt));
ok($c === 403 && str_contains(msg($b), 'edit'), 'shared at view: 403 in words');
act(as_member(27), '/pages/unshare.php', ['page' => $w['runbook'], 'member' => 40]);
[$c, $b] = act_token('/pages/comments/add.php', ['page' => $ap, 'markdown' => 'the expert comments'], as_agent($rt));
ok($c === 200 && (int) one('SELECT agent_run_id FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $b['record_id']]) === 602 && (bool) one('SELECT author_is_agent FROM mcp_comments WHERE comment_id = CAST(:c AS uuid)', ['c' => $b['record_id']]), 'its comment carries the run and reads as an agent\'s');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
