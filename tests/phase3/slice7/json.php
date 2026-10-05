<?php
/** Proof — JSON mode (the contract): the four actions under action tokens; the four screens answer data. */
require __DIR__ . '/lib.php';
$w = agents_world();
$launch = $w['launch']; $product = $w['product'];
$contract = fn (array $b) => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']);
kernel_state(function ($s) { $s['facts']['4202'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 4202, 'request_id' => 'req-4202', 'trigger' => 'duty', 'endpoints' => [['name' => 'Actions MCP']]]; return $s; });
$lib = as_agent(run_token(42, 4202));
$marcoT = ['X-Action-Token: ' . person_token(27)]; $priyaT = ['X-Action-Token: ' . person_token(26)]; $ownerT = ['X-Action-Token: ' . person_token(1)];

echo "1. The four actions\n";
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE JSON draft', 'space' => $product, 'markdown' => 'x'], $lib);
$draft = (string) $b['record_id'];
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE JSON subject', 'space' => $product, 'markdown' => 'y'], $lib);
$subject = (string) $b['record_id'];
[$c, $b] = act_token('/proposals/save.php', ['kind' => 'duplicate', 'title' => 'A duplicate', 'reason' => 'Same title twice', 'page' => $subject], $lib);
$p1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $contract($b) && $p1 > 0, 'proposal_make: the contract');
[$c, $b] = act_token('/proposals/save.php', ['kind' => 'duplicate', 'title' => 'A duplicate', 'reason' => 'Same title twice', 'page' => $subject], $lib);
ok($c === 422 && str_contains(msg($b), 'already proposed'), 'proposal_make twice: 422');
[$c, $b] = act_token('/proposals/save.php', ['kind' => 'orphan', 'reason' => 'no title', 'page' => $subject], $lib);
ok($c === 422 && isset(fields($b)['title']), 'proposal_make without a title: 422 on the field');
[$c, $b] = act_token('/proposals/accept.php', ['proposal' => $p1], $marcoT);
ok($c === 200 && $contract($b) && $b['location'] === '/pages/' . $subject, 'proposal_accept: the contract, the page to open as the location');
[$c, $b] = act_token('/pages/save.php', ['title' => 'SMOKE JSON subject 2', 'space' => $product, 'markdown' => 'z'], $lib);
$subject2 = (string) $b['record_id'];
[$c, $b] = act_token('/proposals/save.php', ['kind' => 'stale', 'title' => 'Stale', 'reason' => 'old', 'page' => $subject2], $lib);
$p2 = (int) $b['record_id'];
[$c, $b] = act_token('/proposals/dismiss.php', ['proposal' => $p2, 'reason' => 'not now'], $priyaT);
ok($c === 200 && $contract($b), 'proposal_dismiss: the contract');
[$c, $b] = act_token('/proposals/dismiss.php', ['proposal' => 999999999], $priyaT);
ok($c === 404, 'proposal_dismiss on nothing: 404');
// a failed dispatch to retry
kernel_chat(['mode' => 'error500']);
[$c, $b] = post(as_member(26), $launch, '@SMOKE Seamus json failure');
$dm = (int) $b['record_id']; $d = dispatch_id_of($dm, 40);
for ($i = 0; $i < 6; $i++) { make_due($d); worker_pass(); }
ok(dispatch_db($d)['status'] === 'failed', 'set-up: a failed dispatch');
[$c, $b] = act_token('/admin/dispatches/retry.php', ['dispatch' => $d], $priyaT);
ok($c === 403, 'dispatch_retry by a Member: 403');
[$c, $b] = act_token('/admin/dispatches/retry.php', ['dispatch' => $d], $ownerT);
ok($c === 200 && $contract($b) && dispatch_db($d)['status'] === 'sent', 'dispatch_retry: the contract');
kernel_chat(['reply' => 'fine']);
worker_pass();
echo "2. _partial and the screens\n";
[$c, $js] = screen(as_member(27), '/proposals/?status=accepted&kind=duplicate');
ok($c === 200 && $js['status'] === 'accepted' && $js['kind'] === 'duplicate' && in_array($p1, array_column($js['proposals'], 'proposal_id'), true) && $js['proposals'][0]['subject']['url'] !== null, '/proposals/ answers JSON');
$one = array_column($js['proposals'], null, 'proposal_id')[$p1];
ok(isset($one['icon'], $one['proposed_by']['display_name']) && array_key_exists('draft', $one) && $one['proposed_by']['member_id'] === 42 && $one['status'] === 'accepted' && isset($one['decided_by']['member_id']), 'a proposal presents icon, subject, draft (null here), proposer and decider');
foreach (['/admin/agents', '/admin/dispatches', '/admin/connections'] as $path) { [$c, $d2] = screen(as_member(1), $path); ok($c === 200 && $d2 !== [], "$path answers JSON"); }
$r = req('GET', '/admin/agents', ['jar' => as_member(1), 'headers' => JSONH]);
ok(!str_contains($r['body'], 'reply_text') && !str_contains($r['body'], 'utterance'), 'no utterance or reply text is presented beyond the excerpt');
echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
foreach (['proposal_make', 'proposal_accept', 'proposal_dismiss', 'dispatch_retry'] as $a) { ok(($reg['actions'][$a]['built'] ?? false) === true, "$a is built in the registry"); }
foreach (['proposal-list', 'agent-list', 'dispatch-list', 'connection-list'] as $s) { ok(($reg['screens'][$s]['built'] ?? false) === true, "$s is built in the registry"); }
finish();
