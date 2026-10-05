<?php
/** Proof — relations and rollups (spec "Proof", 3): a relation set through the picker, both sides, the rollups computed, a target removed from both, the refusals in the database's words. */
require __DIR__ . '/lib.php';
$w = databases_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $ann = as_member(29);
[$tasks, $epics, $launch, $fix, $build, $ship] = [$w['tasks'], $w['epics'], $w['launch'], $w['fix'], $w['build'], $w['ship']];
function htmx_get(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => ['HX-Request: true', 'Accept: text/html']]); return [$r['code'], $r['body']]; }
$epicRow = fn () => rows_as(27, $epics, null, ['property' => 'Epic', 'title' => ['equals' => 'SMOKE Launch']])[0] ?? [];

act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => []]);      // a rerun starts clean
echo "1. The picker\n";
ok(relations_of($launch, 'tasks') === [] && $epicRow()['properties']['points']['display'] === '', 'before: Launch is related to nothing; its Points rollup is empty');
[$c, $h] = htmx_get($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks');
ok($c === 200 && str_contains($h, 'id="relation-picker"') && !str_contains($h, '<html') && str_contains($h, 'SMOKE Fix the login') && str_contains($h, 'SMOKE Build the board') && str_contains(htmx_get($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks&q=ship')[1], 'SMOKE Ship it'), 'the picker (an HTMX partial) lists the rows of the target database, Tasks');
[$c, $h] = htmx_get($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks&q=fix');
$form = substr($h, (int) strpos($h, 'id="relation-form"'));
ok(str_contains($form, 'SMOKE Fix the login') && !str_contains($form, 'SMOKE Build the board') && !str_contains($form, 'SMOKE Launch'), 'a search narrows it (find_pages within Tasks: only Tasks\' rows, never an Epic)');
$r = page($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks');
ok($r['code'] === 200 && str_contains($r['body'], 'id="relation-form"') && str_contains($r['body'], 'action="/databases/rows/relation.php"') && str_contains($r['body'], '<html'), 'the picker is also a plain page (JavaScript off): a form that posts the list');
[$c, $d] = screen($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks');
ok($c === 200 && count($d['candidates']) >= 3 && $d['target_database']['database_id'] === $tasks && $d['linked'] === [], 'and answers JSON: the candidates, the target, what is linked');
$r = page($marco, '/databases/rows/relation.php?row=' . $launch . '&property=Points');
ok($r['code'] === 422, 'a property that is not a relation: refused in words');
$r = page($ann, '/databases/rows/relation.php?row=' . $launch . '&property=Tasks');
ok($r['code'] === 404, 'Ann: 404');

echo "2. Link two tasks; both sides show it\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => ['', $fix, $build]]);
ok($c === 200 && relations_of($launch, 'tasks') === [$fix, $build], 'row_relation_set: the whole list [Fix, Build]');
ok((int) one('SELECT count(*) FROM row_relations WHERE from_row_id IN (CAST(:a AS uuid), CAST(:b AS uuid), CAST(:c AS uuid))', ['a' => $launch, 'b' => $fix, 'c' => $build]) === 4, 'a dual relation is one link read from both sides: two links, four rows');
ok(relations_of($fix, 'Related to tasks') === [$launch] && relations_of($build, 'Related to tasks') === [$launch], 'the Tasks side shows the epic');
$e = $epicRow()['properties'];
ok($e['points']['display'] === '11' && $e['done']['display'] === '50.0%' && $e['count']['display'] === '2' && $e['latest']['display'] === '2026-10-20', 'rollups: Points sums 11, Done 50.0%, Count 2, Latest 2026-10-20');
$t = rows_as(27, $tasks, null, ['property' => 'Task', 'title' => ['equals' => 'SMOKE Fix the login']])[0]['properties'];
ok(count($t['Related to tasks']) === 1 && $t['Related to tasks'][0]['title'] === 'SMOKE Launch', 'the dual side resolves with the epic\'s title');
$l = activity('row.relation_set', $since);
ok(count($l) === 1 && $l[0]['entity_uuid'] === $launch && json_decode($l[0]['after'], true)['key'] === 'tasks' && json_decode($l[0]['after'], true)['target_count'] === 2 && !str_contains($l[0]['after'], 'SMOKE Fix'), 'row.relation_set logs the key and the target count — no titles');
$r = page($marco, '/databases/' . $epics . '/rows/' . $launch);
ok(str_contains($r['body'], 'SMOKE Fix the login') && str_contains($r['body'], 'SMOKE Build the board'), 'the epic\'s page shows the linked rows');
$r = page($marco, '/databases/' . $epics);
ok(str_contains($r['body'], '>11<') && str_contains($r['body'], '50%') || str_contains($r['body'], '50.0%'), 'the epics table shows the rollups');
$n = (int) one("SELECT count(*) FROM page_links WHERE kind = 'relation'");
ok($n >= 4, 'the links are backlinks too (page_links)');

echo "3. Take a link away\n";
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => [$fix]]);
ok($c === 200 && relations_of($launch, 'tasks') === [$fix] && relations_of($build, 'Related to tasks') === [], 'dropping Build removes both sides');
$e = $epicRow()['properties'];
ok($e['points']['display'] === '3' && $e['count']['display'] === '1' && $e['done']['display'] === '100.0%', 'and the rollups follow (3, 1, 100.0%)');
act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => [$fix, $build]]);
ok(relations_of($launch, 'tasks') === [$fix, $build], 'put back for the next proofs');
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $build, 'property' => 'Related to tasks', 'targets' => []]);
ok($c === 200 && relations_of($launch, 'tasks') === [$fix], 'cleared from the other side too: the epic loses Build');
act($marco, '/databases/rows/relation.php', ['row' => $build, 'property' => 'Related to tasks', 'targets' => [$launch]]);
ok(relations_of($launch, 'tasks') === [$fix, $build] || relations_of($launch, 'tasks') === [$build, $fix], 'and added from it');
act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => [$fix, $build]]);

