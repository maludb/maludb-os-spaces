<?php
declare(strict_types=1);

/**
 * POST /api/v1/shares/read.php — the records MCP server's door to the two shares (docs/build-specs/mcp-servers.md). INTERNAL PORT ONLY (the public vhost blocks /api). The server has already verified the bearer (the
 * kernel's token, or a person's or agent's own); it signs the request with ACTIONS_RELAY_KEY — HMAC-SHA256 over "share:<unix time>:<sha256 of the body>", headers X-Share-Time and X-Share-Signature, ±120 s — a key no agent
 * holds. Body: {"tool": pages_index|page_markdown, "caller": "kernel"|"person", "member_id": <the verified member, for a person>, "arguments": {...}}. The answer is the document itself (app/features/shares/queries.php);
 * a refusal is 403 {"error": <the sentence>}, a bad argument 422, a bad signature 401. A kernel call runs as the agent `arguments.as_agent` names (see that file); it logs `share.read`.
 */
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/features/shares/queries.php';

function share_relay_reply(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    share_relay_reply(['error' => 'POST only.'], 405);
}
$body = (string) file_get_contents('php://input');
$time = (string) ($_SERVER['HTTP_X_SHARE_TIME'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_SHARE_SIGNATURE'] ?? '');
$key = (string) env('ACTIONS_RELAY_KEY', '');
if (strlen($key) < 32 || !ctype_digit($time) || abs(time() - (int) $time) > 120 || !hash_equals(hash_hmac('sha256', 'share:' . $time . ':' . hash('sha256', $body), $key), $sig)) {
    share_relay_reply(['error' => 'unauthorized'], 401);
}
$in = json_decode($body, true);
if (!is_array($in) || !is_string($in['tool'] ?? null) || !in_array($in['caller'] ?? null, ['kernel', 'person'], true)) {
    share_relay_reply(['error' => 'The request names a tool and a caller.'], 422);
}
$caller = (string) $in['caller'];
$a = is_array($in['arguments'] ?? null) ? $in['arguments'] : [];
$pdo = db();
try {
    if (!in_array($in['tool'], ['pages_index', 'page_markdown'], true)) {
        throw new DomainException('That is not one of the two shared tools.');
    }
    if ($caller === 'person') {
        $member = $in['member_id'] ?? null;
        if (!is_int($member) || $member < 1 || !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL)", ['m' => $member])) {
            share_relay_reply(['error' => 'That member is not admitted.'], 403);
        }
        $agent = null;
    } else {
        $agent = share_agent($pdo, $a['as_agent'] ?? null);
        $member = $agent;
    }
    $given = $a;
    unset($given['as_agent']);
    if ($in['tool'] === 'pages_index') {
        $offset = isset($a['cursor']) && ctype_digit((string) $a['cursor']) ? (int) $a['cursor'] : 0;
        $q = isset($a['q']) && $a['q'] !== '' ? mb_substr((string) $a['q'], 0, 200) : null;
        $doc = $member === null
            ? share_document('os.spaces-pages/1', 0, ['rows' => [], 'total' => 0, 'next_cursor' => null, 'note' => 'No requesting expert agent was named, so there is nothing this application may read.'])
            : share_pages_index($pdo, (int) $member, $q, (int) ($a['limit'] ?? 25), $offset);
        $doc['as_member'] = $member;
        $rows = count($doc['rows']);
    } else {
        if ($member === null) {
            throw new ShareRefused('page_markdown reads as one of your application\'s expert agents: name it in as_agent.');
        }
        $doc = share_page_markdown($pdo, (int) $member, (string) ($a['page'] ?? ''));
        $rows = 1;
    }
    share_log($pdo, $caller, (string) $in['tool'], $given, $rows, $agent);
} catch (ShareRefused $e) {
    share_relay_reply(['error' => $e->getMessage()], 403);
} catch (DomainException $e) {
    share_relay_reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('shares/read: ' . $e->getMessage());
    share_relay_reply(['error' => 'The document could not be built.'], 500);
}
share_relay_reply($doc, 200);
