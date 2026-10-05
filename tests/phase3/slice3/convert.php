<?php
/** Proof — the converter round trip (spec "Proof", 1): Markdown in → blocks → sp_page_markdown out, equal; mentions, embeds, tables. */
require __DIR__ . '/lib.php';
require dirname(__DIR__, 3) . '/app/richtext/markdown.php';
$w = editor_world();
$rb = $w['runbook'];

echo "1. The every-type page\n";
$roots = root_blocks($rb);
$types = array_column($roots, 'type');
ok(count($roots) >= 18, 'the Runbook holds ' . count($roots) . ' root blocks');
foreach (['heading_1', 'heading_2', 'heading_3', 'paragraph', 'bulleted_list_item', 'numbered_list_item', 'to_do', 'quote', 'callout', 'code', 'divider', 'equation', 'toggle', 'embed', 'bookmark', 'table'] as $t) {
    ok(in_array($t, $types, true), "a $t block");
}
$para = array_values(array_filter($roots, fn ($r) => $r['type'] === 'paragraph'))[0];
$runs = json_decode(block_row($para['id'])['content'], true)['rich_text'];
$kinds = array_map(fn ($r) => $r['type'] . ':' . ($r['type'] === 'mention' ? $r['mention']['type'] : implode(',', array_keys(array_filter($r['annotations'] ?? [], fn ($v) => $v === true)))), $runs);
ok(in_array('text:bold', $kinds, true) && in_array('text:italic', $kinds, true) && in_array('text:code', $kinds, true) && in_array('text:strikethrough', $kinds, true), 'bold, italic, code and strikethrough runs');
ok(in_array('mention:page', $kinds, true) && in_array('mention:member', $kinds, true), '[[SMOKE Product handbook]] a page mention, @SMOKE Priya a member mention');
$pm = array_values(array_filter($runs, fn ($r) => $r['type'] === 'mention' && $r['mention']['type'] === 'page'))[0];
ok($pm['mention']['id'] === $w['handbook'], 'the page mention points at the handbook');
$link = array_values(array_filter($runs, fn ($r) => !empty($r['text']['link']['url'])))[0] ?? null;
ok($link !== null && $link['text']['link']['url'] === 'https://example.com/x', 'the link kept its URL');
$bul = array_values(array_filter($roots, fn ($r) => $r['type'] === 'bulleted_list_item' && $r['plain_text'] === 'two'))[0];
ok(count(children_of($bul['id'])) === 1 && children_of($bul['id'])[0]['plain_text'] === 'nested', 'the nested bullet is a child of "two"');
$todo = array_values(array_filter($roots, fn ($r) => $r['type'] === 'to_do'));
ok(count($todo) === 2 && json_decode(block_row($todo[1]['id'])['content'], true)['checked'] === true, 'two to-dos, the second checked');
$code = array_values(array_filter($roots, fn ($r) => $r['type'] === 'code'))[0];
ok(json_decode(block_row($code['id'])['content'], true)['language'] === 'php' && $code['plain_text'] === 'echo 1;', 'the code block keeps its language and text');
$tog = array_values(array_filter($roots, fn ($r) => $r['type'] === 'toggle'))[0];
ok($tog['plain_text'] === 'a toggle' && children_of($tog['id'])[0]['plain_text'] === 'inside the toggle', 'the toggle and what is inside it');
$emb = array_values(array_filter($roots, fn ($r) => $r['type'] === 'embed'))[0];
ok(str_contains(json_decode(block_row($emb['id'])['content'], true)['url'], 'youtube.com'), 'the YouTube URL became an embed');
$bm = array_values(array_filter($roots, fn ($r) => $r['type'] === 'bookmark'))[0];
ok(json_decode(block_row($bm['id'])['content'], true)['url'] === 'https://example.org/page', 'the other host became a bookmark');
$tbl = array_values(array_filter($roots, fn ($r) => $r['type'] === 'table'))[0];
$rows = children_of($tbl['id']);
ok(count($rows) === 2 && $rows[0]['type'] === 'table_row' && json_decode(block_row($tbl['id'])['content'], true)['table_width'] === 2 && json_decode(block_row($tbl['id'])['content'], true)['has_column_header'] === true, 'the table: two rows, width 2, a header row');
$callout = array_values(array_filter($roots, fn ($r) => $r['type'] === 'callout'))[0];
ok(json_decode(block_row($callout['id'])['content'], true)['icon']['emoji'] === '💡' && $callout['plain_text'] === 'a callout', 'the callout with its icon');

echo "2. Round trip: Markdown out → blocks → Markdown out again\n";
$md1 = page_md($rb);
$tree = markdown_to_blocks($md1, ['page_by_title' => fn (string $t) => page_by_title($t), 'member_by_name' => fn (string $n) => (($id = one('SELECT id FROM members WHERE display_name = :n', ['n' => $n])) ? ['id' => (int) $id, 'name' => $n, 'kind' => 'member'] : null), 'embed_hosts' => ['www.youtube.com', 'youtube.com']]);
$marco = as_member(27);
[, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Round trip', 'space' => $w['product']]);
$rt2 = (string) $b['record_id'];
[$c, $b] = act($marco, '/blocks/append.php', ['page' => $rt2, 'markdown' => $md1]);
ok($c === 200 && ($b['count'] ?? 0) === count($roots), 'block_append of the page\'s own Markdown makes the same number of root blocks (' . ($b['count'] ?? 0) . ')');
$md2 = page_md($rt2);
$norm = fn (string $s) => preg_replace('/\n{2,}/', "\n", trim($s));
ok($norm($md1) === $norm($md2), 'the Markdown round-trips byte for byte' . ($norm($md1) === $norm($md2) ? '' : "\n--- first:\n" . $md1 . "\n--- second:\n" . $md2));
ok(array_column(root_blocks($rt2), 'type') === $types, 'the types in the same order');

echo "3. Odd Markdown\n";
[, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Odd', 'space' => $w['product']]);
$odd = (string) $b['record_id'];
[$c, $b] = act($marco, '/blocks/append.php', ['page' => $odd, 'markdown' => "[[No such page]] stays text\n\n<!-- unsupported block callout_x -->\n\n- [[SMOKE Runbook]]\n\n[a file](/files/999)\n"]);
$r = root_blocks($odd);
$first = json_decode(block_row($r[0]['id'])['content'], true)['rich_text'];
ok($first[0]['type'] === 'text' && str_starts_with($first[0]['plain_text'], '[[No such page]]') && $r[0]['plain_text'] === '[[No such page]] stays text', 'an unknown [[X]] stays text');
ok($r[1]['type'] === 'unsupported', 'the unsupported marker becomes an unsupported block');
ok($r[2]['type'] === 'link_to_page' && json_decode(block_row($r[2]['id'])['content'], true)['page_id'] === $rb, '- [[SMOKE Runbook]] a link_to_page');
ok($r[3]['type'] === 'file', 'a /files link line a file block');
[$c, $b] = act($marco, '/blocks/append.php', ['page' => $odd, 'markdown' => '   ']);
ok($c === 422 && isset(fields($b)['markdown']), 'empty Markdown: 422 (field markdown)');
finish();
