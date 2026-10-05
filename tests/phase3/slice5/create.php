<?php
/** Proof — create and schema (spec "Proof", 1): a database with every property type, the refusals in words, a rename, lossless retypes, a removal that keeps and one that purges, the gate, the log. */
require __DIR__ . '/lib.php';
$w = databases_world();
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $ann = as_member(29); $owner = as_member(1);
$product = $w['product'];
$ALL = ['Task' => 'title', 'Notes' => 'rich_text', 'Points' => 'number', 'Kind' => ['type' => 'select', 'options' => ['Bug', 'Feature']], 'Tags' => ['type' => 'multi_select', 'options' => ['ui', 'db']],
    'Status' => ['type' => 'status', 'options' => ['Todo', 'Done']], 'Due' => 'date', 'Owner' => 'people', 'Files' => 'files', 'Urgent' => 'checkbox', 'Link' => 'url', 'Mail' => 'email', 'Phone' => 'phone_number',
    'Created' => 'created_time', 'Creator' => 'created_by', 'Edited' => 'last_edited_time', 'Editor' => 'last_edited_by', 'Ref' => ['type' => 'unique_id', 'prefix' => 'TSK']];

echo "1. Make a database with every property type\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Schema lab', 'space' => $product, 'properties' => json_encode($ALL)]);
$lab = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($lab) && str_starts_with((string) $b['location'], '/databases/' . $lab), 'database_create: 200, a UUID record_id, the location');
$schema = db_schema($lab);
ok(count($schema) === 18, 'the schema holds eighteen properties (' . count($schema) . ')');
$types = array_map(fn ($d) => $d['type'], $schema);
ok(array_unique(array_values($types)) !== [] && count(array_filter($types, fn ($t) => $t === 'title')) === 1 && $schema['title']['name'] === 'Task' && $schema['status']['type'] === 'status' && $schema['ref']['unique_id']['prefix'] === 'TSK'
    && array_column($schema['kind']['select']['options'], 'name') === ['Bug', 'Feature'], 'one title (named Task), the status, the select\'s options and the unique id\'s prefix are as given');
