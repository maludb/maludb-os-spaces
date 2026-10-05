<?php
/** Proof — the agent loop (spec "Proof", 5): the dispatch row; the proof plays the worker: running → "thinking", answered → the reply in the thread. */
require __DIR__ . '/lib.php';
$w = channel_world();
$marco = as_member(27); $priya = as_member(26);
$launch = $w['launch'];

echo "1. A mention dispatches\n";
[$c, $b] = post($priya, $launch, '@SMOKE Seamus what is the launch date?');
$m = (int) $b['record_id'];
$d = q("SELECT id, kind, via, acting_member_id, channel_id, conversation_id, status, pending_message_id FROM agent_dispatches WHERE record_type = 'message' AND record_id = :m", ['m' => $m]);
ok(count($d) === 1 && $d[0]['kind'] === 'mention' && $d[0]['via'] === 'chat' && (int) $d[0]['acting_member_id'] === 26 && (int) $d[0]['channel_id'] === $launch && $d[0]['conversation_id'] === 'spaces:thread:' . $m && $d[0]['status'] === 'sent', 'a dispatch row: mention, chat, the thread as the conversation');
$did = (int) $d[0]['id'];
$r = page($marco, '/channels/' . $launch . '/threads/' . $m);
ok($r['code'] === 200 && !str_contains($r['body'], 'is thinking'), 'nothing is thinking yet');
$due = q('SELECT dispatch_id, utterance, context FROM sp_dispatches_due(10)');
ok(count(array_filter($due, fn ($x) => (int) $x['dispatch_id'] === $did)) === 1 && str_contains((string) $due[0]['utterance'], 'launch date'), 'sp_dispatches_due() offers it with the utterance');

echo "2. The worker: running\n";
$pending = (int) one("SELECT sp_dispatch_record(:d, 'running', 9001, 'req-9001')", ['d' => $did]);
ok($pending > 0 && message_db($pending)['kind'] === 'agent_pending' && message_db($pending)['thread_root_id'] == $m && (int) message_db($pending)['author_member_id'] === 40, 'running: the agent_pending placeholder in the thread');
$r = page($marco, '/channels/' . $launch . '/threads/' . $m);
ok(str_contains($r['body'], 'id="thread-row-' . $pending . '"') && str_contains($r['body'], 'SMOKE Seamus</span> is thinking'), 'the thread shows "SMOKE Seamus is thinking…"');
$r = req('GET', '/channels/' . $launch . '/since?after=' . $m, ['jar' => $marco]);
ok(str_contains($r['headers'], 'X-Running: 1'), 'the channel\'s poll says one is running');
$r = page($marco, '/channels/' . $launch);
ok(str_contains($r['body'], 'id="channel-thinking"') && str_contains($r['body'], 'SMOKE Seamus is thinking'), 'the channel view shows the thinking state');

echo "3. The worker: answered\n";
$n0 = last_note_id();
$reply = (int) one("SELECT sp_dispatch_record(:d, 'answered', 9001, 'req-9001', sp_rich_text('The launch is on **Friday**.'))", ['d' => $did]);
ok($reply === $pending && message_db($reply)['kind'] === 'message' && message_db($reply)['plain_text'] === 'The launch is on **Friday**.' && (int) message_db($m)['reply_count'] === 1, 'answered: the placeholder became the reply as Seamus; reply_count 1');
$r = page($priya, '/channels/' . $launch . '/threads/' . $m);
ok(!str_contains($r['body'], 'is thinking') && str_contains($r['body'], 'id="thread-row-' . $reply . '"') && str_contains($r['body'], 'badge bg-soft-info text-info">agent'), 'the thread shows the reply with the agent chip, no thinking');
ok(count(notes(26, 'agent_replied', $n0)) === 1, 'Priya is told (agent_replied)');
ok(q("SELECT status, reply_message_id FROM agent_dispatches WHERE id = :d", ['d' => $did])[0] == ['status' => 'answered', 'reply_message_id' => $reply], 'the dispatch is answered');
$r = req('GET', '/channels/' . $launch . '/threads/' . $m . '/since?after=' . $reply . '&hashes=' . urlencode(json_encode([(string) $reply => 'pending'])), ['jar' => $marco]);
ok($r['code'] === 200 && str_contains($r['body'], 'hx-swap-oob="outerHTML"') && str_contains($r['body'], 'Friday'), 'a thread poll that knew the pending row gets the reply as a swap');
ok(count(q("SELECT 1 FROM agent_dispatches WHERE record_type = 'message' AND record_id = :m", ['m' => $reply])) === 0, 'Seamus\'s own reply dispatches nothing');

echo "4. A DM to the agent\n";
[$c, $b] = act($priya, '/dm/open.php', ['member' => 40]);
$dm = (int) $b['record_id'];
[$c, $b] = post($priya, $dm, 'Seamus, remind me of the plan');
$dmsg = (int) $b['record_id'];
$d = q("SELECT kind, conversation_id FROM agent_dispatches WHERE record_type = 'message' AND record_id = :m", ['m' => $dmsg]);
ok(count($d) === 1 && $d[0]['kind'] === 'dm' && $d[0]['conversation_id'] === 'spaces:thread:' . $dmsg, 'a DM to Seamus dispatches dm');
$r = page($priya, '/dm/' . $dm);
ok(str_contains($r['body'], 'badge bg-soft-info text-info">agent'), 'the DM shows the agent chip');
finish();
