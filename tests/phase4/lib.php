<?php
/**
 * Helpers for the Phase 4 proofs (docs/build-specs/mcp-servers.md). Run through tests/phase4/run.sh: a SCRATCH database (sp_dev10 — never the installed one), the application on :8401, a fake kernel on :8402 (run-facts, K6), a fake MaluDB and
 * MaluMail, and the TWO MCP SERVERS on :8404 (records) and :8405 (activity), plus a third records server on :8410 whose kernel URL is dead (the gate must fail closed). Everything a proof makes is named "SMOKE …".
 * Builds on slice 9's helpers (so 8's … 1's and Phase 2's): the cast is the owner (1, super-admin), Priya (26), Marco (27, owner of Product), Bea (28), Ann (29, a guest), Dana (30), Lee (31), Seamus (40, an agent), the Librarian (42, an agent),
 * the Watcher (41, an agent the kernel never vouched for). The world is slice 9's HOME WORLD (which holds slices 1-8's) plus p4_world()'s additions, all named SMOKE p4.
 */
require dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8404;
const ACT = 8405;
const REC_DEAD = 8410;
/** The tools that take no arguments (the client sends no `params`). */
const NO_ARGS = ['app_roles', 'get_settings', 'my_tokens', 'my_favorites', 'my_unread'];

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl) . '.' . $run;
    return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl);
    return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY'));
}
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function ktoken(string $app = 'spaces', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token): array
{
    [$sess] = mcp_open($port, $token);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null]. Arguments are wrapped as {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat)
 * and the tools that take none (app_roles, my_timer, my_reimbursements_due).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = []): array
{
    @file_put_contents(need('SP_DEV_STATE') . '/p4-called.txt', $tool . "\n", FILE_APPEND);
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_ARGS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, '/mcp', $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || (is_array($data) && isset($data['error']) && count($data) <= 2), 'text' => $text, 'data' => is_array($data) ? $data : null];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
/** All the values of a key anywhere in a decoded answer. */
function key_values($data, string $key): array
{
    $out = [];
    if (!is_array($data)) { return $out; }
    foreach ($data as $k => $v) { if ($k === $key) { $out[] = $v; } $out = array_merge($out, key_values($v, $key)); }
    return $out;
}
function sum_key($rows, string $key): float { $s = 0.0; foreach ($rows as $r) { $s += (float) ($r[$key] ?? 0); } return round($s, 2); }
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
/** SQL over the views AS a member (the same rows the server hands that member). */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return $rows;
}
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }
function same_num($a, $b): bool { return abs((float) $a - (float) $b) < 0.005; }


/** The ids of a list of rows under a key (null-safe). */
function ids(?array $rows, string $key): array { return array_values(array_map(fn ($r) => $r[$key], $rows ?? [])); }

