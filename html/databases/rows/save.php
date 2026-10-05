<?php
declare(strict_types=1);
/**
 * Actions `row_create` (no `row`; log `row.create`: database_id, title, property keys) / `row_update` (with; log `row.update`: the keys changed — never the values). edit_content. Values arrive as
 * `p[key]` fields or `properties` (JSON keyed by display name or key); a select by option name, people by id or name, a date {start, end}; a computed property is ignored. `title` is the title
 * property. `markdown` becomes the row's body. A people property that newly names a member tells them (a bell row, kind mention). An HTMX cell edit (`render=row`) answers the row's partial.
 */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
[$raw, $errors] = row_values_from_request();
$title = req_has('title') ? trim((string) req_val('title')) : null;
$rowId = (string) (req_val('row') ?? '');
if ($rowId !== '') {
    $r = row_from_request($pdo);
    require_page_level($r['page_id'], 'edit_content', 'Database');
    $d = $r['database'] ?? refuse(404, 'Database not found.');
    $schema = $d['properties'];
    if ($title !== null && ($title === '' || mb_strlen($title) > 300)) { $errors['title'] = 'Give the row a title of up to 300 characters.'; }
    [$values, $errs] = coerce_row_values($pdo, $schema, $d['title_property_key'], $raw, $title === null || isset($errors['title']) ? null : $title);
    $errors += $errs;
    if ($errors !== []) { sp_refuse_fields($errors); }
    $res = sp_guard($pdo, static function () use ($pdo, $me, $r, $d, $schema, $values): array {
        $pdo->beginTransaction();
        $res = update_row($pdo, $r['page_id'], $values, $me);
        $actor = (string) (current_member()['display_name'] ?? 'Someone');
        $after = find_page($pdo, $r['page_id']);
        notify_assigned($pdo, $r['page_id'], (string) ($after['plain_title'] ?? ''), $d['plain_title'], $actor, $schema, $res['added_people']);
        if ($res['changed'] !== []) {
            database_log($pdo, 'row.update', 'row', $r['page_id'], $r['space_id'], ['after' => ['database_id' => $d['database_id'], 'keys' => $res['changed']]]);
        }
        $pdo->commit();
        return $res;
    });
    $land = sp_land(return_path('/databases/' . $d['database_id'] . '/rows/' . $r['page_id']), 'rowsaved');
    if (is_htmx_request() && !wants_json() && (string) (req_val('render') ?? '') === 'row') {
        $row = find_row($pdo, $r['page_id']);
        $view = is_uuid((string) req_val('view')) ? find_view($pdo, (string) req_val('view')) : null;
        $d = find_database($pdo, $d['database_id']);
        if ($view !== null && $view['group_by'] !== null && in_array($view['group_by'], $res['changed'], true)) { header('HX-Refresh: true'); exit; }   // the row changed groups: the page redraws
        hx_trigger('rowChanged');
        echo view('databases/partials/row-row.php', ['row' => ['row_id' => $row['page_id'], 'title' => $row['plain_title'], 'icon' => $row['icon'], 'properties' => $row['resolved']], 'schema' => $d['properties'],
            'cols' => visible_schema($d['properties'], $view['visible_properties'] ?? null, $d['title_property_key']), 'd' => $d, 'may' => ['edit' => PAGE_LEVELS[$d['my_level']] >= 3], 'here' => (string) (req_val('here') ?? '/databases/' . $d['database_id']), 'view' => $view, 'members' => member_choices($pdo)]);
        exit;
    }
    if (is_htmx_request() && !wants_json() && (string) (req_val('render') ?? '') === 'panel') {
        hx_trigger('rowChanged');
        echo render_row_panel($pdo, find_row($pdo, $r['page_id']), '/databases/' . $d['database_id'] . '/rows/' . $r['page_id']);
        exit;
    }
    sp_done('Saved ' . ($r['plain_title'] ?: 'the row'), $r['page_id'], $land, 'rowChanged', ['row_id' => $r['page_id'], 'database_id' => $d['database_id'], 'changed' => $res['changed']]);
}
// create
$d = database_from_request($pdo);
require_page_level($d['database_id'], 'edit_content', 'Database');
$schema = $d['properties'];
if ($title !== null && ($title === '' || mb_strlen($title) > 300)) { $errors['title'] = 'Give the row a title of up to 300 characters.'; }
[$values, $errs] = coerce_row_values($pdo, $schema, $d['title_property_key'], $raw, $title === null || isset($errors['title']) ? null : $title);
$errors += $errs;
$template = row_template_from_request($pdo, $d['database_id']);
$markdown = req_val('markdown');
if ($errors !== []) { sp_refuse_fields($errors); }
$res = sp_guard($pdo, static function () use ($pdo, $me, $d, $schema, $values, $template, $markdown): array {
    $pdo->beginTransaction();
    $id = create_row($pdo, $d['database_id'], $values, $template, $me);
    if ($markdown !== null && trim($markdown) !== '') {
        require_once dirname(__DIR__, 3) . '/app/features/blocks/handler.php';          // the converter, the block writers
        append_markdown($pdo, $id, trim($markdown), null, null, markdown_context($pdo));
    }
    $actor = (string) (current_member()['display_name'] ?? 'Someone');
    $added = [];
    foreach ($values as $k => $v) {
        if (($schema[$k]['type'] ?? '') === 'people' && is_array($v) && $v !== []) { $added[$k] = array_map('intval', $v); }
    }
    $after = find_page($pdo, $id);
    notify_assigned($pdo, $id, (string) ($after['plain_title'] ?? ''), $d['plain_title'], $actor, $schema, $added);
    database_log($pdo, 'row.create', 'row', $id, $d['space_id'], ['after' => ['database_id' => $d['database_id'], 'title' => mb_substr((string) ($after['plain_title'] ?? ''), 0, 120), 'keys' => array_keys($values), 'template' => $template]]);
    $pdo->commit();
    return ['id' => $id, 'title' => (string) ($after['plain_title'] ?? '')];
});
sp_done('Added ' . ($res['title'] ?: 'a row'), $res['id'], sp_land(return_path('/databases/' . $d['database_id'] . '/rows/' . $res['id']), 'row'), 'rowChanged', ['row_id' => $res['id'], 'database_id' => $d['database_id']]);
