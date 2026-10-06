<?php
declare(strict_types=1);
/**
 * Action `export_page` (log `page.export`: export_id, page_id, format, include_subpages): a page as Markdown or HTML — one file, or a zip with its subpages and its files — made at once. The page's view
 * level and the right export.own; what is written is what the caller sees (sp_page_markdown(), sp_page_tree() as them). Params: page, format (md|html), include_subpages (yes|no).
 */
require_once dirname(__DIR__, 2) . '/app/features/exports/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$page = (string) (req_val('page') ?? '');
if (!is_uuid($page)) { sp_refuse_fields(['page' => 'Choose the page from the list.']); }
require_page_level($page, 'view');
require_right('export.own');
$p = find_page($pdo, $page) ?? refuse(404, 'Page not found.');
if ($p['kind'] === 'database') { sp_refuse_fields(['page' => 'That is a database: export it as a database.']); }
$format = (string) (req_val('format') ?? 'md');
$subs = sp_yes('include_subpages', false);
$id = (int) sp_guard($pdo, static function () use ($pdo, $page, $format, $subs, $me): int {
    $pdo->beginTransaction();
    $id = start_export($pdo, 'page', $format === '' ? 'md' : $format, ['page' => $page, 'include_subpages' => $subs], $me);
    $pdo->commit();
    return $id;
});
$r = run_export($pdo, $id);
log_activity($pdo, 'page.export', 'export', $id, ['entity_uuid' => $page, 'space_id' => $p['space_id'], 'after' => ['export_id' => $id, 'page_id' => $page, 'format' => $format === '' ? 'md' : $format, 'include_subpages' => $subs, 'status' => $r['status']]]);
[$words, $notice] = export_outcome($r);
sp_done($words, $id, sp_land('/exports/', $notice), '', ['status' => $r['status'], 'export_id' => $id] + array_intersect_key($r, array_flip(['item_count', 'byte_size', 'error'])));
