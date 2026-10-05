<?php
declare(strict_types=1);
/** Action `template_publish` (log `page.template_publish`: template; agent approval `other`): the owner of the page's space; make a page a template or stop. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
if ($p['space_id'] === null || !db_bool($pdo, 'SELECT sp_is_space_owner(:s)', ['s' => $p['space_id']])) { refuse(403, 'Only an owner of the page\'s space publishes a template.'); }
$on = sp_yes('template', true);
sp_guard($pdo, static function () use ($pdo, $me, $p, $on): void {
    $pdo->beginTransaction();
    set_template($pdo, $p['page_id'], $on, $me);
    page_log($pdo, 'page.template_publish', $p['page_id'], $p['space_id'], ['after' => ['template' => $on]]);
    $pdo->commit();
});
sp_done($on ? ($p['plain_title'] ?: 'The page') . ' is a template now' : ($p['plain_title'] ?: 'The page') . ' is no longer a template', $p['page_id'], sp_land(return_path($on ? '/templates/' : '/pages/' . $p['page_id']), 'template'), 'pageChanged', ['page_id' => $p['page_id'], 'template' => $on]);
