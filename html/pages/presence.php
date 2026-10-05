<?php
declare(strict_types=1);
/** POST /pages/presence.php {page, content_rev} every 10 s while the tab is visible (a screen helper): who else is here (the file cache) and whether the page moved on ({changed, content_rev, by}). Sets last_seen_at. */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$id = (string) (req_val('page') ?? '');
$p = is_uuid($id) ? find_page($pdo, $id) : null;
if ($p === null) { respond_not_found('Page not found.'); }
$known = request_integer('content_rev') ?? 0;
presence_touch($pdo, true);
$others = page_presence_touch($id, $me, (string) (current_member()['display_name'] ?? 'someone'));
$rev = (int) one_value($pdo, 'SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $id]);
$by = null;
if ($rev > $known) {
    $by = one_value($pdo, 'SELECT display_name FROM members WHERE id = (SELECT last_edited_by FROM pages WHERE id = CAST(:p AS uuid))', ['p' => $id]);
}
header('Cache-Control: no-store');
json_response(['data' => ['others' => $others, 'content_rev' => $rev, 'changed' => $rev > $known, 'by' => $by, 'locked' => (bool) one_value($pdo, 'SELECT is_locked FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $id])]]);
