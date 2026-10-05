<?php
/** Proof — the wiki's reports and the nudge (spec "Proof", 7): the seven lists with the right pages; a page she may not see absent from Priya's; Nudge queues one `verification` notice with the page in record_uuid and logs page.nudge; a second the same week queues nothing and says so; a non-owner is refused; the settings' numbers are shown; JavaScript off nudges. */
require __DIR__ . '/lib.php';
$w = wiki_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $owner = as_member(1);
$product = $w['product'];
$ids = static fn (array $d, string $list): array => array_column($d['lists'][$list], 'page_id');
$report = '/spaces/' . $product . '/wiki/report';

echo "1. The seven lists\n";
[$c, $d] = screen($marco, $report);
ok($c === 200 && array_keys($d['lists']) === ['expired', 'unverified', 'stale', 'orphans', 'broken', 'duplicates', 'unanswered'], 'the screen answers JSON with the seven lists');
ok(in_array($w['expired'], $ids($d, 'expired'), true) && !in_array($w['verified'], $ids($d, 'expired'), true) && !in_array($w['never'], $ids($d, 'expired'), true), 'Expired: the page whose date has passed; not the fresh one, not the unverified one');
ok(in_array($w['never'], $ids($d, 'unverified'), true) && !in_array($w['verified'], $ids($d, 'unverified'), true) && !in_array($w['expired'], $ids($d, 'unverified'), true), 'Never verified: the page nobody verified');
ok(in_array($w['stale'], $ids($d, 'stale'), true) && in_array($w['hidden'], $ids($d, 'stale'), true) && !in_array($w['never'], $ids($d, 'stale'), true), 'Stale: the 200-day-old pages (Marco sees both), not a page edited today');
ok(in_array($w['orphan'], $ids($d, 'orphans'), true) && !in_array($w['never'], $ids($d, 'orphans'), true), 'Orphans: the page nothing links to');
$bk = array_values(array_filter($d['lists']['broken'], static fn (array $b): bool => $b['page_id'] === $w['linker']));
if (count($bk) !== 1) { echo '       broken: ' . json_encode($d['lists']['broken']) . "\n"; }
if (count($bk) !== 1) { echo '       broken: ' . json_encode($d['lists']['broken']) . "\n"; }
ok(count($bk) === 1 && $bk[0]['reason'] === 'in the trash' && $bk[0]['points_to']['page_id'] === $w['target_id'], 'Broken links: the page, where it points, why (in the trash)');
$dup = array_values(array_filter($d['lists']['duplicates'], static fn (array $x): bool => $x['title'] === 'smoke how we deploy'));
ok(count($dup) === 1 && $dup[0]['count'] === 2 && count($dup[0]['pages']) === 2 && $dup[0]['pages'][0]['space']['name'] === 'SMOKE Product', 'Duplicates: the title and both pages');
$q = array_values(array_filter($d['lists']['unanswered'], static fn (array $x): bool => str_contains($x['excerpt'], 'deploy runbook')));
ok(count($q) === 1 && $q[0]['channel']['name'] === 'smoke-launch' && $q[0]['hours_open'] >= 29, 'Unanswered: the 30-hour-old question in #smoke-launch');
ok(array_filter($d['lists']['unanswered'], static fn (array $x): bool => str_contains($x['excerpt'], 'staging key')) === [], 'the answered one is not there');
ok(array_sum($d['counts']) >= 8 && $d['counts']['expired'] === count($d['lists']['expired']), 'the counts match the lists');
$r = page($marco, $report);
ok($r['code'] === 200 && str_contains($r['body'], 'id="wiki-report-content"'), 'the page answers');
foreach (['expired', 'unverified', 'stale', 'orphans', 'broken', 'duplicates', 'unanswered'] as $k) {
    if (!str_contains($r['body'], 'id="wiki-list-' . $k . '"') || !preg_match('/id="wiki-list-' . $k . '-count">' . count($d['lists'][$k]) . '</', $r['body'])) { ok(false, "list $k: its section and its count in the heading"); continue; }
}
ok(preg_match_all('/id="wiki-list-(expired|unverified|stale|orphans|broken|duplicates|unanswered)-count">\d+</', $r['body']) === 7, 'seven sections, each with its count in the heading');
ok(str_contains($r['body'], 'id="wiki-link-expired-' . $w['expired'] . '"') && str_contains($r['body'], 'href="/pages/' . $w['expired'] . '?back='), 'each page is a link, with the way back');

