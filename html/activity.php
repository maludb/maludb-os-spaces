<?php
declare(strict_types=1);
/**
 * /activity — my Activity (screen `activity`; params: since = today|7|30, kind = mention|reply|reaction|comment): who mentioned me, replied to my messages and threads, reacted to my
 * messages, commented on my pages — sp_activity_feed(), the caller's own and nothing else. A row names who (an agent chipped), what, the excerpt, when, and leads to the thread or the page.
 * `?list=1&h=<hash>` is the feed's poll (204 when nothing changed). (The own trail — the activity log — is /trail, slice 9.)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/notify/queries.php';
require_once dirname(__DIR__) . '/app/features/notify/present.php';
require_login();
require_human();
$pdo = db();
$tz = member_timezone();
$sinceKey = (string) ($_GET['since'] ?? '7');
if (!in_array($sinceKey, ['today', '7', '30'], true)) { $sinceKey = '7'; }
$kind = request_string('kind');
if (!isset(ACTIVITY_KINDS[$kind])) { $kind = ''; }
try {
    $since = $sinceKey === 'today' ? (new DateTimeImmutable('today', new DateTimeZone($tz ?: 'UTC')))->setTimezone(new DateTimeZone('UTC')) : new DateTimeImmutable('-' . (int) $sinceKey . ' days', new DateTimeZone('UTC'));
} catch (Exception) {
    $since = new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));
}
$rows = activity_feed($pdo, $since->format('Y-m-d H:i:sP'), $kind === '' ? null : $kind, 100);
$hash = substr(md5(implode(',', array_map(static fn (array $r): string => $r['kind'] . ($r['message_id'] ?? '') . ($r['page_id'] ?? '') . $r['occurred_at'] . $r['actor_member_id'], $rows))), 0, 12);
$data = ['rows' => $rows, 'since' => $sinceKey, 'kind' => $kind, 'hash' => $hash, 'tz' => $tz, 'here' => here_url()];
if (request_string('list') === '1') {
    header('Vary: HX-Request');
    if (($_GET['h'] ?? '') === $hash) {
        http_response_code(204);
        exit;
    }
    echo view('activity/feed.php', $data);
    exit;
}
log_screen_view($pdo, 'activity');
if (wants_json()) {
    respond_screen(['since' => $sinceKey, 'kind' => $kind === '' ? null : $kind, 'activity' => array_map('present_activity_row', $rows)]);
}
render_screen('Activity', view('activity/page.php', $data), ['activeNav' => 'activity', 'screen' => 'activity']);
