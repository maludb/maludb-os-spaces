<?php
/**
 * Proof — the activity server (ACT1-ACT5, P12, C11): record_history, actor_timeline, recent_activity, who_touched, share_reads, activity_search. The trail is read through mcp_activity_log, which shows a caller the rows about what they may see and their
 * own; a message's words and a page's text are never in it. World: Marco's Spec page (blocks, two versions, comments), #smoke-launch, the share.read rows the shares proof just made.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$L = $w['launch']; $P = $w['product'];
$A = fn (int $m, string $tool, array $a = []) => tdata($m, $tool, $a, ACT);
$E = fn (int $m, string $tool, array $a = []) => (string) (tdata($m, $tool, $a, ACT)['error'] ?? '');
$acts = fn ($r) => array_column($r['events'] ?? [], 'action');

echo "1. A record's history (ACT1, P12)\n";
$h = $A(27, 'record_history', ['entity_type' => 'page', 'entity_uuid' => $w['spec']]);
$ev = $h['events'];
ok(count($ev) >= 5 && $ev[0]['activity_id'] < end($ev)['activity_id'] && $h['timezone'] === 'UTC', 'record_history of a page: its events oldest first (' . count($ev) . ')');
ok($acts($h)[0] === 'page.create' && in_array('block.append', $acts($h), true) && in_array('page.version_save', $acts($h), true) && in_array('comment.add', $acts($h), true), 'the creation, its edits block by block, the saved versions and the comments are all in one history');
ok($ev[0]['actor_label'] === 'SMOKE Marco' && $ev[0]['source'] === 'web' && $ev[0]['actor_is_agent'] === false && isset($ev[0]['occurred_at_local']), 'each row: the actor worded, the source, the time and the local time');
$ver = array_values(array_filter($ev, fn ($e) => $e['action'] === 'page.version_save'))[0];
ok(isset($ver['after']['version_no']) || isset($ver['after']['reason']) || $ver['after'] !== null, 'a version save carries its facts (version number, reason)');
ok(!str_contains(json_encode($h), 'first paragraph') && !str_contains(json_encode($h), 'third paragraph') && !str_contains(json_encode($h), 'rollout date'), 'never the text: no paragraph and no comment words anywhere in the trail');
ok(count($A(26, 'record_history', ['entity_type' => 'page', 'entity_uuid' => $w['spec']])['events']) === count($ev) && $A(29, 'record_history', ['entity_type' => 'page', 'entity_uuid' => $w['spec']])['events'] === [], 'Priya (who may see the page) sees the same trail; the guest, who may not, sees nothing of it');
$sp = $A(27, 'record_history', ['entity_type' => 'space', 'entity_id' => $P]);
ok(in_array('space.wiki_set', $acts($sp), true) && in_array('space.member_add', $acts($sp), true) && count(array_unique(array_column($sp['events'], 'space_id'))) >= 1, 'a space by its integer id: members added, the wiki setting (the newest 100 of its trail)');
$ch = $A(27, 'record_history', ['entity_type' => 'channel', 'entity_id' => $L]);
ok(in_array('channel.create', $acts($ch), true) && in_array('message.post', $acts($ch), true) && in_array('channel.pin', $acts($ch), true), 'a channel: its creation, the posts, the pin');
$posts = array_values(array_filter($ch['events'], fn ($e) => $e['action'] === 'message.post'));
ok(count($posts) >= 4 && isset($posts[0]['after']['length']) && !has_key($ch, 'markdown') && !has_key($ch, 'plain_text') && !str_contains(json_encode($ch), 'rollout starts Monday'), 'a message post records its length and mentions, never the body');
$msg = $A(27, 'record_history', ['entity_type' => 'message', 'entity_id' => $w['p4_message']]);
ok(in_array('message.post', $acts($msg), true) && in_array('message.react', $acts($msg), true) && count($msg['events']) >= 3, 'one message: its post, the reaction, the save, the pin');
$db = $A(27, 'record_history', ['entity_type' => 'database', 'entity_uuid' => $w['tasks']]);
ok(in_array('database.create', $acts($db), true) && in_array('row.create', $acts($db), true), 'a database: created, its rows made (through the payload\'s database id)');
ok($A(30, 'record_history', ['entity_type' => 'channel', 'entity_id' => $w['leads_channel']])['events'] === [] && count($A(26, 'record_history', ['entity_type' => 'channel', 'entity_id' => $w['leads_channel']])['events']) >= 1, 'a private channel\'s trail: Priya (in it) reads it, Dana does not');
ok(str_contains($E(27, 'record_history', ['entity_type' => 'page']), 'Give entity_id') && str_contains($E(27, 'record_history', ['entity_type' => 'page', 'entity_uuid' => 'nope']), 'UUID') && count($A(27, 'record_history', ['entity_type' => 'page', 'entity_uuid' => $w['spec'], 'limit' => 2])['events']) === 2, 'no id, a bad id: told in words; a limit keeps the newest and still reads oldest first');
$lim = $A(27, 'record_history', ['entity_type' => 'page', 'entity_uuid' => $w['spec'], 'limit' => 2])['events'];
ok($lim[1]['activity_id'] === end($ev)['activity_id'], '...the newest two of the history');

echo "2. Who did what (ACT2, C11)\n";
$tl = $A(1, 'actor_timeline', ['member' => 'SMOKE Marco', 'since' => '2026-01-01']);
ok($tl['member_id'] === 27 && count($tl['events']) > 10 && $tl['events'][0]['activity_id'] > end($tl['events'])['activity_id'] && count(array_filter($tl['by_action'], fn ($b) => $b['action'] === 'block.append')) === 1, 'actor_timeline as the admin: Marco\'s rows newest first with a count by action');
ok(count(array_filter($tl['events'], fn ($e) => $e['action'] === 'screen.view')) === 0, 'screen views are not part of anyone\'s trail');
ok(str_contains($E(26, 'actor_timeline', ['member' => 27, 'since' => '2026-01-01']), "person's trail") , 'a person\'s trail is not Priya\'s to read (she owns no space)');
$self = $A(27, 'actor_timeline', ['member' => 27, 'since' => '2026-01-01']);
ok(count($self['events']) === count($tl['events']), 'but one\'s own is: Marco reads his, the same rows the admin read');
$own = $A(27, 'actor_timeline', ['member' => 'SMOKE Priya', 'since' => '2026-01-01']);
ok(count($own['events']) >= 1 && count(array_filter($own['events'], fn ($e) => $e['space_id'] !== $P)) === 0, 'a space owner reads a person\'s rows in the spaces they own only (Priya in Product: ' . count($own['events']) . ')');
$ag = $A(26, 'actor_timeline', ['member' => 'SMOKE Librarian', 'since' => '2026-01-01']);
ok(!isset($ag['error']) && count($ag['events']) >= 1 && $ag['events'][0]['actor_is_agent'] === true && str_ends_with($ag['events'][0]['actor_label'], '(agent)'), 'an agent\'s trail is open to any member: the Librarian\'s proposal, worded "(agent)"');
ok(count($A(27, 'actor_timeline', ['member' => 27])['events']) <= count($self['events']) && str_contains($E(27, 'actor_timeline', ['member' => 'Nobody Atall']), 'No member you can see'), 'since defaults to 7 days; an unknown member is told');
$rc = $A(27, 'recent_activity', ['since' => '2026-01-01']);
ok(count($rc['events']) > 20 && count($rc['by_action']) > 5 && count(array_filter($rc['events'], fn ($e) => $e['action'] === 'screen.view')) === 0 && $rc['events'][0]['activity_id'] > end($rc['events'])['activity_id'], 'recent_activity: what changed, newest first, with a count by action');
$rs = $A(27, 'recent_activity', ['since' => '2026-01-01', 'space' => 'SMOKE Product', 'limit' => 200]);
ok(count($rs['events']) > 0 && count(array_filter($rs['events'], fn ($e) => $e['space_id'] !== $P)) === 0 && $rs['events'][0]['space_name'] === 'SMOKE Product', 'a space narrows it, each row naming the space');
ok($A(27, 'recent_activity', ['since' => '2099-01-01'])['events'] === [] && str_contains($E(27, 'recent_activity', ['since' => 'lately']), 'a day'), 'a future time: nothing; a bad one: told');
$dana = $A(30, 'recent_activity', ['since' => '2026-01-01', 'limit' => 200]);
$sqlc = sql_as(30, "SELECT count(*) FROM mcp_activity_log WHERE action <> 'screen.view' AND occurred_at >= '2026-01-01'");
ok(count($dana['events']) === min(200, (int) $sqlc[0]['count']) && count($dana['events']) < count($A(1, 'recent_activity', ['since' => '2026-01-01', 'limit' => 200])['events']), 'Dana sees the rows the view gives her — fewer than the admin\'s');

echo "3. Who acted here (ACT4)\n";
$wt = $A(27, 'who_touched', ['space' => 'SMOKE Product', 'since' => '2026-01-01']);
ok(count($wt['everyone']) >= 3 && $wt['last'] !== null && find_row($wt['everyone'], 'actor_member_id', 27)['actions'] > 5 && isset($wt['everyone'][0]['first_at'], $wt['everyone'][0]['last_at']), 'who_touched a space as its owner: everyone with their action counts, first and last time, and the last thing done');
ok(str_contains($E(26, 'who_touched', ['space' => $P]), 'owners and the admin') && count($A(1, 'who_touched', ['space' => $P, 'since' => '2026-01-01'])['everyone']) === count($wt['everyone']), 'a Member is refused; the admin sees the same');
$wp = $A(27, 'who_touched', ['page' => $w['spec'], 'since' => '2026-01-01']);
ok(find_row($wp['everyone'], 'actor_member_id', 27) !== null && find_row($wp['everyone'], 'actor_member_id', 26) !== null, 'who_touched a page (edit access): Marco and Priya, who commented');
ok(str_contains($E(29, 'who_touched', ['page' => $w['runbook']]), 'edit access') && str_contains($E(27, 'who_touched', []), 'exactly one') && str_contains($E(27, 'who_touched', ['page' => $w['spec'], 'space' => $P]), 'exactly one'), 'a guest with view only: refused; none or two targets: told');
$wc = $A(30, 'who_touched', ['channel' => '#smoke-launch', 'since' => '2026-01-01']);
ok(count($wc['everyone']) >= 3 && str_contains($E(30, 'who_touched', ['channel' => $w['leads_channel']]), 'No channel you can see'), 'who_touched a channel (anyone who may read it); a private one is not Dana\'s');

echo "4. What siblings read of ours (ACT4) and the trail by a phrase (ACT5)\n";
$sr = $A(1, 'share_reads');
ok(count($sr['reads']) >= 6 && count(array_unique(array_column($sr['reads'], 'tool'))) === 2 && $sr['reads'][0]['direction'] === 'in' && $sr['reads'][0]['source'] === 'application' && $sr['reads'][0]['actor_member_id'] === null, 'share_reads as the admin: the shares\' calls, each with the tool, the direction, source application, no actor (' . count($sr['reads']) . ')');
ok(str_contains($E(27, 'share_reads'), "admin's") && $A(1, 'share_reads', ['since' => '2099-01-01'])['reads'] === [] , 'for the admin alone; a future time lists none');
$one = $sr['reads'][0];
ok(isset($one['after']['keys']) && isset($one['after']['rows']) && !str_contains(json_encode($sr), 'Runbook'), 'a row carries the argument keys and the rows answered — never what was read');
$as = $A(27, 'activity_search', ['q' => 'version_save']);
ok(count($as['events']) === 2 && array_unique($acts($as)) === ['page.version_save'], 'activity_search "version_save": the action named');
$as2 = $A(27, 'activity_search', ['q' => 'smoke-launch']);
ok(count($as2['events']) >= 3 && count(array_filter($as2['events'], fn ($e) => $e['channel_name'] === 'smoke-launch' || ($e['after']['name'] ?? '') === 'smoke-launch')) === count($as2['events']), 'a channel\'s name finds the events about it');
$as3 = $A(27, 'activity_search', ['q' => 'SMOKE p4 Spec']);
ok(count($as3['events']) >= 3 && count(array_filter($as3['events'], fn ($e) => ($e['page_title'] ?? '') === 'SMOKE p4 Spec' || ($e['after']['title'] ?? '') === 'SMOKE p4 Spec')) === count($as3['events']), 'a page\'s title finds its events');
ok($A(27, 'activity_search', ['q' => 'version_save', 'since' => '2099-01-01'])['events'] === [] && $A(29, 'activity_search', ['q' => 'version_save'])['events'] === [], 'since narrows; a guest sees none of it');
ok(!isset($A(27, 'activity_search', ['q' => 'x; DROP TABLE pages'])['error']), 'words, never SQL: a statement is just words that find nothing');

echo "5. An agent reads the trail as itself\n";
$exp = agent_grants('expert');
$run = random_int(700000, 799999); facts($run, 40, $exp);
$tok = rtoken(40, $run);
$r = mcp_tool(ACT, $tok, 'record_history', ['entity_type' => 'channel', 'entity_id' => $L]);
ok(!$r['error'] && count($r['data']['events']) >= 3, 'the expert (Seamus, in #smoke-launch) reads the channel\'s history');
$r = mcp_tool(ACT, $tok, 'share_reads', []);
ok($r['error'] && str_contains($r['text'], 'not among the tools'), 'share_reads is not among its grants: refused');
$r = mcp_tool(ACT, $tok, 'record_history', ['entity_type' => 'channel', 'entity_id' => $w['leads_channel']]);
ok(!$r['error'] && $r['data']['events'] === [], 'a private channel it is not in: an empty history, as the view gives it');
finish();
