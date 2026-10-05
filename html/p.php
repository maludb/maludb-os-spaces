<?php
declare(strict_types=1);
/**
 * /p/{token}, /p/{token}/{page}, /p/{token}/files/{id} — the public door (design §4, D13; screen `public-page`): no session is consulted;
 * sp_public_page_lookup(sha256(token)) as the writer with no acting member; the page rendered by the same reader; subpages listed when the
 * publication includes them; images and files only of those pages; noindex by the row; 60 views a minute per address; views counted; logged
 * `page.public_view` with source portal and the publication id — never the token. A revoked or trashed page: one 404 page with the business's name.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/public/queries.php';
require_once dirname(__DIR__) . '/app/features/public/render.php';
$GLOBALS['__public_door'] = 'portal';
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '', false)");                      // the door has no member: the token is the authority
$business = (string) (one_value($pdo, 'SELECT business_name FROM sp_settings WHERE id = 1') ?: app_name());
$notFound = static function () use ($business): never {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo view('public/notfound.php', ['business' => $business]);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method Not Allowed');
}
if (public_rate_limited($pdo, client_ip())) {
    http_response_code(429);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    exit('Too many requests. Try again in a minute.');
}
$token = (string) ($_GET['token'] ?? '');
$pub = public_lookup($pdo, $token) ?? $notFound();
$ids = public_page_ids($pdo, $pub['publication_id']);
$fileId = request_integer('file');
if ($fileId !== null) {
    $a = public_attachment($pdo, $fileId, $ids) ?? $notFound();
    $root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
    $full = realpath($root . '/' . ltrim((string) $a['storage_path'], '/'));
    if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) { $notFound(); }
    header('Content-Type: ' . $a['mime_type']);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $a['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    header('X-Robots-Tag: noindex');
    readfile($full);
    exit;
}
$pageId = is_uuid($_GET['page'] ?? null) ? (string) $_GET['page'] : (string) $pub['page_id'];
if (!in_array($pageId, $ids, true)) { $notFound(); }
$page = public_page($pdo, $pageId) ?? $notFound();
$embedHosts = pg_text_array((string) one_value($pdo, 'SELECT allowed_embed_hosts::text FROM sp_settings WHERE id = 1'));
$body = public_render_page($pdo, $page, $token, $ids, $embedHosts);
$children = $pub['include_subpages'] ? public_children($pdo, $pageId, $ids) : [];
$crumbs = [];
if ($pageId !== $pub['page_id']) {
    $st = $pdo->prepare('SELECT page_id::text AS page_id, plain_title FROM sp_page_ancestors(CAST(:id AS uuid)) WHERE page_id = ANY (CAST(:ids AS uuid[]))');
    $st->execute(['id' => $pageId, 'ids' => '{' . implode(',', $ids) . '}']);
    $crumbs = $st->fetchAll();
}
$props = [];
if ($page['parent_database_id'] !== null && $pub['public_properties'] !== []) {
    $all = json_decode((string) one_value($pdo, 'SELECT sp_row_resolved(CAST(:id AS uuid))::text', ['id' => $pageId]), true) ?: [];
    foreach ($pub['public_properties'] as $k) { if (array_key_exists($k, $all)) { $props[$k] = $all[$k]; } }
}
bump_public_views($pdo, $pub['publication_id']);
log_activity($pdo, 'page.public_view', 'page', $pageId, ['actor_member_id' => null, 'source' => 'portal', 'after' => ['publication_id' => $pub['publication_id'], 'title' => mb_substr((string) $page['plain_title'], 0, 120)]]);
header('Cache-Control: no-store');
if ($pub['noindex']) { header('X-Robots-Tag: noindex, nofollow'); }
echo view('public/page.php', ['business' => $business, 'page' => $page, 'bodyHtml' => $body, 'children' => $children, 'crumbs' => $crumbs, 'token' => $token, 'noindex' => $pub['noindex'], 'props' => $props, 'root' => (string) $pub['page_id']]);
