<?php
declare(strict_types=1);
/** GET /channels/messages/get.php?message= — one row re-rendered (a screen helper; no log). */
require_once dirname(__DIR__, 3) . '/app/features/messages/handler.php';
require_login();
$pdo = db();
[$m, $c] = message_from_request($pdo);
header('Cache-Control: no-store');
if (wants_json()) { json_response(['data' => ['message' => present_message($m), 'hash' => row_hashes([$m])[(string) $m['message_id']]]]); }
echo message_html($pdo, $m, $c, ['in_thread' => request_bool('thread')]);
