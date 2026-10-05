<?php
/** Proof — who sees what, the admin's logged look at a private space, JSON mode with action and run tokens, _partial=1 (spec "Proof", 7 and 8). */
require __DIR__ . '/lib.php';
$w = spaces_world();
$priya = as_member(26); $marco = as_member(27); $ann = as_member(29); $owner = as_member(1); $dana = as_member(30);

echo "1. Who sees\n";
ok(page($priya, '/spaces/' . $w['leads'])['code'] === 404 && page($dana, '/spaces/' . $w['leads'] . '/members')['code'] === 404 && page($dana, '/spaces/' . $w['leads'] . '/edit')['code'] === 404, 'the private Design leads: 404 to those outside it (home, members, edit)');
$since = last_activity_id();
$r = page($owner, '/spaces/' . $w['leads']);
ok($r['code'] === 200 && str_contains($r['body'], 'id="space-view-kind">private<'), 'the admin opens it (D2)');
$log = activity('space.admin_view', $since);
ok(count($log) === 1 && (int) $log[0]['space_id'] === $w['leads'] && (int) $log[0]['actor_member_id'] === 1, 'and space.admin_view is logged once, with space_id');
page($marco, '/spaces/' . $w['leads']);
ok(count(activity('space.admin_view', $since)) === 1, 'the owner opening his own private space logs nothing of the kind');
ok(page($owner, '/spaces/' . $w['leads'] . '/edit')['code'] === 200 && page($owner, '/spaces/' . $w['product'] . '/members')['code'] === 200, 'the admin manages any space (edit 200)');
$m = q('SELECT space_id FROM mcp_spaces ORDER BY space_id');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$seen = array_map('intval', array_column(q('SELECT space_id FROM mcp_spaces ORDER BY space_id'), 'space_id'));
[$c, $d] = screen($priya, '/spaces/');
$cards = array_map(fn ($s) => $s['space_id'], array_merge($d['mine'], $d['open'], $d['closed']));
sort($cards);
ok($seen === $cards && !in_array($w['leads'], $seen, true), 'mcp_spaces answers Priya exactly the cards shown (and never the private one)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok(q('SELECT space_id FROM mcp_spaces') === [], 'the view answers Ann (guest) nothing');
act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 29]);
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok(array_column(q('SELECT space_id FROM mcp_spaces'), 'space_id') == [$w['product']], 'added to Product by hand, she sees that one');
ok(page($ann, '/spaces/' . $w['product'])['code'] === 200 && str_contains(page($ann, '/spaces/' . $w['product'])['body'], 'id="space-view-leave-btn"') && !str_contains(page($ann, '/spaces/' . $w['product'])['body'], 'id="space-view-edit-btn"'), 'and opens its home: Leave, never Edit');
act($marco, '/spaces/members/remove.php', ['space' => $w['product'], 'member' => 29]);

