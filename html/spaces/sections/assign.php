<?php
declare(strict_types=1);
/** Action `page_section_set` (log `page.section_set`, entity_uuid = the page): an owner puts a root page of the space into a section (empty = none), after another page (empty = first; not sent = last). */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$page = (string) (req_val('page') ?? '');
if (!is_uuid($page)) { sp_refuse_fields(['page' => 'Say which page.']); }
$spaceId = one_value($pdo, 'SELECT space_id FROM mcp_pages WHERE page_id = CAST(:p AS uuid)', ['p' => $page]) ?? refuse(404, 'Page not found.');
$s = find_space($pdo, (int) $spaceId) ?? refuse(404, 'Space not found.');
require_space_owner($s);
if ($s['archived_at'] !== null) { refuse(422, 'Space "' . $s['name'] . '" is archived: nothing changes in it.'); }
$errors = [];
$section = req_has('section') && (string) req_val('section') !== '' ? sp_ref($pdo, 'section', null, 'SELECT 1 FROM mcp_space_sections WHERE section_id = :id', 'section', $errors) : null;
$afterSent = req_has('after');
$after = $afterSent ? (string) req_val('after') : null;
if ($afterSent && $after !== '' && !is_uuid($after)) { $errors['after'] = 'The page to follow is named by its id.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
sp_guard($pdo, static function () use ($pdo, $me, $s, $page, $section, $after, $afterSent): void {
    $pdo->beginTransaction();
    assign_page_section($pdo, $s['space_id'], $page, $section, $after, $afterSent, $me);
    space_log($pdo, 'page.section_set', $s['space_id'], ['after' => ['section_id' => $section, 'after' => $afterSent ? ($after ?: null) : null]], 'page', $page);
    $pdo->commit();
});
sp_done($section === null ? 'Moved the page to the root' : 'Moved the page into its section', $page, sp_land(return_path('/spaces/' . $s['space_id'] . '/sections'), 'section'), 'spaceChanged', ['space_id' => $s['space_id']]);
