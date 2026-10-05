<?php
declare(strict_types=1);
/** GET /pages/{id}/publish — the publication's state (screen `page-publish`). POST — action `page_publish` (log `page.publish`: include_subpages, noindex, layout — never the token; confirm; agent approval `external_send`): publish.web. The link is shown once. */
require_once dirname(__DIR__, 2) . '/app/features/pages/handler.php';
$base = rtrim((string) (env('SP_PUBLIC_BASE_URL') ?: one_value(db(), 'SELECT public_base_url FROM sp_settings WHERE id = 1') ?: env('APP_URL', '')), '/');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    require_human();
    $pdo = db();
    $id = (string) ($_GET['id'] ?? ($_GET['page'] ?? ''));
    $p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
    require_page_level($id, 'view');
    require_right('publish.web');
    $pub = publication($pdo, $id);
    $token = $_SESSION['published_token'][$id] ?? null;
    unset($_SESSION['published_token'][$id]);
    log_screen_view($pdo, 'page-publish');
    if (wants_json()) {
        respond_screen(['page' => present_page($p), 'publication' => present_publication($pub), 'public_base_url' => $base]);
    }
    render_screen('Publish ' . ($p['plain_title'] ?: 'the page'), view('pages/publish.php', ['p' => $p, 'pub' => $pub, 'token' => $token, 'base' => $base, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, page_notices($p))]),
        ['activeNav' => 'pages', 'screen' => 'page-publish', 'entity' => 'page', 'recordId' => $id]);
    exit;
}
sp_handler_begin();
require_right('publish.web');
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'view');
$errors = [];
$opts = ['include_subpages' => sp_yes('include_subpages'), 'noindex' => sp_yes('noindex', true), 'layout' => null, 'public_properties' => request_list('public_properties') ?? []];
if (req_has('layout') && (string) req_val('layout') !== '') {
    $opts['layout'] = (string) req_val('layout');
    if (!in_array($opts['layout'], ['table', 'gallery'], true)) { $errors['layout'] = 'The layout is table or gallery.'; }
}
if ($errors !== []) { sp_refuse_fields($errors); }
$token = sp_guard($pdo, static function () use ($pdo, $me, $p, $opts): string {
    $pdo->beginTransaction();
    $token = publish_page($pdo, $p['page_id'], $opts, $me);
    page_log($pdo, 'page.publish', $p['page_id'], $p['space_id'], ['after' => ['include_subpages' => $opts['include_subpages'], 'noindex' => $opts['noindex'], 'layout' => $opts['layout'], 'public_properties' => count($opts['public_properties'])]]);
    $pdo->commit();
    return $token;
});
$link = $base . '/p/' . $token;
if (!wants_json()) {
    $_SESSION['published_token'][$p['page_id']] = $token;
}
sp_done('Published ' . ($p['plain_title'] ?: 'the page') . ' at ' . $link, $p['page_id'], sp_land(return_path('/pages/' . $p['page_id'] . '/publish'), 'published'), 'pageChanged', ['page_id' => $p['page_id'], 'link' => $link]);