echo "2. The numbers, and who sees what\n";
$st = q('SELECT stale_page_days, unanswered_hours FROM sp_settings WHERE id = 1')[0];
ok($d['numbers']['stale_days'] === (int) $st['stale_page_days'] && $d['numbers']['unanswered_hours'] === (int) $st['unanswered_hours'] && $d['numbers']['verify_months'] === 6, 'the settings\' numbers are in the JSON (stale days, unanswered hours, the verify window)');
ok(str_contains($r['body'], 'id="wiki-report-stale-days"') && preg_match('/Stale after <strong>' . (int) $st['stale_page_days'] . '</', $r['body']) && str_contains($r['body'], 'Verify every <strong>6</strong>'), 'and in the header');
[$c, $dp] = screen($priya, $report);
ok($c === 200 && in_array($w['stale'], $ids($dp, 'stale'), true) && !in_array($w['hidden'], $ids($dp, 'stale'), true) && !in_array($w['hidden'], $ids($dp, 'unverified'), true), 'Priya: the stale page is there, Marco\'s private one is not');
ok(!str_contains(page($priya, $report)['body'], $w['hidden']), 'nor anywhere in her page');
[$c, $dd] = screen($dana, $report);
ok($c === 200 && array_filter($dd['lists']['broken'], static fn (array $b): bool => $b['page_id'] === $w['linker']) !== [], 'Dana (a member) sees the broken link');
[$c, $x] = screen($marco, '/spaces/999999/wiki/report');
ok($c === 404, 'a space that does not exist: 404');
$nowiki = (int) one('SELECT id FROM spaces WHERE NOT is_wiki ORDER BY id LIMIT 1');
[$c, $x] = screen($marco, '/spaces/' . $nowiki . '/wiki/report');
ok($c === 404, 'a space that is not a wiki: 404');

