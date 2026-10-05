<?php
/** Proof — seeds and cards (spec "Proof", 1): General open and default; IT and Accounting closed with the manager as owner; each person's three groups; a guest sees no card; the admin sees every space, the private one marked. */
require __DIR__ . '/lib.php';
$w = spaces_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $owner = as_member(1); $dana = as_member(30);

echo "1. The seeds\n";
$g = q('SELECT kind, is_default, everyone_level, default_channel_id FROM spaces WHERE id = :g', ['g' => $w['general']])[0];
ok($g['kind'] === 'open' && $g['is_default'] && $g['everyone_level'] === 'view' && $g['default_channel_id'] !== null, 'General is open, the default, everyone at view, with its #general');
ok($w['it'] !== null && $w['accounting'] !== null && q('SELECT kind, department_id FROM spaces WHERE id = :s', ['s' => $w['it']])[0] === ['kind' => 'closed', 'department_id' => 5], 'IT and Accounting are seeded closed, tied to their departments');
ok((int) one("SELECT count(*) FROM space_members WHERE space_id = :s AND member_id = 1 AND role = 'owner'", ['s' => $w['it']]) === 1, 'the owner, manager of IT, owns the IT space (db/006)');
ok((int) one("SELECT count(*) FROM space_members WHERE space_id = :s", ['s' => $w['accounting']]) === 0 && (int) one('SELECT count(*) FROM sp_space_member_ids(:s)', ['s' => $w['accounting']]) === 1, 'Accounting has no owner yet (no manager) and one derived member, Bea');
ok(q('SELECT slug, icon FROM spaces WHERE id = :s', ['s' => $w['product']])[0] === ['slug' => 'smoke-product', 'icon' => '🚀'], 'Product (the world) has the slug smoke-product and its icon');

echo "2. The cards per person\n";
$names = fn (array $g) => array_map(fn ($s) => $s['name'], $g);
[$c, $d] = screen($priya, '/spaces/');
ok($c === 200 && $names($d['mine']) === ['General', 'SMOKE Product'] && $d['open'] === [] && in_array('Accounting', $names($d['closed']), true) && in_array('IT', $names($d['closed']), true) && !in_array('SMOKE Design leads', $names($d['closed']), true), 'Priya: Mine = General and Product; nothing open to join; Closed = Accounting and IT (never the private one)');
[$c, $d] = screen($bea, '/spaces/');
ok($names($d['mine']) === ['General', 'Accounting'] && in_array('SMOKE Product', $names($d['closed']), true), 'Bea: Mine = General and Accounting (derived, through the department); Product is closed to her');
[$c, $d] = screen($marco, '/spaces/');
ok($names($d['mine']) === ['General', 'SMOKE Design leads', 'SMOKE Product'], 'Marco: Mine holds General, Design leads (private, his) and Product');
$card = array_values(array_filter($d['mine'], fn ($s) => $s['name'] === 'SMOKE Product'))[0];
ok($card['i_am_owner'] === true && $card['member_count'] === 2 && $card['channel_count'] === 1 && $card['page_count'] === 0 && $card['kind'] === 'closed', 'his Product card: owner, 2 members, 1 channel, 0 pages, closed');
[$c, $d] = screen($ann, '/spaces/');
ok($c === 403, 'Ann (guest) may not list spaces: 403 ("You may not join spaces.")');
ok(page($ann, '/spaces/' . $w['product'])['code'] === 404 && page($ann, '/spaces/' . $w['general'])['code'] === 404, 'nor open one: 404 (she sees only where she was added)');
[$c, $d] = screen($owner, '/spaces/');
ok(in_array('SMOKE Design leads', $names($d['mine']), true) && in_array('SMOKE Product', $names($d['closed']), true), 'the admin: every space — the private one under Mine (marked), Product under Closed (not a member)');
$h = page($owner, '/spaces/')['body'];
ok(str_contains($h, 'id="space-list-mine"') && str_contains($h, 'id="space-list-open"') && str_contains($h, 'id="space-list-closed"') && preg_match('/id="space-card-' . $w['leads'] . '-kind">private</', $h) === 1, 'the screen: three groups; the private card wears its chip');
[$c, $d] = screen($priya, '/spaces/?q=prod');
ok(count($d['mine']) === 1 && $d['mine'][0]['name'] === 'SMOKE Product' && $d['closed'] === [], 'search by a part of the name');
[$c, $d] = screen($priya, '/spaces/?kind=closed');
ok($d['mine'] !== [] && $d['mine'][0]['name'] === 'SMOKE Product' && count($d['closed']) === 2, 'filter by kind');
ok(page($dana, '/spaces/' . $w['product'])['code'] === 200 && str_contains(page($dana, '/spaces/' . $w['product'])['body'], 'id="space-view-request-btn"'), 'Dana (not a member) opens the closed Product: its home with Request to join');
$h = page($priya, '/spaces/' . $w['product'])['body'];
ok(str_contains($h, 'id="space-view-leave-btn"') && !str_contains($h, 'id="space-view-join-btn"') && !str_contains($h, 'id="space-view-request-btn"') && !str_contains($h, 'id="space-view-edit-btn"'), 'Priya (a member) sees Leave, no Join, no Request, no Edit');
$h = page($marco, '/spaces/' . $w['product'])['body'];
ok(str_contains($h, 'id="space-view-edit-btn"') && str_contains($h, 'id="space-view-members-btn"') && str_contains($h, 'id="space-view-sections-btn"') && str_contains($h, 'id="space-view-requests-btn"') && str_contains($h, 'id="space-view-archive-btn"') && !str_contains($h, 'id="space-view-delete-btn"'), 'Marco (owner) sees Edit, Members, Sections, Requests, Archive — not Delete');
ok(str_contains($h, 'id="space-home-channel-' . (int) one('SELECT default_channel_id FROM spaces WHERE id = :s', ['s' => $w['product']]) . '"'), 'the home lists #general');
finish();
