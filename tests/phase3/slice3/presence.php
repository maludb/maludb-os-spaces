<?php
/** Proof — presence (spec "Proof", 7): two sessions see each other; a save shows as changed to the other; the heartbeat sets last_seen_at. */
require __DIR__ . '/lib.php';
$w = editor_world();
$marco = as_member(27); $priya = as_member(26); $ann = as_member(29);
[, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Presence', 'space' => $w['product'], 'markdown' => 'watch me']);
$pp = (string) $b['record_id'];
$rev = content_rev($pp);
@unlink(dirname(__DIR__, 3) . '/storage/presence/' . $pp . '.json');

echo "1. Two sessions\n";
pdo()->exec("UPDATE members SET last_seen_at = NULL WHERE id = 27");
[$c, $b] = act($marco, '/pages/presence.php', ['page' => $pp, 'content_rev' => $rev]);
ok($c === 200 && ($b['data']['others'] ?? null) === [] && ($b['data']['changed'] ?? true) === false && ($b['data']['content_rev'] ?? 0) === $rev, 'Marco alone: no others, nothing changed');
ok(one('SELECT last_seen_at FROM members WHERE id = 27') !== null, 'the heartbeat set last_seen_at');
[$c, $b] = act($priya, '/pages/presence.php', ['page' => $pp, 'content_rev' => $rev]);
ok($c === 200 && count($b['data']['others'] ?? []) === 1 && ($b['data']['others'][0]['name'] ?? '') === 'SMOKE Marco', 'Priya sees Marco here');
[$c, $b] = act($marco, '/pages/presence.php', ['page' => $pp, 'content_rev' => $rev]);
ok(count($b['data']['others'] ?? []) === 1 && ($b['data']['others'][0]['member_id'] ?? 0) === 26, 'Marco sees Priya');
$file = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/storage/presence/' . $pp . '.json'), true);
ok(isset($file['26'], $file['27']), 'the file cache holds both (never the database)');

echo "2. A save shows as changed to the other\n";
$blk = root_blocks($pp)[0]['id'];
[$c, $b] = act($marco, '/blocks/update.php', ['block' => $blk, 'version' => 1, 'content' => para('watch me now')]);
$newRev = (int) $b['content_rev'];
[$c, $b] = act($priya, '/pages/presence.php', ['page' => $pp, 'content_rev' => $rev]);
ok($c === 200 && ($b['data']['changed'] ?? false) === true && ($b['data']['by'] ?? '') === 'SMOKE Marco' && ($b['data']['content_rev'] ?? 0) === $newRev, 'Priya\'s next heartbeat: changed by SMOKE Marco, the new content_rev');
[$c, $b] = act($priya, '/pages/presence.php', ['page' => $pp, 'content_rev' => $newRev]);
ok(($b['data']['changed'] ?? true) === false, 'once she has it, nothing changed');
act($marco, '/pages/lock.php', ['page' => $pp, 'locked' => 'yes']);
[$c, $b] = act($priya, '/pages/presence.php', ['page' => $pp, 'content_rev' => $newRev]);
ok(($b['data']['locked'] ?? false) === true, 'a lock reaches the other within a heartbeat');
act($marco, '/pages/lock.php', ['page' => $pp, 'locked' => 'no']);

echo "3. Who may heartbeat\n";
[$c, $b] = act($ann, '/pages/presence.php', ['page' => $pp, 'content_rev' => 0]);
ok($c === 404, 'Ann (cannot see the page): 404');
$r = req('POST', '/pages/presence.php', ['form' => ['page' => $pp], 'headers' => ['Accept: application/json']]);
ok($r['code'] === 401 || $r['code'] === 403 || $r['code'] === 302, 'anonymous: refused');
$r = req('GET', '/pages/presence.php?page=' . $pp, ['jar' => $marco]);
ok($r['code'] === 405, 'GET: 405');
file_put_contents(dirname(__DIR__, 3) . '/storage/presence/' . $pp . '.json', json_encode(['26' => ['name' => 'SMOKE Priya', 'seen' => time() - 60], '27' => ['name' => 'SMOKE Marco', 'seen' => time()]]));
[$c, $b] = act($marco, '/pages/presence.php', ['page' => $pp, 'content_rev' => $newRev]);
ok(($b['data']['others'] ?? null) === [], 'a stale entry (60 s) is dropped');
finish();
