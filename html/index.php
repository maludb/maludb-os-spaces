<?php
declare(strict_types=1);
/** / — home (screen `home`): unread, mentions, recent pages, favorites, pages to verify, the Librarian's note; an owner's join requests; the admin's counts. Phase 0: the shape and the real sidebar. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen($s);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'home']);
