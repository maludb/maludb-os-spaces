<?php
/** Proof — JSON mode (spec "Proof", 6): every action under a signed action token answers the contract; the expert posts where it is, is refused where it is not. */
require __DIR__ . '/lib.php';
$w = channel_world();
$launch = $w['launch'];
$tok = ['X-Action-Token: ' . person_token(27)];
$ptok = ['X-Action-Token: ' . person_token(26)];
$adm = ['X-Action-Token: ' . person_token(1)];
$contract = fn (array $b) => ($b['ok'] ?? false) === true && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']);

echo "1. Every action under the action token\n";
[$c, $b] = act_token('/channels/save.php', ['space' => $w['product'], 'name' => 'smoke-token', 'topic' => 't'], $tok);
$ch = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $contract($b) && $ch > 0, 'channel_create: the contract');
[$c, $b] = act_token('/channels/save.php', ['channel' => $ch, 'topic' => 'changed', '_partial' => '1'], $tok);
ok($c === 200 && channel_row($ch)['topic'] === 'changed' && channel_row($ch)['name'] === 'smoke-token', '_partial=1 on channel_update: the topic changed, the name kept');
[$c, $b] = act_token('/channels/messages/post.php', ['channel' => $ch, 'markdown' => 'first'], $tok);
$m1 = (int) $b['record_id'];
ok($c === 200 && $contract($b) && isset($b['message']['markdown']) && isset($b['html']), 'message_post: the contract, the message and its HTML');
[$c, $b] = act_token('/channels/messages/post.php', ['channel' => $ch, 'markdown' => 'later', 'schedule_for' => (new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i')], $tok);
$sched = (int) $b['record_id'];
act_token('/channels/members/add.php', ['channel' => $ch, 'member' => 26], $tok);
foreach ([['/channels/messages/reply.php', ['message' => $m1, 'markdown' => 'a reply'], $tok], ['/channels/messages/edit.php', ['message' => $m1, 'markdown' => 'first, edited'], $tok], ['/channels/messages/react.php', ['message' => $m1, 'emoji' => '🎉'], $tok],
          ['/channels/messages/unreact.php', ['message' => $m1, 'emoji' => '🎉'], $tok], ['/channels/messages/save.php', ['message' => $m1], $tok], ['/channels/messages/save.php', ['message' => $m1, 'saved' => 'no'], $tok],
          ['/channels/pins/add.php', ['channel' => $ch, 'message' => $m1], $tok], ['/channels/pins/remove.php', ['channel' => $ch, 'message' => $m1], $tok], ['/channels/bookmarks/save.php', ['channel' => $ch, 'title' => 'B', 'url' => 'https://example.com'], $tok],
          ['/channels/notify.php', ['channel' => $ch, 'starred' => 'yes'], $tok], ['/channels/retention.php', ['channel' => $ch, 'days' => 7], $tok], ['/channels/read.php', ['channel' => $ch], $tok],
          ['/channels/messages/schedule.php', ['message' => $sched, 'schedule_for' => (new DateTimeImmutable('+3 hours'))->format('Y-m-d H:i')], $tok], ['/channels/messages/unschedule.php', ['message' => $sched], $tok],
          ['/channels/messages/announce.php', ['channel' => $ch, 'markdown' => 'hear ye'], $tok], ['/channels/messages/delete-own.php', ['message' => $m1], $tok],
          ['/channels/members/add-guest.php', ['channel' => $ch, 'guest' => 29], $tok], ['/channels/members/remove.php', ['channel' => $ch, 'member' => 29], $tok], ['/channels/leave.php', ['channel' => $ch], $ptok], ['/channels/join.php', ['channel' => $ch], $ptok],
          ['/dm/open.php', ['member' => 26], $tok], ['/dm/open-group.php', ['members' => [26, 30]], $tok], ['/reminders/save.php', ['remind_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i'), 'text' => 'SMOKE remember'], $tok],
          ['/channels/archive.php', ['channel' => $ch], $tok], ['/channels/unarchive.php', ['channel' => $ch], $tok], ['/channels/archive.php', ['channel' => $ch], $tok], ['/channels/delete.php', ['channel' => $ch], $adm]] as [$path, $form, $t]) {
    [$c, $b] = act_token($path, $form, $t);
    ok($c === 200 && $contract($b), "$path: 200 and the contract" . ($c !== 200 ? ' — ' . msg($b) : ''));
    if ($path === '/reminders/save.php') { $rem = (int) $b['record_id']; }
    if ($path === '/channels/bookmarks/save.php') { act_token('/channels/bookmarks/delete.php', ['bookmark' => (int) $b['record_id']], $tok); }
}
[$c, $b] = act_token('/reminders/done.php', ['reminder' => $rem], $tok);
ok($c === 200 && $contract($b), '/reminders/done.php: the contract');
[$c, $b] = act_token('/channels/messages/delete.php', ['message' => 999999999], $tok);
ok($c === 404, 'message_delete on nothing: 404');
foreach (['/channels/', '/channels/new', '/channels/' . $launch, '/channels/' . $launch . '/edit', '/channels/' . $launch . '/members', '/channels/' . $launch . '/pins', '/channels/scheduled', '/dm/', '/dm/new', '/reminders/', '/saved'] as $path) {
    [$c, $d] = screen(as_member(27), $path);
    ok($c === 200 && $d !== [], "$path answers JSON");
}

echo "2. The expert (a run token with the relay)\n";
kernel_state(function ($s) { $s['facts']['702'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 702, 'request_id' => 'req-702', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = run_token(40, 702);
$since = last_activity_id();
[$c, $b] = act_token('/channels/messages/post.php', ['channel' => $launch, 'markdown' => 'Seamus here, @channel'], as_agent($rt));
$am = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $am > 0 && (int) message_db($am)['author_member_id'] === 40 && one('SELECT count(*) FROM message_mentions WHERE message_id = :m', ['m' => $am]) == 0, 'Seamus posts in #smoke-launch (it is in it); its @channel stripped');
$log = activity('message.post', $since);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 702, 'message.post: source agent, run 702');
[$c, $b] = act_token('/channels/messages/post.php', ['channel' => $w['leads_channel'], 'markdown' => 'intruding'], as_agent($rt));
ok($c === 404, 'in #smoke-leads-only (it cannot see): 404');
[$c, $b] = act_token('/channels/messages/post.php', ['channel' => $w['product_general'], 'markdown' => 'in general?'], as_agent($rt));
ok($c === 200 || $c === 403, 'Product\'s #general: ' . ($c === 200 ? 'posted (a public channel of a space it is in)' : 'refused in words — ' . msg($b)));
[$c, $b] = post(as_member(26), $launch, 'a root for the agent');
$root = (int) $b['record_id'];
[$c, $b] = act_token('/channels/messages/reply.php', ['message' => $root, 'markdown' => 'the agent replies'], as_agent($rt));
ok($c === 200 && message_db((int) $b['record_id'])['thread_root_id'] == $root, 'its thread_reply lands in the thread');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