echo "4. What is refused\n";
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => [$fix, $launch]]);
ok($c === 422 && str_contains(msg($b), 'links to another database'), 'a row of another database: the guard\'s words — ' . msg($b));
ok(relations_of($launch, 'tasks') === [$fix, $build], 'and nothing changed');
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Tasks', 'targets' => ['00000000-0000-0000-0000-0000000000aa']]);
ok($c === 422 && str_contains(msg($b), 'not here'), 'a row that is not there: ' . msg($b));
[$c, $b] = act($marco, '/databases/rows/relation.php', ['row' => $launch, 'property' => 'Points', 'targets' => [$fix]]);
ok($c === 422 && isset(fields($b)['property']), 'a property that is not a relation: refused');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Bad', 'type' => 'rollup', 'rollup_relation' => 'Points', 'rollup_property' => 'Task', 'rollup_function' => 'count']);
ok($c === 422 && str_contains(msg($b), 'through a relation'), 'a rollup through a non-relation: "' . msg($b) . '"');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Bad', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Task', 'rollup_function' => 'median']);
ok($c === 422 && str_contains(msg($b), 'function'), 'an unknown rollup function: "' . msg($b) . '"');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Bad', 'type' => 'rollup', 'rollup_relation' => 'Tasks', 'rollup_property' => 'Nonesuch', 'rollup_function' => 'count']);
ok($c === 422 && str_contains(msg($b), 'does not have'), 'a rollup of a property the related database lacks: ' . msg($b));
[$c, $b] = act($marco, '/databases/properties/remove.php', ['database' => $epics, 'key' => 'Tasks']);
ok($c === 422 && str_contains(msg($b), 'rollup goes through'), 'the relation a rollup goes through cannot be removed first: ' . msg($b));
ok(isset(db_schema($epics)['bad']) === false && isset(db_schema($epics)['tasks']), 'the schema is as it was');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $epics, 'key' => 'Self', 'type' => 'relation', 'relation_database' => '00000000-0000-0000-0000-0000000000bb']);
ok($c === 422 && str_contains(msg($b), 'Choose the database'), 'a relation to a database that is not there: ' . msg($b));
$lone = (string) (act($marco, '/databases/save.php', ['title' => 'SMOKE Lone', 'space' => $w['product']])[1]['record_id'] ?? '');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $lone, 'key' => 'Epic link', 'type' => 'relation', 'relation_database' => $epics]);
ok($c === 200 && db_schema($lone)['epic_link']['relation']['two_way'] === false && !isset(db_schema($epics)['Related to epic link']), 'a one-way relation writes no mirror');
[$c, $b] = act($marco, '/databases/properties/remove.php', ['database' => $lone, 'key' => 'Epic link']);
ok($c === 200, 'and goes without trouble');
finish();
