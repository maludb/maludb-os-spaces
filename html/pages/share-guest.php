<?php
declare(strict_types=1);
/** Action `share_guest` (log `page.share_guest`; confirm; agent approval `external_send`): full and share.guest; a guest at view, comment or edit. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'full');
require_right('share.guest');
$errors = [];
$guest = sp_ref($pdo, 'guest', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'guest', $errors, false);
$level = (string) (req_val('level') ?? 'view') ?: 'view';
if (!in_array($level, GUEST_LEVELS, true)) { $errors['level'] = 'A guest is shared at view, comment or edit.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
sp_guard($pdo, static function () use ($pdo, $me, $p, $guest, $level): void {
    $pdo->beginTransaction();
    share_page($pdo, $p['page_id'], 'guest', $guest, $level, $me);
    page_log($pdo, 'page.share_guest', $p['page_id'], $p['space_id'], ['after' => ['principal_kind' => 'guest', 'principal_id' => $guest, 'level' => $level]]);
    $pdo->commit();
});
sp_done('Shared ' . ($p['plain_title'] ?: 'the page') . ' with the guest at ' . $level, $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/share'), 'shared'), 'pageChanged', ['page_id' => $p['page_id']]);
