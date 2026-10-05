<?php
/** Proof — status (spec "Proof", 3): set, shown in the people picker and on a DM, cleared by an empty text; a lapsed `until` is shown no more; refusals in words; logged status.set. */
require __DIR__ . '/lib.php';
$w = wiki_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30);
$picker = static fn (string $jar): string => page($jar, '/dm/new')['body'];

echo "1. Set and shown\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/settings/status.php', ['text' => 'SMOKE at the dentist', 'emoji' => '🦷']);
ok($c === 200 && $b['ok'] && $b['refresh'] === 'statusChanged' && $b['text'] === 'SMOKE at the dentist', 'status_set: the contract');
$l = activity('status.set', $since);
$after = json_decode((string) ($l[0]['after'] ?? '{}'), true);
ok(count($l) === 1 && $after['text'] === 'SMOKE at the dentist' && $after['emoji'] === '🦷', 'logged status.set with the person\'s own words in after.text');
$html = $picker($marco);
ok(preg_match('/id="dm-new-person-26-status">[^<]*SMOKE at the dentist/', $html) === 1 && str_contains($html, '🦷'), 'the people picker shows it beside her name');
[$c, $d] = screen($priya, '/settings/');
ok($d['status']['text'] === 'SMOKE at the dentist' && $d['status']['emoji'] === '🦷', 'the settings screen reads it back');
$dm = (int) one("SELECT c.id FROM channels c WHERE c.kind = 'dm' AND EXISTS (SELECT 1 FROM channel_members x WHERE x.channel_id = c.id AND x.member_id = 26) AND EXISTS (SELECT 1 FROM channel_members y WHERE y.channel_id = c.id AND y.member_id = 27) LIMIT 1");
if ($dm === 0) { [, $b] = act($marco, '/dm/open.php', ['member' => 26]); $dm = (int) ($b['record_id'] ?? 0); }
ok($dm > 0 && str_contains(page($marco, '/dm/')['body'], 'id="dm-card-' . $dm . '-status"'), 'a DM with her shows her status on its card');

echo "2. Until, lapsed and refused\n";
$until = date('Y-m-d\TH:i', time() + 3600);
[$c, $b] = act($priya, '/settings/status.php', ['text' => 'SMOKE back at three', 'until' => $until]);
ok($c === 200 && $b['until'] !== null && one('SELECT status_until FROM members WHERE id = 26') !== null, 'a future until is kept');
pdo()->exec("UPDATE members SET status_until = now() - interval '1 minute' WHERE id = 26");
ok(!str_contains($picker($marco), 'dm-new-person-26-status') && !str_contains(page($marco, '/dm/')['body'], 'id="dm-card-' . $dm . '-status"'), 'past `until`: not shown in the picker or on the DM (nothing clears the row)');
ok(one('SELECT status_text FROM members WHERE id = 26') === 'SMOKE back at three', 'the row is still there');
[$c, $b] = act($priya, '/settings/status.php', ['text' => 'SMOKE x', 'until' => '2020-01-01T10:00']);
ok($c === 422 && isset(fields($b)['until']) && str_contains(fields($b)['until'], 'future'), 'an until in the past: 422 on the field');
[$c, $b] = act($priya, '/settings/status.php', ['text' => str_repeat('x', 101)]);
ok($c === 422 && isset(fields($b)['text']), 'more than 100 characters: 422 on the field');
[$c, $b] = act($priya, '/settings/status.php', ['text' => 'ok', 'until' => 'whenever']);
ok($c === 422 && isset(fields($b)['until']), 'an until that is no time: 422 on the field');

echo "3. Cleared\n";
act($priya, '/settings/status.php', ['text' => 'SMOKE lunch', 'emoji' => '🍜']);
ok(str_contains($picker($marco), 'SMOKE lunch'), 'set again: shown');
$since = last_activity_id();
[$c, $b] = act($priya, '/settings/status.php', ['text' => '', 'emoji' => '']);
ok($c === 200 && one('SELECT status_text FROM members WHERE id = 26') === null && one('SELECT status_emoji FROM members WHERE id = 26') === null && one('SELECT status_until FROM members WHERE id = 26') === null, 'an empty text and emoji clear it');
ok(!str_contains($picker($marco), 'dm-new-person-26-status'), 'no longer shown');
$l = activity('status.set', $since);
ok(count($l) === 1 && json_decode((string) $l[0]['after'], true)['text'] === null, 'logged as cleared');
act($priya, '/settings/status.php', ['text' => 'SMOKE again']);
[$c, $b] = act($priya, '/settings/status.php', ['clear' => '1']);
ok($c === 200 && one('SELECT status_text FROM members WHERE id = 26') === null, 'clear=1 clears it too');
$r = req('POST', '/settings/status.php', ['jar' => $priya, 'form' => ['csrf_token' => page_csrf($priya), 'text' => 'SMOKE plain form', 'return_to' => '/settings/']]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=status_set') && one('SELECT status_text FROM members WHERE id = 26') === 'SMOKE plain form', 'JavaScript off: the plain form posts and lands with its notice');
act($priya, '/settings/status.php', ['clear' => '1']);
finish();