echo "2. JSON mode — every handler under a signed action token answers the contract; _partial keeps the untouched fields\n";
$tok = ['X-Action-Token: ' . person_token(27)];
[$c, $b] = act_token('/spaces/save.php', ['name' => 'SMOKE Token space', 'kind' => 'closed', 'description' => 'SMOKE by token'], $tok);
$ts = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $ts > 0 && str_starts_with((string) $b['location'], '/spaces/' . $ts) && $b['refresh'] === 'spaceChanged' && isset($b['did']), 'space_create under the action token: {ok, did, record_id, location, refresh}');
[$c, $b] = act_token('/spaces/save.php', ['space' => $ts, 'name' => 'SMOKE Token space 2', '_partial' => '1'], $tok);
$row = q('SELECT name, description, kind, member_level FROM spaces WHERE id = :s', ['s' => $ts])[0];
ok($c === 200 && $row['name'] === 'SMOKE Token space 2' && $row['description'] === 'SMOKE by token' && $row['kind'] === 'closed' && $row['member_level'] === 'edit', '_partial=1 on space_update: the name changed, the description, kind and level kept');
[$c, $b] = act_token('/spaces/save.php', ['space' => $ts, 'name' => '', '_partial' => '1'], $tok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['name']), '422 {error: {code: invalid, fields}}');
foreach ([['/spaces/members/add.php', ['space' => $ts, 'member' => 26]], ['/spaces/members/owner.php', ['space' => $ts, 'member' => 26, 'owner' => 'yes']], ['/spaces/members/owner.php', ['space' => $ts, 'member' => 26, 'owner' => 'no']],
          ['/spaces/sections/save.php', ['space' => $ts, 'name' => 'Docs']], ['/spaces/wiki.php', ['space' => $ts, 'wiki' => 'yes', 'verify_months' => '6']], ['/spaces/kind.php', ['space' => $ts, 'kind' => 'open']],
          ['/spaces/members/remove.php', ['space' => $ts, 'member' => 26]], ['/spaces/archive.php', ['space' => $ts]], ['/spaces/restore.php', ['space' => $ts]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $tok);
    ok($c === 200 && ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']), "$path under the action token: 200 {ok, did, record_id, location, refresh}");
}
[$c, $b] = act_token('/spaces/sections/delete.php', ['section' => (int) one("SELECT id FROM space_sections WHERE space_id = :s AND name = 'Docs'", ['s' => $ts])], $tok);
ok($c === 200 && $b['ok'] === true, '/spaces/sections/delete.php too');
[$c, $b] = act_token('/spaces/join.php', ['space' => $ts], ['X-Action-Token: ' . person_token(30)]);
ok($c === 200 && $b['ok'] === true, '/spaces/join.php as Dana (it is open now)');
[$c, $b] = act_token('/spaces/leave.php', ['space' => $ts], ['X-Action-Token: ' . person_token(30)]);
ok($c === 200 && $b['ok'] === true, '/spaces/leave.php as Dana');
act_token('/spaces/kind.php', ['space' => $ts, 'kind' => 'closed'], $tok);
[$c, $b] = act_token('/spaces/request.php', ['space' => $ts, 'message' => 'SMOKE please'], ['X-Action-Token: ' . person_token(30)]);
$rq = (int) $b['record_id'];
ok($c === 200 && $b['ok'] === true && $rq > 0, '/spaces/request.php as Dana');
[$c, $b] = act_token('/spaces/request-withdraw.php', ['space' => $ts], ['X-Action-Token: ' . person_token(30)]);
ok($c === 200 && $b['ok'] === true, '/spaces/request-withdraw.php');
act_token('/spaces/request.php', ['space' => $ts], ['X-Action-Token: ' . person_token(30)]);
$rq = (int) one("SELECT id FROM space_join_requests WHERE space_id = :s AND status = 'pending'", ['s' => $ts]);
[$c, $b] = act_token('/spaces/request-decide.php', ['request' => $rq, 'decision' => 'approve'], $tok);
ok($c === 200 && $b['ok'] === true && $b['member_id'] === 30, '/spaces/request-decide.php');
act_token('/spaces/archive.php', ['space' => $ts], $tok);
[$c, $b] = act_token('/spaces/delete.php', ['space' => $ts], ['X-Action-Token: ' . person_token(1)]);
ok($c === 200 && $b['ok'] === true && (int) one('SELECT count(*) FROM spaces WHERE id = :s', ['s' => $ts]) === 0, '/spaces/delete.php as the admin');
[$c, $d] = screen($marco, '/spaces/');
ok($c === 200 && isset($d['mine'], $d['open'], $d['closed']), 'the list answers JSON (mine, open, closed)');
foreach (['/spaces/' . $w['product'], '/spaces/' . $w['product'] . '/members', '/spaces/' . $w['product'] . '/sections', '/spaces/' . $w['product'] . '/requests', '/spaces/' . $w['product'] . '/templates', '/spaces/' . $w['product'] . '/edit', '/spaces/new'] as $path) {
    [$c, $d] = screen($marco, $path);
    ok($c === 200 && $d !== [], "$path answers JSON");
}

echo "3. The expert (a run token with the relay) makes a closed space and adds a member — source agent\n";
kernel_state(function ($s) { $s['facts']['501'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 501, 'request_id' => 'req-501', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = run_token(40, 501);
$since = last_activity_id();
[$c, $b] = act_token('/spaces/save.php', ['name' => 'SMOKE Agent space', 'kind' => 'closed'], as_agent($rt));
$as = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $as > 0 && q("SELECT role FROM space_members WHERE space_id = :s AND member_id = 40", ['s' => $as])[0]['role'] === 'owner', 'Seamus (the agent) makes a closed space and owns it');
[$c, $b] = act_token('/spaces/members/add.php', ['space' => $as, 'member' => 26], as_agent($rt));
ok($c === 200 && ($b['rows'] ?? 0) === 1, 'and adds Priya');
$log = activity('space.create', $since);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 501 && $log[0]['request_id'] === 'req-501', 'space.create: source agent, run 501, the run\'s request id');
[$c, $b] = act_token('/spaces/save.php', ['name' => 'SMOKE Watcher space'], as_agent(run_token(41, 502)));
ok($c === 401, 'the Watcher: 401');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