echo "3. Nudge\n";
$since = last_activity_id(); $n0 = last_note_id();
$exp = $w['expired'];
ok(str_contains(page($marco, $report)['body'], 'id="nudge-' . $exp . '"') && !str_contains(page($priya, $report)['body'], 'id="nudge-' . $exp . '"'), 'the owner of the space sees Nudge; a plain member does not');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $exp, 'reason' => 'expired']);
ok($c === 200 && $b['ok'] && $b['queued'] === true && $b['record_id'] === $exp && $b['refresh'] === 'wikiChanged' && isset($b['location'], $b['did']), 'wiki_nudge_send: the contract and queued=true');
$n = q("SELECT id, kind, title, body, record_uuid::text AS u FROM notifications WHERE member_id = 26 AND kind = 'verification' AND id > :n", ['n' => $n0]);
ok(count($n) === 1 && $n[0]['u'] === $exp && $n[0]['title'] === 'Please look at "SMOKE Wiki Expired"' && str_starts_with($n[0]['body'], 'It expired on '), 'one verification notice for her, the page in record_uuid: "' . ($n[0]['title'] ?? '') . '" / "' . ($n[0]['body'] ?? '') . '"');
$l = activity('page.nudge', $since);
$after = json_decode((string) ($l[0]['after'] ?? '{}'), true);
ok(count($l) === 1 && $l[0]['entity_uuid'] === $exp && (int) $l[0]['space_id'] === $product && !str_contains(json_encode($l[0]), 'Please look'), 'logged page.nudge with entity_uuid and space_id, no words of the notice');
ok(str_contains((string) ($l[0]['after'] ?? ''), '26') || str_contains((string) ($l[0]['before'] ?? '') . json_encode($l[0]), '26'), 'and the member nudged');
$r = req('GET', '/notifications', ['jar' => $priya, 'headers' => JSONH]);
$mine = array_values(array_filter(json_decode($r['body'], true)['data']['notifications'], static fn (array $x): bool => $x['kind'] === 'verification' && str_contains($x['title'], 'SMOKE Wiki Expired')));
if (count($mine) !== 1) { echo '       mine: ' . json_encode($mine) . "\n"; }
ok(count($mine) === 1 && $mine[0]['url'] === '/pages/' . $exp, 'it is on her bell, linking to the page');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $exp]);
ok($c === 200 && $b['ok'] && $b['queued'] === false && str_contains($b['did'], 'Already nudged this week'), 'a second nudge this week: ok, queued=false, and it says so: "' . ($b['did'] ?? '') . '"');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'verification' AND record_uuid = CAST(:p AS uuid) AND id > :n", ['p' => $exp, 'n' => $n0]) === 1 && count(activity('page.nudge', $since)) === 1, 'nothing more was queued or logged');
$r = req('POST', '/spaces/wiki/nudge.php', ['jar' => $marco, 'form' => ['csrf_token' => page_csrf($marco), 'page' => $exp, 'return_to' => $report]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=already'), 'JavaScript off: the plain form lands back on the report saying so (' . $r['location'] . ')');
$mail = q("SELECT channel, kind, body FROM notification_outbox WHERE member_id = 26 AND kind = 'verification' AND dedupe_key LIKE :k", ['k' => 'wiki_nudge:' . $exp . '%']);
ok(count($mail) <= 1, 'at most one outbox row for the key (an away member gets the email once)');

echo "4. Reasons, the member named, refusals\n";
$nv = $w['never']; $n0 = last_note_id();
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $nv]);
$n = q("SELECT body FROM notifications WHERE member_id = 26 AND kind = 'verification' AND record_uuid = CAST(:p AS uuid) AND id > :n", ['p' => $nv, 'n' => $n0]);
ok($c === 200 && $b['queued'] && ($n[0]['body'] ?? '') === 'It was never verified.', 'the reason is read from the page: never verified');
$st = $w['stale']; $n0 = last_note_id();
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $st, 'reason' => 'stale']);
$n = q("SELECT body FROM notifications WHERE member_id = 26 AND record_uuid = CAST(:p AS uuid) AND id > :n", ['p' => $st, 'n' => $n0]);
ok($c === 200 && str_starts_with($n[0]['body'] ?? '', 'It has not been edited in 2') && str_ends_with($n[0]['body'] ?? '', ' days.'), 'stale: "' . ($n[0]['body'] ?? '') . '"');
$vf = $w['verified']; $n0 = last_note_id();
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $vf, 'member' => 30]);
ok($c === 200 && $b['queued'] && $b['member_id'] === 30 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 30 AND kind = 'verification' AND record_uuid = CAST(:p AS uuid) AND id > :n", ['p' => $vf, 'n' => $n0]) === 1, '`member` names another person than the owner');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $vf, 'member' => 40]);
ok($c === 422 && str_contains(msg($b), 'agent'), 'an agent cannot be nudged (it is dispatched, not notified): "' . msg($b) . '"');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $vf, 'member' => 27]);
ok($c === 422 && str_contains(msg($b), 'nobody else'), 'nudging oneself: refused in words');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $w['hidden'], 'member' => 30]);
ok($c === 422 && str_contains(msg($b), 'cannot see'), 'someone who cannot see the page: refused in words: "' . msg($b) . '"');
[$c, $b] = act($priya, '/spaces/wiki/nudge.php', ['page' => $exp]);
ok($c === 403, 'a non-owner of the space: 403');
[$c, $b] = act($dana, '/spaces/wiki/nudge.php', ['page' => $w['hidden']]);
ok($c === 404, 'a page she cannot see: 404');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => 'not-a-uuid']);
ok($c === 422, 'no page: 422');
[$c, $b] = act($marco, '/spaces/wiki/nudge.php', ['page' => $vf, 'reason' => 'because']);
ok($c === 422 && isset(fields($b)['reason']), 'an unknown reason: 422 on the field');
$plain = mk_in($owner, $nowiki, 'SMOKE not a wiki page');
[$c, $b] = act($owner, '/spaces/wiki/nudge.php', ['page' => $plain]);
ok($c === 422 && str_contains(msg($b), 'not a wiki'), 'a page in a space that is not a wiki is not nudged: "' . msg($b) . '"');
$tok = ['X-Action-Token: ' . person_token(27)];
[$c, $b] = act_token('/spaces/wiki/nudge.php', ['page' => $w['linker'], 'member' => 30], $tok);
ok($c === 200 && $b['ok'] && array_key_exists('record_id', $b) && isset($b['location'], $b['did'], $b['refresh']), 'under an action token the contract is the same');
finish();
