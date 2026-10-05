<?php
declare(strict_types=1);
/** Action `page_verify` (log `page.verify`: months, verify_until): the wiki owner or the space owner; months 1, 3, 6 or 12 (the space's default). */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'view');
if ($p['wiki_owner_member_id'] !== $me && ($p['space_id'] === null || !db_bool($pdo, 'SELECT sp_is_space_owner(:s)', ['s' => $p['space_id']]))) { refuse(403, 'Only the page\'s wiki owner or the space\'s owner verifies it.'); }
$errors = [];
$months = sp_int('months', null, 1, 12, 'Months', $errors, true);
if ($months !== null && !in_array($months, VERIFY_MONTHS, true)) { $errors['months'] = 'Verify for 1, 3, 6 or 12 months.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
$until = sp_guard($pdo, static function () use ($pdo, $me, $p, $months): string {
    $pdo->beginTransaction();
    $until = verify_page($pdo, $p['page_id'], $months, $me);
    page_log($pdo, 'page.verify', $p['page_id'], $p['space_id'], ['after' => ['months' => $months, 'verify_until' => json_ts($until)]]);
    $pdo->commit();
    return $until;
});
sp_done('Verified ' . ($p['plain_title'] ?: 'the page') . ' until ' . format_date($until), $p['page_id'], sp_land(return_path('/pages/' . $p['page_id']), 'verified'), 'pageChanged', ['page_id' => $p['page_id'], 'verify_until' => json_ts($until)]);
