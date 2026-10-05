<?php
declare(strict_types=1);
/** Action `row_delete` (log `row.delete`: database_id; confirm; agent approval `deletion`): edit_content; the row to the trash (restore is `page_restore`). */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$r = row_from_request($pdo);
require_page_level($r['page_id'], 'edit_content', 'Database');
sp_guard($pdo, static function () use ($pdo, $me, $r): void {
    $pdo->beginTransaction();
    delete_row($pdo, $r['page_id'], $me);
    database_log($pdo, 'row.delete', 'row', $r['page_id'], $r['space_id'], ['after' => ['database_id' => $r['parent_database_id'], 'title' => mb_substr($r['plain_title'], 0, 120)]]);
    $pdo->commit();
});
sp_done('Moved ' . ($r['plain_title'] ?: 'the row') . ' to the trash', $r['page_id'], sp_land(return_path('/databases/' . $r['parent_database_id']), 'rowdeleted'), 'rowChanged', ['row_id' => $r['page_id'], 'database_id' => $r['parent_database_id']]);
