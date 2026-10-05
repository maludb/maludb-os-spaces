<?php
/** Proof — DMs (spec "Proof", 4): the pair once, a third refused, a group found again, a guest's reach, a DM notifies. */
require __DIR__ . '/lib.php';
$w = channel_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $ann = as_member(29); $bea = as_member(28);
pdo()->exec("UPDATE members SET last_seen_at = now() - interval '3 hours' WHERE id = 26");

echo "1. A direct message\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/dm/open.php', ['member' => 26]);
$dm = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $dm > 0 && ($b['location'] ?? '') === '/dm/' . $dm && channel_row($dm)['kind'] === 'dm' && in_channel($dm, 27) && in_channel($dm, 26), 'dm_open makes the pair');
[$c, $b] = act($priya, '/dm/open.php', ['member' => 27]);
ok($c === 200 && (int) $b['record_id'] === $dm && ($b['existing'] ?? false) === true, 'opened from the other side: the same conversation');
ok(count(activity('channel.create', $since)) === 1, 'channel.create logged once');
try { as_viewer(27); one('INSERT INTO channel_members (channel_id, member_id) VALUES (' . $dm . ', 30)'); ok(false, 'a third person refused'); } catch (PDOException $e) { ok(str_contains($e->getMessage(), 'exactly two people'), 'a third person: the database refuses in words'); }
[$c, $b] = act($marco, '/channels/members/add.php', ['channel' => $dm, 'member' => 30]);
ok($c === 422, 'and through the handler');
[$c, $b] = act($marco, '/dm/open.php', ['member' => 27]);
ok($c === 422 && msg($b) === 'A direct message is with someone else', 'with oneself: the sentence');
$n0 = last_note_id();
[$c, $b] = post($marco, $dm, 'Hi Priya, privately');
$m = (int) $b['record_id'];
ok($c === 200 && count(notes(26, 'dm', $n0)) === 1 && str_contains(notes(26, 'dm', $n0)[0]['title'], 'New message from SMOKE Marco'), 'a DM notifies the other');
ok(page($dana, '/dm/' . $dm)['code'] === 404 && page($dana, '/channels/' . $dm)['code'] === 404, 'nobody else reads it (the admin neither)');
ok(page(as_member(1), '/dm/' . $dm)['code'] === 404, 'the admin: 404 on a DM they are not in');
$r = page($priya, '/dm/' . $dm);
ok($r['code'] === 200 && str_contains($r['body'], 'id="message-row-' . $m . '"') && str_contains($r['body'], 'SMOKE Marco') && str_contains($r['body'], 'id="composer"'), 'Priya reads it as a channel view with the composer');
[$c, $d] = screen($priya, '/dm/');
ok($c === 200 && count(array_filter($d['dms'], fn ($x) => $x['channel_id'] === $dm)) === 1 && $d['dms'][0]['names'] === 'SMOKE Marco', 'dm-list shows it with the other\'s name');
[$c, $b] = act($marco, '/channels/leave.php', ['channel' => $dm]);
ok($c === 422, 'nobody leaves a DM');

echo "2. A group\n";
[$c, $b] = act($marco, '/dm/open-group.php', ['members' => [26, 30]]);
$g = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $g > 0 && channel_row($g)['kind'] === 'group_dm' && (int) one('SELECT count(*) FROM channel_members WHERE channel_id = :c', ['c' => $g]) === 3, 'a group of three');
[$c, $b] = act($dana, '/dm/open-group.php', ['members' => [27, 26]]);
ok($c === 200 && (int) $b['record_id'] === $g, 'found again by its members from another side');
[$c, $b] = act($marco, '/dm/open-group.php', ['members' => [26]]);
ok($c === 422 && isset(fields($b)['members']), 'one other: 422');
[$c, $b] = act($dana, '/channels/members/add.php', ['channel' => $g, 'member' => 31]);
ok($c === 200 && in_channel($g, 31), 'a member adds a fourth');
[$c, $b] = act($dana, '/channels/leave.php', ['channel' => $g]);
ok($c === 200 && !in_channel($g, 30), 'and may leave a group');

echo "3. A guest's reach\n";
[$c, $b] = act($ann, '/dm/open.php', ['member' => 28]);
ok($c === 422 && msg($b) === 'That member is not someone you can see', 'Ann cannot DM Bea (not someone she can see)');
[$c, $b] = act($ann, '/dm/open.php', ['member' => 27]);
ok($c === 200, 'she DMs Marco (in #smoke-launch with her)');
$adm = (int) $b['record_id'];
[$c, $b] = post($ann, $adm, 'Hello from the guest');
ok($c === 200, 'and writes');
finish();