ok(isset($schema['due'], $schema['owner'], $schema['files'], $schema['urgent'], $schema['link'], $schema['mail'], $schema['phone'], $schema['created'], $schema['creator'], $schema['edited'], $schema['editor']), 'every other type is there under its slug key');
$l = activity('database.create', $since);
ok(count($l) === 1 && $l[0]['entity_uuid'] === $lab && (int) $l[0]['space_id'] === $product && json_decode($l[0]['after'], true)['property_count'] === 18, 'database.create: entity_uuid, space_id and the property count');
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Plain db', 'space' => $product]);
$plain = (string) ($b['record_id'] ?? '');
ok($c === 200 && array_keys(db_schema($plain)) === ['title'] && db_schema($plain)['title']['name'] === 'Name' && count(views_of($plain)) === 1 && views_of($plain)[0]['layout'] === 'table', 'with no schema: a Name title and a first Table view');
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Nowhere']);
ok($c === 422 && isset(fields($b)['space']), 'no space and no page: 422 in words (' . msg($b) . ')');
[$c, $b] = act($ann, '/databases/save.php', ['title' => 'SMOKE Ann db', 'space' => $product]);
ok(in_array($c, [403, 404], true), 'a guest cannot make one in a space they are not in (' . $c . ')');
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Inline db', 'parent' => $w['handbook'], 'inline' => 'yes']);
$inl = (string) ($b['record_id'] ?? '');
ok($c === 200 && (bool) one('SELECT is_inline FROM databases WHERE id = CAST(:d AS uuid)', ['d' => $inl]) && one('SELECT parent_page_id::text FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $inl]) === $w['handbook'], 'a database inside a page: inline, its parent the page');
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE Orphan inline', 'space' => $product, 'inline' => 'yes']);
ok($c === 422 && isset(fields($b)['inline']), 'inline without a page: refused in words');
$tpl = (string) one("SELECT id::text FROM pages WHERE is_template AND kind = 'database' AND plain_title = 'Vendor list'");
[$c, $b] = act($marco, '/databases/save.php', ['title' => 'SMOKE From template', 'space' => $product, 'template' => $tpl]);
$fromT = (string) ($b['record_id'] ?? '');
ok($c === 200 && is_uuid($fromT) && count(db_schema($fromT)) === 8 && db_schema($fromT)['title']['name'] === 'Vendor' && (int) one('SELECT count(*) FROM database_views WHERE database_id = CAST(:d AS uuid)', ['d' => $fromT]) >= 1, 'database_create from the Vendor list template: its schema and a view (db/019 — sp_page_duplicate now copies a database)');
ok(!(bool) one('SELECT is_template FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $fromT]), 'the copy is a database, not a template');

echo "2. The database's own words\n";
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Calc', 'type' => 'formula']);
ok($c === 422 && str_contains(msg($b), 'Extended'), 'a formula: "…formula, button and place are Extended" (' . msg($b) . ')');
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Second title', 'type' => 'title']);
ok($c === 422 && str_contains(msg($b), 'exactly one title'), 'a second title: ' . msg($b));
[$c, $b] = act($marco, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Pin', 'type' => 'place']);
ok($c === 422 && str_contains(msg($b), 'Extended'), 'a place: Extended too');
ok(count(db_schema($lab)) === 18, 'none of those changed the schema');

echo "3. Who may change the schema\n";
act($marco, '/pages/restrict.php', ['page' => $lab]);
act($marco, '/pages/share.php', ['page' => $lab, 'member' => 26, 'level' => 'edit']);
act($marco, '/pages/share.php', ['page' => $lab, 'member' => 30, 'level' => 'comment']);
[$c, $b] = act($dana, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Extra', 'type' => 'number']);
ok($c === 403, 'Dana (comment) may not add a property: 403 in words (' . msg($b) . ')');
[$c, $b] = act($dana, '/databases/save.php', ['database' => $lab, 'title' => 'Hijacked']);
ok($c === 403, 'nor rename the database: 403');
[$c, $b] = act($dana, '/databases/delete.php', ['database' => $lab]);
ok($c === 403, 'nor delete it: 403');
[$c, $b] = act($ann, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Extra', 'type' => 'number']);
ok($c === 404, 'Ann (nothing shared): the database does not exist for her, 404');

echo "4. Rename and retype (Priya, edit)\n";
$r1 = mkrow($marco, $lab, 'SMOKE Lab one', ['Points' => 3, 'Kind' => 'Bug', 'Tags' => ['ui', 'db'], 'Due' => ['start' => '2026-10-05'], 'Link' => 'https://example.com', 'Urgent' => true]);
$r2 = mkrow($marco, $lab, 'SMOKE Lab two', ['Points' => 8, 'Kind' => 'Feature']);
ok(row_props($r1)['points'] === 3 && row_props($r1)['kind']['name'] === 'Bug', 'two rows to carry values (a number 3; the select Bug)');
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Points', 'name' => 'Effort']);
ok($c === 200 && db_schema($lab)['points']['name'] === 'Effort' && db_schema($lab)['points']['type'] === 'number' && row_props($r1)['points'] === 3, 'Priya renames Points to Effort: the key and the values stay');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'effort', 'type' => 'rich_text']);
ok($c === 200 && db_schema($lab)['points']['type'] === 'rich_text' && display_text(row_props($r1)['points']) === '3' && display_text(row_props($r2)['points']) === '8', 'number → rich text: the values become text ("3", "8")');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Kind', 'type' => 'multi_select']);
$k1 = row_props($r1)['kind'];
ok($c === 200 && db_schema($lab)['kind']['type'] === 'multi_select' && array_column($k1, 'name') === ['Bug'] && array_column(db_schema($lab)['kind']['multi_select']['options'], 'name') === ['Bug', 'Feature'], 'select → multi-select: the one option becomes a list, the options stay');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Due', 'type' => 'rich_text']);
ok($c === 422 && str_contains(msg($b), 'make a new property instead') && db_schema($lab)['due']['type'] === 'date', 'date → text: refused in words (' . msg($b) . ')');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Notes', 'type' => 'title']);
ok($c === 422 && str_contains(msg($b), 'The title is the title'), 'rich text → title: "' . msg($b) . '"');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Task', 'type' => 'rich_text']);
ok($c === 422 && str_contains(msg($b), 'The title is the title'), 'the title → text: the same sentence');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Urgent', 'type' => 'select']);
ok($c === 200 && db_schema($lab)['urgent']['type'] === 'select' && row_props($r1)['urgent']['name'] === 'Yes', 'checkbox → select (Yes/No): true becomes Yes');
$l = activity('database.property_save', $since);
ok(count($l) >= 4 && json_decode($l[1]['after'], true)['retyped_rows'] === 2 && json_decode($l[1]['after'], true)['key'] === 'points', 'database.property_save logs the key, the type and retyped_rows (2)');

echo "5. Remove: keep the values, or purge them\n";
[$c, $b] = act($priya, '/databases/properties/remove.php', ['database' => $lab, 'key' => 'Tags']);
ok($c === 200 && !isset(db_schema($lab)['tags']) && isset(row_props($r1)['tags']) && count(row_props($r1)['tags']) === 2, 'Tags removed keeping the values: the schema has no Tags, the row still carries them');
[$c, $b] = act($priya, '/databases/properties/save.php', ['database' => $lab, 'key' => 'Tags', 'type' => 'multi_select', 'options' => 'ui, db']);
ok($c === 200 && isset(db_schema($lab)['tags']) && count(row_props($r1)['tags']) === 2, 'added again: the kept values show once more');
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/properties/remove.php', ['database' => $lab, 'key' => 'Tags', 'purge_values' => 'yes']);
ok($c === 200 && !isset(row_props($r1)['tags']), 'removed with purge: the rows lose the values too');
$l = activity('database.property_remove', $since);
ok(count($l) === 1 && json_decode($l[0]['after'], true) === ['key' => 'tags', 'purge_values' => true], 'database.property_remove logs the key and purge_values — no values');
[$c, $b] = act($priya, '/databases/properties/remove.php', ['database' => $lab, 'key' => 'Task']);
ok($c === 422 && str_contains(msg($b), 'title'), 'the title cannot be removed: ' . msg($b));
[$c, $b] = act($priya, '/databases/properties/remove.php', ['database' => $lab, 'key' => 'Nothing here']);
ok($c === 422 && str_contains(msg($b), 'No property'), 'a property nobody has: refused in words');

echo "6. The whole schema, the log, the page\n";
$sch = db_schema($lab);
$sch['extra'] = ['id' => 'extra', 'name' => 'Extra', 'type' => 'number'];
unset($sch['phone']);
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/schema.php', ['database' => $lab, 'properties' => json_encode($sch)]);
ok($c === 200 && isset(db_schema($lab)['extra']) && !isset(db_schema($lab)['phone']), 'database_schema_save: one UPDATE, validated by the guard');
$l = activity('database.schema_save', $since);
$a = json_decode($l[0]['after'] ?? '[]', true);
ok(count($l) === 1 && $a['properties_added'] === ['extra'] && $a['properties_removed'] === ['phone'] && !str_contains($l[0]['after'], 'SMOKE Lab'), 'database.schema_save logs the keys added and removed — no row values');
$noTitle = $sch; unset($noTitle['title']);
[$c, $b] = act($priya, '/databases/schema.php', ['database' => $lab, 'properties' => json_encode($noTitle)]);
ok($c === 422 && str_contains(msg($b), 'exactly one title'), 'a schema without its title: the guard\'s words');
[$c, $b] = act($priya, '/databases/schema.php', ['database' => $lab, 'properties' => '[1,2]']);
ok($c === 422, 'a list instead of an object: 422');
$r = page($priya, '/databases/' . $lab . '/schema');
$h = $r['body'];
$ok = $r['code'] === 200;
foreach (array_keys(db_schema($lab)) as $k) { $ok = $ok && str_contains($h, 'id="property-row-' . $k . '"'); }
ok($ok && str_contains($h, 'id="schema-table"') && str_contains($h, '>Bug<') && str_contains($h, 'prefix') && str_contains($h, 'id="property-add-form"'), 'the schema page lists every property with its chips, and the add form');
ok(!str_contains($h, 'Warning:') && !str_contains($h, 'Notice:') && !str_contains($h, 'Fatal error'), 'and says no PHP warning');
[$c, $d] = screen($priya, '/databases/' . $lab . '/schema');
ok($c === 200 && count($d['properties']) === count(db_schema($lab)) && $d['may']['schema'] === true, 'database-schema answers JSON: the properties and what I may do');
$r = page($dana, '/databases/' . $lab . '/schema');
ok($r['code'] === 200 && !str_contains($r['body'], 'id="property-add-form"') && !str_contains($r['body'], 'id="database-details"'), 'Dana (comment) sees the schema without the forms');

echo "7. Update and delete\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/databases/save.php', ['database' => $lab, 'title' => 'SMOKE Schema lab 2', 'description' => 'Where schemas are tried']);
ok($c === 200 && one('SELECT plain_title FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $lab]) === 'SMOKE Schema lab 2' && one('SELECT sp_rich_text_plain(description) FROM databases WHERE id = CAST(:d AS uuid)', ['d' => $lab]) === 'Where schemas are tried', 'database_update: the title (the page\'s) and the description');
$l = activity('database.update', $since);
ok(count($l) === 1 && !str_contains($l[0]['after'], 'Where schemas'), 'database.update logs the change without the description\'s words');
[$c, $b] = act($priya, '/databases/delete.php', ['database' => $lab]);
ok($c === 403, 'Priya (edit) cannot delete: 403');
$since = last_activity_id();
[$c, $b] = act($marco, '/databases/delete.php', ['database' => $lab]);
ok($c === 200 && one('SELECT archived_at IS NOT NULL FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $lab]) && one('SELECT archived_at IS NOT NULL FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $r1]), 'database_delete (full): the database and its rows go to the trash');
$l = activity('database.delete', $since);
ok(count($l) === 1 && json_decode($l[0]['after'], true)['row_count'] === 2, 'database.delete logs the row count');
[$c, $b] = act($marco, '/pages/restore.php', ['page' => $lab]);
ok($c === 200 && one('SELECT archived_at IS NULL FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $lab]) && one('SELECT archived_at IS NULL FROM pages WHERE id = CAST(:d AS uuid)', ['d' => $r1]), 'page_restore brings the database and its rows back');
[$c, $b] = act($marco, '/databases/save.php', ['database' => $lab, 'title' => 'SMOKE Schema lab']);
finish();

function display_text(mixed $rt): string { return is_array($rt) ? trim(implode('', array_map(fn ($r) => $r['plain_text'] ?? '', $rt))) : (string) $rt; }
