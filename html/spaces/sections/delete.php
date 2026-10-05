<?php
declare(strict_types=1);
/** Action `section_delete` (log `section.delete` — the pages moved to the root; confirm): an owner deletes a section. */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$sectionId = request_integer('section') ?? refuse(422, 'Say which section.');
$spaceId = one_value($pdo, 'SELECT space_id FROM mcp_space_sections WHERE section_id = :id', ['id' => $sectionId]) ?? refuse(404, 'Section not found.');
$s = find_space($pdo, (int) $spaceId) ?? refuse(404, 'Space not found.');
require_space_owner($s);
if ($s['archived_at'] !== null) { refuse(422, 'Space "' . $s['name'] . '" is archived: nothing changes in it.'); }
$moved = sp_guard($pdo, static function () use ($pdo, $me, $s, $sectionId): int {
    $pdo->beginTransaction();
    $moved = delete_section($pdo, $sectionId, $me);
    space_log($pdo, 'section.delete', $s['space_id'], ['after' => ['section_id' => $sectionId, 'pages_moved' => $moved]], 'space_section', $sectionId);
    $pdo->commit();
    return $moved;
});
sp_done('Deleted the section' . ($moved > 0 ? '; ' . $moved . ' page' . ($moved === 1 ? '' : 's') . ' moved to the root' : ''), $sectionId, sp_land(return_path('/spaces/' . $s['space_id'] . '/sections'), 'section'), 'spaceChanged', ['space_id' => $s['space_id'], 'pages_moved' => $moved]);
