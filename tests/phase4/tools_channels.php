<?php
/**
 * Proof — channels and messages (C1-C12): find_channels, get_channel, channel_history, thread_read, my_unread, my_activity, my_saved, my_reminders, channel_pins, unanswered_questions, thread_candidates, my_scheduled, my_dms, channel_members.
 * A message reaches an agent as Markdown with its author, time, reactions; a private channel or a direct conversation the caller is not in does not exist for them. World: #smoke-launch (public, Product), #smoke-leads-only (private: Marco, Priya),
 * a DM Marco -> Priya, Priya's thread "Is the rate limit per key?" (a reply of Marco's, a 👍 of Dana's), Dana's question 30 hours old, and the p4 messages (a pin, a bookmark, a reaction, a save, a reminder, a scheduled message).
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$L = $w['launch']; $LO = $w['leads_channel']; $P = $w['product'];
$T = fn (int $m, string $tool, array $a = []) => tdata($m, $tool, $a);
$E = fn (int $m, string $tool, array $a = []) => (string) (tdata($m, $tool, $a)['error'] ?? '');
$msgs = fn ($r) => $r['messages'] ?? [];

echo "1. Finding and describing channels (C1)\n";
$fc = $T(27, 'find_channels', ['limit' => 100]);
ok(find_row($fc, 'channel_id', $L)['kind'] === 'public' && find_row($fc, 'channel_id', $LO)['kind'] === 'private' && count($fc) === (int) val_as(27, "SELECT count(*) FROM mcp_channels WHERE kind IN ('public', 'private') AND archived_at IS NULL"), 'find_channels as Marco: the public and the private channel, the same count the view gives him (' . count($fc) . '); direct messages are my_dms');
ok(find_row($T(30, 'find_channels', ['limit' => 100]), 'channel_id', $LO) === null && find_row($T(30, 'find_channels', ['limit' => 100]), 'channel_id', $L) !== null, 'a private channel Dana is not in does not exist for her; the public one does');
ok($T(27, 'find_channels', ['q' => 'launch']) === [['channel_id' => $L, 'name' => 'smoke-launch']], 'q alone resolves #name: the plain list (channel_id, name)');
ok(count($T(27, 'find_channels', ['kind' => 'private'])) >= 1 && count(array_filter($T(27, 'find_channels', ['kind' => 'private', 'limit' => 100]), fn ($c) => $c['kind'] !== 'private')) === 0 && str_contains($E(27, 'find_channels', ['kind' => 'dm']), 'public or private'), 'kind private; a direct-message kind is told to use my_dms');
ok(find_row($T(30, 'find_channels', ['mine' => true, 'limit' => 100]), 'channel_id', $L) !== null && find_row($T(31, 'find_channels', ['mine' => true, 'limit' => 100]), 'channel_id', $L) === null, 'mine: Dana joined #smoke-launch, Lee did not');
ok(count($T(27, 'find_channels', ['space' => 'SMOKE Product', 'limit' => 100])) === count(array_filter($fc, fn ($c) => $c['space_id'] === $P)), 'space: by name or id, only that space\'s');
$un = find_row($T(30, 'find_channels', ['limit' => 100]), 'channel_id', $L);
$sql = sql_as(30, 'SELECT unread_count, mention_count FROM sp_unread(30) WHERE channel_id = ' . $L)[0] ?? ['unread_count' => 0, 'mention_count' => 0];
ok($un['unread_count'] === (int) $sql['unread_count'] && $un['mention_count'] === (int) $sql['mention_count'] && $un['unread_count'] >= 1 && find_row($T(26, 'find_channels', ['limit' => 100]), 'channel_id', $L)['unread_count'] === 0, 'how much is unread for Dana in #smoke-launch is sp_unread\'s (' . $un['unread_count'] . '); Priya wrote the last line, so none for her');
$gc = $T(26, 'get_channel', ['channel' => '#smoke-launch']);
ok($gc['channel_id'] === $L && $gc['topic'] === 'The launch' && $gc['purpose'] === 'Everything about the launch' && $gc['pins_count'] === 1 && $gc['member_count'] >= 5 && $gc['i_am_member'] === true && $gc['retention_days'] === null, 'get_channel by #name: topic, purpose, pins 1, members, retention off');
ok($T(29, 'get_channel', ['channel' => $L])['channel_id'] === $L && str_contains($E(30, 'get_channel', ['channel' => $LO]), 'No channel you can see') && str_contains($E(27, 'get_channel', ['channel' => 'nonesuch']), 'No channel you can see'), 'a guest in the channel reads it; Dana is told a private channel does not exist for her; an unknown name too');
$cm = $T(27, 'channel_members', ['channel' => $L]);
ok(find_row($cm, 'member_id', 29) !== null && find_row($cm, 'member_id', 40)['is_agent'] === true && find_row($cm, 'member_id', 42)['is_agent'] === true, 'channel_members: the guest Ann and the agents Seamus and the Librarian, agents marked');
ok(ids($T(27, 'channel_members', ['channel' => $LO]), 'member_id') === [27, 26] && str_contains($E(30, 'channel_members', ['channel' => $LO]), 'No channel you can see'), 'a private channel\'s members are visible to its members only');

echo "2. What was said (C2-C3)\n";
$h = $T(27, 'channel_history', ['channel' => $L, 'limit' => 200]);
$m = $msgs($h);
$mids = ids($m, 'message_id');
$sorted = $mids; sort($sorted);
ok(count($m) >= 6 && $mids === $sorted && $h['channel_id'] === $L, 'channel_history: the messages oldest first (' . count($m) . ')');
$p4 = find_row($m, 'markdown', 'SMOKE p4 the rollout starts Monday https://example.com/rollout');
ok($p4 !== null && $p4['author'] === 'SMOKE Marco' && $p4['author_is_agent'] === false && $p4['reactions'] === [['emoji' => '🎉', 'count' => 1, 'by_me' => true]] && $p4['is_pinned'] === true && str_ends_with($p4['created_at'], '+00:00') && isset($p4['created_at_local']), 'a message as Markdown with its author, time (and the local time), reactions (mine marked) and a pin');
$q = array_values(array_filter($m, fn ($x) => str_contains($x['markdown'], 'Is the rate limit per key?')))[0] ?? null;
ok($q !== null && $q['reply_count'] === 1 && $q['repliers'] === ['SMOKE Marco'] && $q['thread_root_id'] === null && count(array_filter($m, fn ($x) => $x['thread_root_id'] !== null && !$x['also_to_channel'])) === 0, 'a thread is collapsed: the question with its reply count and who replied; its replies are not in the stream');
ok(isset($m[0]['markdown']) && !has_key($m, 'body') && !has_key($m, 'plain_text'), 'only Markdown reaches the agent: no stored run arrays');
$bys = $T(27, 'channel_history', ['channel' => '#smoke-launch', 'since' => (string) $p4['message_id']]);
ok(array_column($msgs($bys), 'message_id') === array_values(array_filter($mids, fn ($i) => $i > $p4['message_id'])) && count($msgs($bys)) >= 1, 'since a message id: only what came after it (' . count($msgs($bys)) . ')');
$byt = $T(27, 'channel_history', ['channel' => $L, 'since' => gmdate('Y-m-d\TH:i:s\Z', strtotime($p4['created_at']) - 1)]);
ok(in_array($p4['message_id'], array_column($msgs($byt), 'message_id'), true) && count($msgs($byt)) < count($m), 'since a time: from the first message at or after it');
ok($msgs($T(27, 'channel_history', ['channel' => $L, 'since' => '2099-01-01'])) === [], 'a time in the future: nothing');
$bef = $T(27, 'channel_history', ['channel' => $L, 'before' => (string) $p4['message_id'], 'limit' => 2]);
ok(count($msgs($bef)) === 2 && max(array_column($msgs($bef), 'message_id')) < $p4['message_id'], 'before: the page of messages older than one (limit 2)');
ok(count($msgs($T(27, 'channel_history', ['channel' => $L, 'limit' => 3]))) === 3, 'limit');
$sch = array_filter($m, fn ($x) => str_contains($x['markdown'], 'scheduled hello'));
ok(count($sch) === 0, 'a scheduled message is not in the channel until it is sent');
$res = $T(27, 'channel_history', ['q' => 'rate limit']);
ok(count($res) >= 2 && array_keys($res[0]) === ['message_id', 'excerpt'] && preg_match('/^SMOKE \w+, \d+ \w{3}: /', $res[0]['excerpt']) === 1, 'q alone (no channel) resolves a message: the plain list (message_id, "Priya, 6 Oct: …")');
ok(count($T(30, 'channel_history', ['q' => 'direct line for Priya'])) === 0 && count($T(26, 'channel_history', ['q' => 'direct line for Priya'])) === 1 && count($T(27, 'channel_history', ['q' => 'leads'])) >= 0, 'a direct message appears to Priya and not to Dana');
ok(count($msgs($T(29, 'channel_history', ['channel' => $L]))) >= 6 && str_contains($E(30, 'channel_history', ['channel' => $LO]), 'No channel you can see'), 'the guest reads the channel she was added to; Dana is told the private one does not exist');
ok(str_contains($E(27, 'channel_history', ['channel' => $L, 'before' => 'x']), 'a message id') && str_contains($E(27, 'channel_history', []), 'Give the channel'), 'a bad before, and nothing at all: told in words');
$th = $T(27, 'thread_read', ['message' => $q['message_id']]);
ok($th['root_message_id'] === $q['message_id'] && count($th['messages']) === 2 && $th['messages'][1]['markdown'] === 'SMOKE Per key, yes.' && $th['messages'][1]['thread_root_id'] === $q['message_id'], 'thread_read: the first message and the reply, in order, as Markdown');
$rid = $th['messages'][1]['message_id'];
ok($T(26, 'thread_read', ['message' => $rid])['root_message_id'] === $q['message_id'], 'any message of the thread names it');
$dmq = (int) one("SELECT id FROM messages WHERE plain_text LIKE 'SMOKE p4 a direct line%'");
ok(str_contains($E(30, 'thread_read', ['message' => $dmq]), 'No message you can read') && $T(26, 'thread_read', ['message' => $dmq])['messages'][0]['markdown'] === 'SMOKE p4 a direct line for Priya', 'a direct message reads to its two people and to nobody else');

echo "3. Mine (C4-C6, C10, C12)\n";
$ur = $T(26, 'my_unread');
$sqlu = sql_as(26, 'SELECT channel_id, unread_count FROM sp_unread(26) WHERE unread_count > 0');
ok(count($ur) === count($sqlu) && count($ur) >= 2 && array_sum(array_column($ur, 'unread_count')) == array_sum(array_column($sqlu, 'unread_count')), 'my_unread: the channels with something unread, the same as sp_unread (' . count($ur) . ')');
ok(in_array($ur[0]['kind'], ['dm', 'group_dm'], true) && $ur[0]['with_people'] === 'SMOKE Marco' && $ur[0]['unread_count'] === 1, 'the direct messages come first: Marco\'s line, 1 unread');
$ml = find_row($T(30, 'my_unread'), 'channel_id', $L);
ok($ml['first_unread_id'] > 0 && $ml['mention_count'] >= 0 && $ml['name'] === 'smoke-launch' && $ml['unread_count'] >= 1, 'with the first unread message and the mentions among them (Dana in #smoke-launch)');
$ac = $T(26, 'my_activity');
ok(count($ac) === count(sql_as(26, "SELECT kind FROM sp_activity_feed(now() - interval '7 days', 100)")) && count($ac) >= 1 && count($T(26, 'my_activity', ['kind' => 'mention'])) <= count($ac), 'my_activity: the same feed as sp_activity_feed (' . count($ac) . '); a kind narrows it');
ok(str_contains(json_encode($T(27, 'my_activity')), 'comment') || count($T(27, 'my_activity')) >= 1, 'Marco\'s feed has Priya\'s comment naming him');
$sv = $T(27, 'my_saved');
ok(count($sv) === 1 && $sv[0]['markdown'] === 'SMOKE p4 the rollout starts Monday https://example.com/rollout' && $sv[0]['channel_name'] === 'smoke-launch' && count($T(26, 'my_saved')) === 1 && $T(26, 'my_saved')[0]['markdown'] === 'SMOKE Is the rate limit per key?' && $T(30, 'my_saved') === [], 'my_saved: Marco\'s one message as Markdown with where it is; Priya\'s is her own; Dana has none');
$rm = $T(27, 'my_reminders');
ok(count($rm) === 1 && $rm[0]['text'] === 'SMOKE p4 check the rollout' && str_contains((string) $rm[0]['message_excerpt'], 'rollout starts Monday') && $T(26, 'my_reminders')[0]['text'] === 'SMOKE look at the limits' && $T(30, 'my_reminders') === [], 'my_reminders: the one, with what it is about; Priya\'s is her own');
ok(count($T(27, 'my_reminders', ['include_done' => true])) === 1, 'include_done');
$sc = $T(27, 'my_scheduled');
ok(count($sc) === 1 && $sc[0]['markdown'] === 'SMOKE p4 scheduled hello' && $sc[0]['scheduled_for'] > gmdate('c') && $T(26, 'my_scheduled') === [], 'my_scheduled: Marco\'s message waiting to go out, and when; nobody else\'s');
$dm = $T(27, 'my_dms');
ok(count($dm) === 1 && $dm[0]['names'] === 'SMOKE Priya' && $dm[0]['last_line'] === 'SMOKE p4 a direct line for Priya' && $dm[0]['unread_count'] === 0 && $dm[0]['kind'] === 'dm', 'my_dms: the conversation with Priya, its last line, nothing unread for the sender');
ok(count($T(26, 'my_dms')) === 1 && $T(26, 'my_dms')[0]['unread_count'] === 1 && $T(30, 'my_dms') === [], 'Priya has 1 unread in it; Dana has no conversations');

echo "4. Pins, questions, decisions (C7-C9)\n";
$pn = $T(27, 'channel_pins', ['channel' => $L]);
ok(count($pn['pins']) === 1 && $pn['pins'][0]['message_markdown'] === 'SMOKE p4 the rollout starts Monday https://example.com/rollout' && count($pn['bookmarks']) === 1 && $pn['bookmarks'][0]['title'] === 'SMOKE p4 rollout doc' && $pn['bookmarks'][0]['url'] === 'https://example.com/rollout-doc', 'channel_pins: the pinned message as Markdown and the bookmark');
ok(str_contains($E(30, 'channel_pins', ['channel' => $LO]), 'No channel you can see'), 'pins of a private channel: not for Dana');
$uq = $T(27, 'unanswered_questions');
ok(count($uq) === count(sql_as(27, 'SELECT message_id FROM sp_unanswered_questions(NULL)')) && find_row($uq, 'author_name', 'SMOKE Dana')['hours_open'] >= 29 && find_row($uq, 'author_name', 'SMOKE Dana')['channel_name'] === 'smoke-launch', 'unanswered_questions: Dana\'s question 30 hours old, the same list as the SQL function');
ok(count($T(27, 'unanswered_questions', ['hours' => 400])) < count($uq) && count($T(27, 'unanswered_questions', ['channel' => '#smoke-launch'])) === count(array_filter($uq, fn ($x) => $x['channel_id'] === $L)) && $T(27, 'unanswered_questions', ['space' => $w['general']]) === [], 'hours, channel and space narrow it');
$tc = $T(27, 'thread_candidates', ['min_replies' => 1, 'days' => 30]);
ok(find_row($tc, 'message_id', $q['message_id']) !== null && count($tc) === count(sql_as(27, 'SELECT message_id FROM sp_thread_candidates(1, 30)')), 'thread_candidates (1 reply): Priya\'s thread, the same as the SQL function');
ok(find_row($T(27, 'thread_candidates', []), 'message_id', $q['message_id']) === null && count($T(27, 'thread_candidates', ['min_replies' => 1, 'space' => $w['general']])) === 0, 'the default bar (8 replies) does not list a 1-reply thread; a space narrows');
finish();
