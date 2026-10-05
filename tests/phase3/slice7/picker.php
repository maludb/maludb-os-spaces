<?php
/** Proof — the picker (spec "Proof", 1): `@` lists Seamus with the agent chip and not Watcher; a DM to Seamus shows the chip in its header. */
require __DIR__ . '/lib.php';
$w = agents_world();
$priya = as_member(26);
$launch = $w['launch'];

echo "1. The mention picker\n";
$r = req('GET', '/channels/mentions.php?channel=' . $launch . '&q=SMOKE', ['jar' => $priya, 'headers' => JSONH]);
$cand = json_decode($r['body'], true)['data']['candidates'] ?? [];
$by = array_column($cand, null, 'name');
ok($r['code'] === 200 && isset($by['SMOKE Seamus']) && $by['SMOKE Seamus']['kind'] === 'agent' && $by['SMOKE Seamus']['hint'] === 'agent' && $by['SMOKE Seamus']['id'] === '40', '`@` lists Seamus as kind agent with the hint "agent"');
ok(isset($by['SMOKE Librarian']) && $by['SMOKE Librarian']['kind'] === 'agent', 'the Librarian is listed too');
ok(!isset($by['SMOKE Watcher']), 'the Watcher (an agent the kernel never vouched for) is not offered');
ok(isset($by['SMOKE Marco']) && $by['SMOKE Marco']['kind'] === 'member', 'people are listed beside them as members');
$r = req('GET', '/channels/mentions.php?channel=' . $launch . '&q=Watcher', ['jar' => $priya, 'headers' => JSONH]);
ok(json_decode($r['body'], true)['data']['candidates'] === [], 'asking for Watcher by name finds no one');

echo "2. The composer carries the chip\n";
$r = page($priya, '/channels/' . $launch);
ok($r['code'] === 200 && str_contains($r['body'], 'sp-composer-input') && str_contains($r['body'], '@ to mention'), 'the composer offers `@` to mention');
$js = file_get_contents(dirname(__DIR__, 3) . '/html/assets/js/channel.js');
ok(str_contains($js, "/channels/mentions.php?channel=") && str_contains($js, "c.kind === 'agent'") && str_contains($js, 'badge bg-soft-info text-info ms-2 sp-mention-chip">agent'), 'the picker script shows an agent candidate with the agent chip (the browser proof clicks it)');

echo "3. A DM to an agent shows the chip in its header\n";
[$c, $b] = act($priya, '/dm/open.php', ['member' => 40]);
$dm = (int) $b['record_id'];
$r = page($priya, '/dm/' . $dm);
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Seamus <span class="badge bg-soft-info text-info">agent</span>'), 'the DM header: "SMOKE Seamus" with the agent chip');
$r = page($priya, '/dm/');
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Seamus'), 'the DM list names the agent');
[$c, $b] = act($priya, '/dm/open.php', ['member' => 41]);
ok($c >= 400, 'a DM to the Watcher (not admitted) is refused: ' . $c . ' ' . msg($b));
finish();
