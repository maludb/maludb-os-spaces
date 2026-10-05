<?php
/** Proof — make (spec "Proof", 1): a root page, a subpage, a private page; the refusals in words; a page from a template; the logs; the JSON contract. */
require __DIR__ . '/lib.php';
$w = pages_world();
$marco = as_member(27); $priya = as_member(26); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30); $owner = as_member(1);

echo "1. The world\n";
$hb = page_row($w['handbook']); $pr = page_row($w['pricing']); $p27 = page_row($w['pricing2027']); $notes = page_row($w['notes']);
ok((int) $hb['space_id'] === $w['product'] && $hb['parent_page_id'] === null && $pr['parent_page_id'] === $w['handbook'] && $p27['parent_page_id'] === $w['pricing'] && (int) $p27['space_id'] === $w['product'], 'the handbook at Product\'s root, Pricing under it, Pricing 2027 under Pricing (in the parent\'s space)');
ok($notes['space_id'] === null && (int) $notes['owner_member_id'] === 28, "Bea's notes: private, hers");
ok((int) one("SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid) AND type = 'child_page'", ['p' => $w['handbook']]) === 1 && (int) one("SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid) AND type = 'paragraph'", ['p' => $w['handbook']]) === 1, 'the handbook holds the edge block to Pricing and the paragraph from its markdown');
ok((bool) one('SELECT is_wiki FROM spaces WHERE id = :g', ['g' => $w['general']]), 'General is a wiki');

echo "2. Make\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Roadmap', 'space' => $w['product']]);
$rm = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($rm) && ($b['location'] ?? '') === '/pages/' . $rm . '?notice=created' && ($b['did'] ?? '') === 'Made SMOKE Roadmap', 'page_create at Product\'s root: 200, record_id a UUID, location ends in it');
$log = activity('page.create', $since);
$after = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && $log[0]['entity_uuid'] === $rm && (int) $log[0]['space_id'] === $w['product'] && $after['title'] === 'SMOKE Roadmap' && $after['private'] === false, 'page.create logged with entity_uuid and space_id');
[$c, $b] = act($priya, '/pages/save.php', ['title' => 'SMOKE Under pricing', 'parent' => $w['pricing']]);
ok($c === 200 && page_row((string) $b['record_id'])['parent_page_id'] === $w['pricing'], 'Priya (a member at edit) makes a subpage under Pricing');
[$c, $b] = act($dana, '/pages/save.php', ['title' => 'SMOKE Intruder', 'space' => $w['product']]);
ok($c === 403 && msg($b) === 'You may not create pages in SMOKE Product.', 'Dana (not in Product): 403 in words');
[$c, $b] = act($dana, '/pages/save.php', ['title' => 'SMOKE Intruder', 'parent' => $w['pricing']]);
ok($c === 404, 'nor under a page she cannot see: 404');
[$c, $b] = act($ann, '/pages/save.php', ['title' => 'SMOKE Guest page', 'private' => 'yes']);
ok($c === 403 && msg($b) === 'You may not keep private pages.', 'Ann (guest): 403 in words');
[$c, $b] = act($ann, '/pages/save.php', ['title' => 'SMOKE Guest page', 'space' => $w['general']]);
ok($c === 404, 'nor in a space: 404 (no space exists to her)');
[$c, $b] = act($bea, '/pages/save.php', ['title' => '', 'space' => $w['general']]);
ok($c === 422 && isset(fields($b)['title']), 'no title: 422 (field title)');
$tpl = (string) one("SELECT id::text FROM pages WHERE is_template AND plain_title = 'Meeting notes'");
[$c, $b] = act($bea, '/pages/save.php', ['title' => 'SMOKE Monday meeting', 'space' => $w['general'], 'template' => $tpl]);
$mm = (string) $b['record_id'];
ok($c === 200 && array_column(q("SELECT plain_text FROM blocks WHERE page_id = CAST(:p AS uuid) AND type = 'heading_2' ORDER BY position", ['p' => $mm]), 'plain_text') === ['Attendees', 'Agenda', 'Notes', 'Action items'], 'a page from the Meeting notes template has its four headings');
ok(page_row($mm)['is_template'] === false && (int) page_row($mm)['wiki_owner_member_id'] === 28, 'it is a page, not a template; in the wiki, Bea owns it');
[$c, $b] = act($bea, '/pages/save.php', ['title' => 'SMOKE Private two', 'private' => 'yes', 'markdown' => "Line one\nLine two"]);
$p2 = (string) $b['record_id'];
ok($c === 200 && page_row($p2)['space_id'] === null && (int) one("SELECT count(*) FROM blocks WHERE page_id = CAST(:p AS uuid)", ['p' => $p2]) === 1, 'a private page with markdown: one paragraph block (the converter is slice 3)');

echo "3. Update keeps what it is not sent; a locked page refuses\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/pages/save.php', ['page' => $rm, 'icon' => '🗺️']);
ok($c === 200 && page_row($rm)['plain_title'] === 'SMOKE Roadmap' && one('SELECT icon FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $rm]) === '🗺️', 'the icon changed; the title kept');
$log = activity('page.update', $since);
ok(count($log) === 1 && json_decode((string) $log[0]['after'], true) == ['icon' => '🗺️'] && json_decode((string) $log[0]['before'], true) == ['icon' => null], 'page.update logs the changed field only');
[$c, $b] = act($priya, '/pages/save.php', ['page' => $rm, 'title' => 'x']);
ok($c === 200, 'Priya (edit through the space) may retitle');
act($priya, '/pages/save.php', ['page' => $rm, 'title' => 'SMOKE Roadmap']);
[$c, $b] = act($dana, '/pages/save.php', ['page' => $rm, 'title' => 'x']);
ok($c === 404, 'Dana: 404 (the page does not exist to her)');
[$c, $b] = act($ann, '/pages/save.php', ['page' => $w['general'] === null ? $rm : $mm, 'title' => 'x']);
ok($c === 404, 'Ann: 404');
finish();