/**
 * The Phase 4 world: slice 9's home world (slices 1-8's: spaces Product and Design leads, the pages, the editor's, the channels #smoke-launch and #leads-only, the databases Tasks and Epics, the wiki's pages of every state, the agents Seamus and the
 * Librarian, a published page and the trash), plus — made through the handlers, named SMOKE p4 —
 *   Marco's page "SMOKE p4 Spec" in Product with two paragraphs, two versions saved, a comment of Priya's mentioning Marco (open) and a second she resolved; shared to nobody; Priya's favorite and a recent visit;
 *   a reminder, a saved message and a scheduled message of Marco's, a pin and a bookmark in #smoke-launch, a reaction; "SMOKE p4 Public", published by the owner; a DM Marco -> Priya with a line; a mention of Seamus (a pending dispatch) and a proposal of the Librarian's;
 *   the relation Epics "SMOKE Launch" -> Tasks "SMOKE Fix the login", one export of Marco's, and Priya's own MCP token.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $r = home_world();
    $marco = as_member(27); $priya = as_member(26); $owner = as_member(1);
    $spec = page_by_exact('SMOKE p4 Spec');
    if ($spec === null) {
        $spec = mk_in($marco, $r['product'], 'SMOKE p4 Spec');
        act($marco, '/blocks/append.php', ['page' => $spec, 'markdown' => "SMOKE p4 first paragraph about the rollout.\n\nSMOKE p4 second paragraph."]);
        act($marco, '/pages/versions/save.php', ['page' => $spec]);
        act($marco, '/blocks/append.php', ['page' => $spec, 'markdown' => 'SMOKE p4 third paragraph added later.']);
        act($marco, '/pages/versions/save.php', ['page' => $spec]);
        $blk = (string) one('SELECT id::text FROM blocks WHERE page_id = CAST(:p AS uuid) ORDER BY position LIMIT 1', ['p' => $spec]);
        [, $c1] = act($priya, '/pages/comments/add.php', ['page' => $spec, 'block' => $blk, 'markdown' => 'SMOKE p4 is the rollout date right, @SMOKE Marco?']);
        [, $c2] = act($priya, '/pages/comments/add.php', ['page' => $spec, 'markdown' => 'SMOKE p4 a resolved remark']);
        act($priya, '/pages/comments/resolve.php', ['comment' => (string) ($c2['record_id'] ?? ''), 'resolved' => 'yes']);
        act($priya, '/pages/favorite.php', ['page' => $spec, 'favorite' => 'yes']);
        screen($priya, '/pages/' . $spec);
        [, $m1] = post($marco, $r['launch'], 'SMOKE p4 the rollout starts Monday https://example.com/rollout');
        $m1 = (int) ($m1['record_id'] ?? 0);
        act($marco, '/channels/messages/react.php', ['message' => $m1, 'emoji' => '🎉']);
        act($marco, '/channels/messages/save.php', ['message' => $m1, 'saved' => 'yes']);
        act($marco, '/reminders/save.php', ['remind_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400), 'message' => $m1, 'text' => 'SMOKE p4 check the rollout']);
        act($marco, '/channels/pins/add.php', ['channel' => $r['launch'], 'message' => $m1]);
        act($marco, '/channels/bookmarks/save.php', ['channel' => $r['launch'], 'title' => 'SMOKE p4 rollout doc', 'url' => 'https://example.com/rollout-doc']);
        post($marco, $r['launch'], 'SMOKE p4 scheduled hello', ['schedule_for' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)]);
        [, $dm] = act($marco, '/dm/open.php', ['member' => 26]);
        post($marco, (int) ($dm['record_id'] ?? 0), 'SMOKE p4 a direct line for Priya');
        post($priya, $r['launch'], 'SMOKE p4 @SMOKE Seamus when is the rollout?');
        act_token('/proposals/save.php', ['kind' => 'duplicate', 'title' => 'SMOKE p4 a duplicate', 'reason' => 'Same title twice', 'page' => $r['handbook']], librarian_token());
        $ep = $r['launch_epic']; $fix = $r['fix'];
        act($marco, '/databases/rows/relation.php', ['row' => $ep, 'property' => 'tasks', 'targets' => $fix]);
        act($marco, '/exports/page.php', ['page' => $spec, 'format' => 'md']);
        $pub = mk_in($marco, $r['product'], 'SMOKE p4 Public');
        act($owner, '/pages/publish.php', ['page' => $pub, 'include_subpages' => 'yes']);
        $r['p4_message'] = $m1;
    }
    $r['spec'] = $spec;
    $r['public'] = page_by_exact('SMOKE p4 Public');
    $r['p4_message'] ??= (int) one("SELECT id FROM messages WHERE plain_text LIKE 'SMOKE p4 the rollout starts%' ORDER BY id LIMIT 1");
    return $w = $r;
}

/** The tool surface (docs/spaces-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/spaces-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## Activity server'); $c = strpos($t, '## Coverage');
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $b, $c - $b), $act);
    $gateWords = ['member', 'view', 'comment', 'edit', 'full', 'channel', 'own', 'owner', 'admin'];
    $records = array_values(array_unique(array_merge(array_diff($rec[1], $gateWords), ['app_roles']))); sort($records);
    $activity = $act[1]; sort($activity);
    return $s = ['records' => $records, 'activity' => $activity, 'shares' => ['page_markdown', 'pages_index']];
}
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [tool => []], 'Activity MCP' => ..., 'Actions MCP' => ...]. */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}
