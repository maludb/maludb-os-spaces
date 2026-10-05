<?php
declare(strict_types=1);
/** Action `section_save` (log `section.save`): an owner adds a section (after another, or last), renames one (`section` + name) or moves one (`section` + after; after empty = first). A duplicate name is refused in words. */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$errors = [];
$sectionId = sp_ref($pdo, 'section', null, 'SELECT 1 FROM mcp_space_sections WHERE section_id = :id', 'section', $errors);
$name = req_has('name') ? trim((string) req_val('name')) : null;
if ($name !== null && ($name === '' || mb_strlen($name) > 60)) { $errors['name'] = 'A section has a name of up to 60 characters.'; }
if ($sectionId === null && $name === null) { $errors['name'] = 'Give the section a name.'; }
$afterSent = req_has('after');
$after = $afterSent && (string) req_val('after') !== '' ? sp_ref($pdo, 'after', null, 'SELECT 1 FROM mcp_space_sections WHERE section_id = :id', 'section to follow', $errors) : null;
if ($errors !== []) { sp_refuse_fields($errors); }
$id = sp_guard($pdo, static function () use ($pdo, $me, $s, $sectionId, $name, $after, $afterSent): int {
    $pdo->beginTransaction();
    $id = save_section($pdo, $sectionId, $s['space_id'], $name, $after, $afterSent, $me);
    space_log($pdo, 'section.save', $s['space_id'], ['after' => array_filter(['section_id' => $id, 'name' => $name, 'after' => $afterSent ? ($after ?? 0) : null, 'new' => $sectionId === null], static fn ($v) => $v !== null)], 'space_section', $id);
    $pdo->commit();
    return $id;
});
sp_done($sectionId === null ? 'Added the section ' . $name : 'Saved the section', $id, sp_land(return_path('/spaces/' . $s['space_id'] . '/sections'), 'section'), 'spaceChanged', ['space_id' => $s['space_id']]);
