<?php
declare(strict_types=1);
/** GET /pages/mentions.php?q=&kind=member|department|page|emoji|any — the pickers' candidates (a screen helper, JSON, no log). */
require_once dirname(__DIR__, 2) . '/app/features/blocks/handler.php';
require_login();
$pdo = db();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
$kind = in_array($_GET['kind'] ?? '', ['member', 'department', 'page', 'emoji', 'any'], true) ? (string) $_GET['kind'] : 'any';
header('Cache-Control: no-store');
json_response(['data' => ['q' => $q, 'kind' => $kind, 'candidates' => mention_candidates($pdo, $q, $kind)]]);
