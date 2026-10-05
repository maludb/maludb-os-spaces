<?php
declare(strict_types=1);
/** Action `page_publish_rotate` (log `page.publish_rotate`; confirm; agent approval `external_send`): publish.web; a new link, the old one stops. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
sp_handler_begin();
require_right('publish.web');
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'view');
$base = rtrim((string) (env('SP_PUBLIC_BASE_URL') ?: one_value($pdo, 'SELECT public_base_url FROM sp_settings WHERE id = 1') ?: env('APP_URL', '')), '/');
$token = sp_guard($pdo, static function () use ($pdo, $me, $p): string {
    $pdo->beginTransaction();
    $t = rotate_publication($pdo, $p['page_id'], $me);
    page_log($pdo, 'page.publish_rotate', $p['page_id'], $p['space_id'], ['after' => ['rotated' => true]]);
    $pdo->commit();
    return $t;
});
if (!wants_json()) { $_SESSION['published_token'][$p['page_id']] = $token; }
sp_done('A new link for ' . ($p['plain_title'] ?: 'the page') . ': ' . $base . '/p/' . $token, $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/publish'), 'rotated'), 'pageChanged', ['page_id' => $p['page_id'], 'link' => $base . '/p/' . $token]);
