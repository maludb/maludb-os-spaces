<?php
declare(strict_types=1);
/** POST /presence.php — the explicit heartbeat the editor and a channel page send every 60 s while visible: members.last_seen_at = now(). 204. Not an action of the manifest. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_post();
require_login();
verify_csrf();
presence_touch(db(), true);
http_response_code(204);
header('Cache-Control: no-store');
exit;
