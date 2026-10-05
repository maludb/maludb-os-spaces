<?php
declare(strict_types=1);
/** Action `version_save` (log `page.version_save`: version_no, reason manual): edit; a snapshot of the page now. */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$p = page_from_request($pdo);
require_page_level($p['page_id'], $p['parent_database_id'] !== null ? 'edit_content' : 'edit');
$vn = sp_guard($pdo, static function () use ($pdo, $p): int {
    $pdo->beginTransaction();
    $vn = (int) one_value($pdo, "SELECT sp_version_save(CAST(:p AS uuid), 'manual')", ['p' => $p['page_id']]);
    page_log($pdo, 'page.version_save', $p['page_id'], $p['space_id'], ['after' => ['version_no' => $vn, 'reason' => 'manual']]);
    $pdo->commit();
    return $vn;
});
$vid = (int) one_value($pdo, 'SELECT id FROM page_versions WHERE page_id = CAST(:p AS uuid) AND version_no = :v', ['p' => $p['page_id'], 'v' => $vn]);
sp_done('Saved version ' . $vn, $vid, sp_land(return_path('/pages/' . $p['page_id'] . '/history'), 'saved'), 'pageChanged', ['page_id' => $p['page_id'], 'version_no' => $vn, 'version_id' => $vid]);
