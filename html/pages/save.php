<?php
declare(strict_types=1);
/**
 * Actions `page_create` (no `page`; log `page.create`: title, space_id, parent_page_id, kind, template) / `page_update` (with; log `page.update`: the changed fields of title, icon, cover).
 * Create: in a space's root (edit on the space), under a parent (edit on it), or privately (pages.private). A `template` applies it instead. `markdown` seeds one paragraph.
 */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$pageId = (string) (req_val('page') ?? '');
$errors = [];
if ($pageId !== '') {
    if (!is_uuid($pageId)) { refuse(422, 'Say which page.'); }
    $p = find_page($pdo, $pageId) ?? refuse(404, 'Page not found.');
    require_page_level($pageId, 'edit');
    if ($p['archived_at'] !== null) { refuse(422, 'Page "' . $p['plain_title'] . '" is in the trash: restore it first.'); }
    $cur = page_state($pdo, $pageId);
    $f = [];
    if (req_has('title')) {
        $t = trim((string) req_val('title'));
        if ($t === '' || mb_strlen($t) > 300) { $errors['title'] = 'Give the page a title of up to 300 characters.'; }
        $f['title'] = $t;
    }
    if (req_has('icon')) { $f['icon'] = (string) req_val('icon') === '' ? null : mb_substr((string) req_val('icon'), 0, 16); }
    if (req_has('cover')) {
        $c = (string) req_val('cover');
        $f['cover_attachment_id'] = $c === '' ? null : (filter_var($c, FILTER_VALIDATE_INT) === false ? null : (int) $c);
        if ($c !== '' && $f['cover_attachment_id'] === null) { $errors['cover'] = 'The cover is an attachment id.'; }
    }
    if ($errors !== []) { sp_refuse_fields($errors); }
    sp_guard($pdo, static function () use ($pdo, $me, $pageId, $cur, $f): void {
        $pdo->beginTransaction();
        update_page($pdo, $pageId, $f, $me);
        $d = sp_diff(page_loggable($cur), page_loggable(page_state($pdo, $pageId)));
        if ($d['after'] !== []) {
            page_log($pdo, 'page.update', $pageId, $cur['space_id'], ['before' => $d['before'], 'after' => $d['after']]);
        }
        $pdo->commit();
    });
    sp_done('Saved ' . ($f['title'] ?? $cur['plain_title'] ?: 'the page'), $pageId, sp_land(return_path('/pages/' . $pageId), 'saved'), 'pageChanged', ['page_id' => $pageId]);
}
// create
$title = trim((string) (req_val('title') ?? ''));
if ($title === '' || mb_strlen($title) > 300) { $errors['title'] = 'Give the page a title of up to 300 characters.'; }
$icon = (string) (req_val('icon') ?? '') === '' ? null : mb_substr((string) req_val('icon'), 0, 16);
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$private = sp_yes('private');
$template = req_has('template') && (string) req_val('template') !== '' ? (string) req_val('template') : null;
$markdown = req_val('markdown');
if ($parent !== null && !is_uuid($parent)) { $errors['parent'] = 'The parent is named by its id.'; }
if ($template !== null && (!is_uuid($template) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:t AS uuid) AND is_template)', ['t' => $template]))) { $errors['template'] = 'That template is not here.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
if ($parent !== null) {
    $pp = find_page($pdo, $parent) ?? refuse(404, 'Parent page not found.');
    require_page_level($parent, 'edit', 'Parent page');
    if ($pp['archived_at'] !== null) { refuse(422, 'The parent page is in the trash.'); }
    $space = $pp['space_id'];
} elseif ($space !== null && !$private) {
    $s = one_value($pdo, 'SELECT name FROM mcp_spaces WHERE space_id = :s AND archived_at IS NULL', ['s' => $space]) ?? refuse(404, 'Space not found.');
    require_right('pages.write');
    if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $space])) { refuse(403, 'You may not create pages in ' . $s . '.'); }
} else {
    require_right('pages.private');
    $space = null;
}
$newId = sp_guard($pdo, static function () use ($pdo, $me, $space, $parent, $title, $icon, $template, $markdown): string {
    $pdo->beginTransaction();
    $id = create_page($pdo, $space, $parent, $title, $icon, $template, $markdown, $me);
    page_log($pdo, 'page.create', $id, $space, ['after' => ['title' => mb_substr($title, 0, 120), 'space_id' => $space, 'parent_page_id' => $parent, 'kind' => 'page', 'template' => $template, 'private' => $space === null, 'markdown_length' => mb_strlen((string) $markdown)]]);
    $pdo->commit();
    return $id;
});
sp_done('Made ' . $title, $newId, sp_land(return_path('/pages/' . $newId), 'created'), 'pageChanged', ['page_id' => $newId]);
