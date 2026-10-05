<?php
declare(strict_types=1);
/**
 * /notifications — the bell's list (screen `notifications`; params: unread, page). Newest first, the unread ones first on "Everything", 50 a page; each row links to its record
 * (notification_record_url). `?count=1[&known=N]` is the header bell's poll (204 when the count is still N — nothing to swap); `?list=1&h=<hash>` the list's own poll
 * (204 when nothing changed, else the list). Neither is logged; the screen is (screen.view). JSON: the notices with their urls and the unread count.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/notify/queries.php';
require_once dirname(__DIR__) . '/app/features/notify/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
if (request_string('count') === '1') {
    header('Vary: HX-Request');
    $unread = unread_notification_count($pdo, $me);
    if (isset($_GET['known']) && (string) $_GET['known'] === (string) $unread) {
        http_response_code(204);
        exit;
    }
    echo view('notifications/partials/bell.php', ['unread' => $unread]);
    exit;
}
$unreadOnly = request_bool('unread');
$page = max(1, (int) (request_integer('page') ?? 1));
$rows = find_my_notifications($pdo, $me, $unreadOnly, $page);
$more = count($rows) > NOTIFICATIONS_PAGE;
$rows = array_slice($rows, 0, NOTIFICATIONS_PAGE);
$unread = unread_notification_count($pdo, $me);
$hash = substr(md5(implode(',', array_map(static fn (array $n): string => $n['notification_id'] . ($n['read_at'] === null ? 'u' : 'r'), $rows)) . '|' . $unread), 0, 12);
$data = ['rows' => $rows, 'unreadOnly' => $unreadOnly, 'page' => $page, 'more' => $more, 'unread' => $unread, 'hash' => $hash, 'tz' => member_timezone(), 'here' => here_url()];
if (request_string('list') === '1') {
    header('Vary: HX-Request');
    if (($_GET['h'] ?? '') === $hash) {
        http_response_code(204);
        exit;
    }
    echo view('notifications/partials/list.php', $data);
    exit;
}
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(['unread_only' => $unreadOnly, 'page' => $page, 'more' => $more, 'unread' => $unread, 'notifications' => array_map('present_notification', $rows)]);
}
render_screen('Notifications', view('notifications/page.php', $data), ['activeNav' => 'notifications', 'screen' => 'notifications']);
