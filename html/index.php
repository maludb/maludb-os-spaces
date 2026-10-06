<?php
declare(strict_types=1);
/**
 * / — home (screen `home`): everyone's landing page, nine regions each from an earlier slice's own query function — Unread (DMs first), Waiting for me (mentions and replies since the last visit), Needs my verification,
 * Pending joins (a space owner), Recently edited, Favorites, the Librarian's note, Unanswered (a space owner), the admin's three cards. Shown to who has them; an empty one says what will appear.
 * Home logs screen.view and remembers when it was opened (the session's home_seen_at) so the next visit's "waiting" starts there; it writes nothing else.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/home/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$seen = isset($_SESSION['home_seen_at']) && is_string($_SESSION['home_seen_at']) ? $_SESSION['home_seen_at'] : null;
$s = home_summary($pdo, $me, $seen);
if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['home_seen_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'); }
if (wants_json()) {
    respond_screen(present_home($s));
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone(), 'here' => here_url()]), ['activeNav' => 'home', 'screen' => 'home']);
