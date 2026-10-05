<?php
declare(strict_types=1);
/** Action `page_owner_set` (log `page.owner_set`: member_id): full; a wiki page's owner; the new owner is told. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
$errors = [];
$member = sp_ref($pdo, 'member', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors, false);
if ($errors !== []) { sp_refuse_fields($errors); }
sp_guard($pdo, static function () use ($pdo, $me, $p, $member): void {
    $pdo->beginTransaction();
    set_page_owner($pdo, $p['page_id'], $member, $me);
    page_log($pdo, 'page.owner_set', $p['page_id'], $p['space_id'], ['before' => ['member_id' => $p['wiki_owner_member_id']], 'after' => ['member_id' => $member]]);
    $pdo->commit();
});
sp_done('Saved who owns ' . ($p['plain_title'] ?: 'the page'), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), 'owner'), 'pageChanged', ['page_id' => $p['page_id'], 'member_id' => $member]);
