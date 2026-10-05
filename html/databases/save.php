<?php
declare(strict_types=1);
/**
 * Actions `database_create` (no `database`; log `database.create`: title, space_id, parent_page_id, inline, property_count, template) / `database_update` (with; log `database.update`: the changed
 * fields of title, description, inline). Create: in a space's root (member with databases.write) or under a page (edit on it); the schema as JSON keyed by name (a Name title by default), or a
 * database template copied (schema, views and rows). Update: edit; a field left out stays.
 */
require_once dirname(__DIR__, 2) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$dbId = (string) (req_val('database') ?? '');
if ($dbId !== '') {
    $d = database_from_request($pdo);
    require_page_level($d['database_id'], 'edit', 'Database');
    $cur = database_state($pdo, $d['database_id']);
    $f = [];
    if (req_has('title')) {
        $t = trim((string) req_val('title'));
        if ($t === '' || mb_strlen($t) > 300) { $errors['title'] = 'Give the database a title of up to 300 characters.'; }
        $f['title'] = $t;
    }
    if (req_has('description')) {
        $t = (string) req_val('description');
        if (mb_strlen($t) > 5000) { $errors['description'] = 'A description is at most 5,000 characters.'; }
        $f['description'] = $t;
    }
    if (req_has('inline')) { $f['inline'] = sp_yes('inline'); }
    if (req_has('icon')) { $f['icon'] = (string) req_val('icon') === '' ? null : mb_substr((string) req_val('icon'), 0, 16); }
    if ($errors !== []) { sp_refuse_fields($errors); }
    sp_guard($pdo, static function () use ($pdo, $me, $d, $cur, $f): void {
        $pdo->beginTransaction();
        update_database($pdo, $d['database_id'], $f, $me);
        if (array_key_exists('icon', $f)) {
            $pdo->prepare('UPDATE pages SET icon = :i WHERE id = CAST(:id AS uuid)')->execute(['i' => $f['icon'], 'id' => $d['database_id']]);
        }
        $d2 = sp_diff(database_loggable($cur), database_loggable(database_state($pdo, $d['database_id'])));
        if ($d2['after'] !== []) {
            database_log($pdo, 'database.update', 'database', $d['database_id'], $d['space_id'], ['before' => $d2['before'], 'after' => $d2['after']]);
        }
        $pdo->commit();
    });
    sp_done('Saved ' . ($f['title'] ?? $cur['plain_title'] ?: 'the database'), $d['database_id'], sp_land(return_path('/databases/' . $d['database_id']), 'saved'), 'databaseChanged', ['database_id' => $d['database_id']]);
}
// create
$title = trim((string) (req_val('title') ?? ''));
if ($title === '' || mb_strlen($title) > 300) { $errors['title'] = 'Give the database a title of up to 300 characters.'; }
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$inline = sp_yes('inline');
$template = req_has('template') && (string) req_val('template') !== '' ? (string) req_val('template') : null;
$props = null;
if (req_has('properties') || isset($_POST['properties'])) {
    $raw = $_POST['properties'] ?? null;
    $props = is_array($raw) ? $raw : (trim((string) $raw) === '' ? null : json_decode((string) $raw, true));
    if ($props !== null && (!is_array($props) || array_is_list($props) && $props !== [])) { $errors['properties'] = 'The schema is a JSON object keyed by property name.'; $props = null; }
}
if ($parent !== null && !is_uuid($parent)) { $errors['parent'] = 'The parent is named by its id.'; }
if ($template !== null && (!is_uuid($template) || !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:t AS uuid) AND is_template AND kind = 'database')", ['t' => $template]))) { $errors['template'] = 'That template is not here.'; }
if ($parent === null && $space === null && $errors === []) { $errors['space'] = 'Say where: a space or a page.'; }
if ($inline && $parent === null && $errors === []) { $errors['inline'] = 'An inline database lives inside a page: name the page.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
if ($parent !== null) {
    $pp = find_page($pdo, $parent) ?? refuse(404, 'Parent page not found.');
    require_page_level($parent, 'edit', 'Parent page');
    if ($pp['archived_at'] !== null) { refuse(422, 'The parent page is in the trash.'); }
    $space = $pp['space_id'];
} else {
    $s = one_value($pdo, 'SELECT name FROM mcp_spaces WHERE space_id = :s AND archived_at IS NULL', ['s' => $space]) ?? refuse(404, 'Space not found.');
    require_right('databases.write');
    if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $space])) { refuse(403, 'You may not create databases in ' . $s . '.'); }
}
$newId = sp_guard($pdo, static function () use ($pdo, $me, $space, $parent, $title, $props, $inline, $template): string {
    $pdo->beginTransaction();
    $id = create_database($pdo, $space, $parent, $title, $props, $inline, $template, $me);
    $n = count(database_state($pdo, $id)['properties']);
    database_log($pdo, 'database.create', 'database', $id, $space, ['after' => ['title' => mb_substr($title, 0, 120), 'space_id' => $space, 'parent_page_id' => $parent, 'inline' => $inline, 'property_count' => $n, 'template' => $template]]);
    $pdo->commit();
    return $id;
});
sp_done('Made ' . $title, $newId, sp_land(return_path('/databases/' . $newId), 'created'), 'databaseChanged', ['database_id' => $newId]);
