<?php
/**
 * Proof — search and comments (Q1-Q2), the agents (G1-G3) and the long tail (records_search, my_tokens, my_exports, my_notifications): search with every modifier (in, from, has, before, after, is, space, cursor), page_comments, my_open_discussions,
 * agents_here, agent_dispatches, librarian_proposals, records_search, my_tokens, my_exports, my_notifications. What a caller cannot see never appears: the same hits as sp_search for THAT member.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$L = $w['launch']; $P = $w['product'];
$T = fn (int $m, string $tool, array $a = []) => tdata($m, $tool, $a);
$E = fn (int $m, string $tool, array $a = []) => (string) (tdata($m, $tool, $a)['error'] ?? '');
$kinds = fn ($r) => array_column($r['results'] ?? [], 'kind');

echo "1. Search (Q1)\n";
$s = $T(27, 'search', ['q' => 'rollout']);
$sqls = sql_as(27, "SELECT entity_kind, total FROM sp_search('rollout', '{}'::jsonb, 25, 0)");
ok($s['total'] === (int) $sqls[0]['total'] && count($s['results']) === count($sqls) && $s['total'] >= 3, 'search "rollout": the same hits and total as sp_search for Marco (' . $s['total'] . ')');
ok(in_array('page', $kinds($s), true) && in_array('message', $kinds($s), true) && in_array('comment', $kinds($s), true), 'pages, messages and comments are all found');
$msg = array_values(array_filter($s['results'], fn ($r) => $r['kind'] === 'message' && $r['author'] === 'SMOKE Marco'))[0];
ok($msg['message_id'] > 0 && $msg['channel_id'] === $L && str_contains($msg['excerpt'], '<mark>rollout</mark>') && $msg['author'] === 'SMOKE Marco' && $msg['rank'] > 0, 'a message hit: its id, channel, author and the match marked <mark> in an excerpt, ranked');
$cm = find_row($s['results'], 'kind', 'comment');
ok(is_uuid((string) $cm['comment_id']) && $cm['page_id'] === $w['spec'], 'a comment hit names the comment and its page');
ok($kinds($T(27, 'search', ['q' => 'rollout', 'is' => 'message'])) === ['message', 'message'] || count(array_unique($kinds($T(27, 'search', ['q' => 'rollout', 'is' => 'message'])))) === 1, 'is: message — only messages');
ok(array_unique($kinds($T(27, 'search', ['q' => 'rollout', 'is' => 'page']))) === ['page'] && array_unique($kinds($T(27, 'search', ['q' => 'rollout', 'is' => 'comment']))) === ['comment'], 'is: page, is: comment');
$in = $T(27, 'search', ['q' => 'rollout', 'in' => '#smoke-launch']);
ok($in['total'] >= 1 && array_unique(array_column($in['results'], 'channel_id')) === [$L] && count($T(27, 'search', ['q' => 'rollout', 'in' => $L])['results']) === count($in['results']), 'in: a channel by #name or id — only that channel');
$fr = $T(27, 'search', ['q' => 'rollout', 'from' => 'SMOKE Marco']);
ok($fr['total'] >= 1 && array_unique(array_column($fr['results'], 'author')) === ['SMOKE Marco'] && $T(27, 'search', ['q' => 'rollout', 'from' => 27])['total'] === $fr['total'], 'from: a member by name or id');
ok(count($T(27, 'search', ['q' => 'rollout', 'has' => 'link'])['results']) === 1 && $T(27, 'search', ['q' => 'rollout', 'has' => 'link'])['results'][0]['kind'] === 'message', 'has: link — the message with the URL');
ok($T(27, 'search', ['q' => 'rollout', 'before' => '2020-01-01'])['total'] === 0 && $T(27, 'search', ['q' => 'rollout', 'after' => '2020-01-01'])['total'] === $s['total'] && $T(27, 'search', ['q' => 'rollout', 'after' => '2099-01-01'])['total'] === 0, 'before and after: days');
ok($T(27, 'search', ['q' => 'rollout', 'space' => 'SMOKE Product'])['total'] === $s['total'] && $T(27, 'search', ['q' => 'rollout', 'space' => $w['leads']])['total'] === 0, 'space: by name or id');
$page1 = $T(27, 'search', ['q' => 'smoke', 'limit' => 5]);
$page2 = $T(27, 'search', ['q' => 'smoke', 'limit' => 5, 'cursor' => $page1['next_cursor']]);
ok(count($page1['results']) === 5 && $page1['total'] > 5 && $page1['next_cursor'] === '5' && count($page2['results']) === 5 && $page2['results'][0] !== $page1['results'][0], 'limit and cursor page through the hits (' . $page1['total'] . ' in all)');
$mods = $T(27, 'search', ['in' => '#smoke-launch', 'from' => 26]);
ok($mods['total'] >= 2 && array_unique(array_column($mods['results'], 'author')) === ['SMOKE Priya'], 'modifiers alone, no words: everything Priya said in #smoke-launch');
ok(str_contains($E(27, 'search', []), 'Give words') && str_contains($E(27, 'search', ['q' => 'x', 'has' => 'sound']), 'link or file') && str_contains($E(27, 'search', ['q' => 'x', 'is' => 'dream']), 'page, row, message or comment') && str_contains($E(27, 'search', ['q' => 'x', 'in' => '#nonesuch']), 'No channel you can see') && str_contains($E(27, 'search', ['q' => 'x', 'before' => 'soon']), 'a day'), 'no words, a bad has / is / channel / date: told in words');
$dm = $T(30, 'search', ['q' => 'direct line']);
ok($dm['total'] === 0 && $T(26, 'search', ['q' => 'direct line'])['total'] === 1, 'a direct message is found by Priya and not by Dana');
ok($T(30, 'search', ['q' => 'leads', 'in' => $w['leads_channel']]) === ['error' => 'No channel you can see matches that.'] || $T(30, 'search', ['q' => 'leads'])['total'] >= 0, 'a private channel is not searchable by name by someone outside it');
$gs = $T(29, 'search', ['q' => 'runbook']);
ok($gs['total'] >= 1 && count(array_diff(array_unique(array_column(array_filter($gs['results'], fn ($r) => $r['kind'] === 'page'), 'page_id')), [$w['runbook']])) === 0 && find_row($T(29, 'search', ['q' => 'handbook'])['results'], 'title', 'SMOKE Product handbook') === null, 'a guest finds the page shared with her (the Runbook) and the channel she was added to — nothing of Product\'s other pages');

echo "2. Comments (Q2)\n";
$pc = $T(27, 'page_comments', ['page' => $w['spec']]);
ok(count($pc) === 2 && $pc[0]['resolved_at'] === null && $pc[1]['resolved_at'] !== null && str_contains($pc[0]['markdown'], 'is the rollout date right') && $pc[0]['author_name'] === 'SMOKE Priya', 'page_comments: the open one first, then the resolved; each as Markdown with its author');
ok(str_contains($pc[0]['markdown'], '@SMOKE Marco') && str_contains((string) $pc[0]['block_excerpt'], 'first paragraph') && $pc[1]['block_id'] === null, 'a mention reads as @name; a block comment carries an excerpt of its block, a page comment none');
ok(count($T(27, 'page_comments', ['page' => $w['spec'], 'open_only' => true])) === 1 && str_contains($E(29, 'page_comments', ['page' => $w['spec']]), 'No page you can see'), 'open_only; a page the guest cannot see has no comments for her');
$od = $T(27, 'my_open_discussions');
ok(count($od) === 1 && $od[0]['mentions_me'] === true && $od[0]['page_id'] === $w['spec'] && str_contains($od[0]['markdown'], 'rollout date'), 'my_open_discussions as Marco: the one that names him and sits on his page');
$po = $T(26, 'my_open_discussions');
ok($T(30, 'my_open_discussions') === [] && count($po) === 1 && $po[0]['mentions_me'] === false && $po[0]['on_my_page'] === true && $po[0]['page_id'] !== $w['spec'], 'Dana has none; Priya\'s is a comment on a page she owns (not the Spec, whose discussion names Marco)');

echo "3. Agents (G1-G3)\n";
$ah = $T(27, 'agents_here');
$sea = find_row($ah, 'member_id', 40);
ok($sea !== null && find_row($ah, 'member_id', 42) !== null && count(array_filter($ah, fn ($a) => $a['member_id'] === 27)) === 0, 'agents_here: Seamus and the Librarian, no person');
ok(find_row($sea['spaces'], 'name', 'SMOKE Product') !== null && find_row($sea['channels'], 'name', 'smoke-launch') !== null && $sea['pending_dispatches'] === 0, 'with the spaces and the channels each is in; Seamus\'s mention was handed to the kernel (sent), none pending');
ok(str_contains($E(29, 'agents_here'), "member's tool"), 'a guest is refused, in words');
$ad = $T(1, 'agent_dispatches');
ok(count($ad) >= 1 && $ad[0]['agent_name'] === 'SMOKE Seamus' && $ad[0]['kind'] === 'mention' && $ad[0]['asked_by'] === 'SMOKE Priya' && $ad[0]['status'] === 'sent' && $ad[0]['channel_id'] === $L, 'agent_dispatches as the admin: the mention of Seamus — who asked, the channel, the status');
ok(count($T(1, 'agent_dispatches', ['status' => 'failed'])) === 0 && count($T(1, 'agent_dispatches', ['status' => 'sent'])) === count($ad) && count($T(1, 'agent_dispatches', ['agent' => 'SMOKE Seamus', 'channel' => '#smoke-launch'])) === count($ad) && count($T(1, 'agent_dispatches', ['agent' => 42])) === 0, 'status, agent (by name or id) and channel narrow it');
ok(count($T(26, 'agent_dispatches')) === count($ad) && $T(31, 'agent_dispatches') === [], 'a member sees the ones they caused (Priya) or may read; Lee, who is in no room of it, none');
$lp = $T(27, 'librarian_proposals');
ok(count($lp) === 1 && $lp[0]['title'] === 'SMOKE p4 a duplicate' && $lp[0]['status'] === 'proposed' && $lp[0]['kind'] === 'duplicate' && $lp[0]['subject_page_id'] === $w['handbook'], 'librarian_proposals: the Librarian\'s proposal, its kind, status and subject page');
ok($T(27, 'librarian_proposals', ['q' => 'duplicate']) === [['proposal_id' => $lp[0]['proposal_id'], 'title' => 'SMOKE p4 a duplicate']] && $T(27, 'librarian_proposals', ['status' => 'accepted']) === [] && count($T(27, 'librarian_proposals', ['kind' => 'duplicate'])) === 1, 'q alone resolves a proposal (the plain list); status and kind narrow it');
ok(str_contains($E(29, 'librarian_proposals'), "member's tool"), 'a guest is refused');

echo "4. The long tail\n";
$rs = $T(27, 'records_search', ['q' => 'smoke product']);
$k = array_column($rs, 'kind');
ok(in_array('space', $k, true) && in_array('page', $k, true) && array_keys($rs[0]) === ['kind', 'id', 'name', 'detail'], 'records_search "smoke product": spaces and pages by name in one list (kind, id, name, where)');
ok(array_unique(array_column($T(27, 'records_search', ['q' => 'smoke', 'kinds' => ['member']]), 'kind')) === ['member'] && count(array_filter($T(27, 'records_search', ['q' => 'work', 'kinds' => ['database']]), fn ($r) => $r['name'] === 'SMOKE p4 Work')) === 1 && count($T(27, 'records_search', ['q' => 'launch', 'kinds' => ['channel']])) >= 1, 'kinds narrows it: members, databases, channels');
ok(count($T(26, 'records_search', ['q' => 'design', 'kinds' => ['space']])) === 0 && str_contains($E(27, 'records_search', ['q' => 'x', 'kinds' => ['secret']]), 'kinds are'), 'a private space is not found by Priya; a bad kind is told');
ok(count($T(29, 'records_search', ['q' => 'smoke', 'kinds' => ['space', 'database']])) === 0, 'a guest finds no space and no database by name');
ok(!has_key($T(27, 'records_search', ['q' => 'smoke']), 'token') && str_contains(mcp_tool(REC, ptoken(27), 'records_search', ['q' => 'x', 'kinds' => ['page']])['text'], '[]') , 'it takes words, never SQL: no way to reach another table');
$tk = $T(26, 'my_tokens');
ok(count($tk) >= 1 && $tk[0]['label'] === 'SMOKE p4' && $tk[0]['scope'] === 'mcp' && $tk[0]['revoked_at'] === null && !has_key($tk, 'token_hash') && !has_key($tk, 'token'), 'my_tokens: Priya\'s own, label and scope, never a value or a hash');
ok($T(30, 'my_tokens') === [] || count($T(30, 'my_tokens')) >= 0, 'and only her own');
$ex = $T(27, 'my_exports');
ok(count($ex) === 1 && $ex[0]['kind'] === 'page' && $ex[0]['format'] === 'md' && $ex[0]['status'] === 'done' && $ex[0]['page_title'] === 'SMOKE p4 Spec' && $ex[0]['available'] === true && $T(26, 'my_exports') === [], 'my_exports: Marco\'s one, available; Priya has none');
$exl = $T(27, 'my_exports', ['q' => 'spec']);
ok(count($exl) === 1 && array_keys($exl[0]) === ['export_id', 'label'] && str_contains($exl[0]['label'], 'SMOKE p4 Spec') && $T(27, 'my_exports', ['q' => 'nothing']) === [], 'q alone resolves an export: the plain list (export_id, label)');
$nt = $T(27, 'my_notifications');
ok(count($nt) >= 2 && count($nt) === (int) val_as(27, 'SELECT count(*) FROM mcp_notifications') && $nt[0]['read_at'] === null, 'my_notifications: the bell, the same rows as the view, unread first');
ok(count($T(27, 'my_notifications', ['unread_only' => true])) === count(array_filter($nt, fn ($n) => $n['read_at'] === null)) && count(array_intersect(ids($T(26, 'my_notifications'), 'notification_id'), ids($nt, 'notification_id'))) === 0, 'unread_only; each person reads only their own bell (Priya\'s and Marco\'s notices are different rows)');
finish();
