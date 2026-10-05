<?php
declare(strict_types=1);
/**
 * /admin/dispatches — every dispatch of a mention or a DM to an agent (screen `dispatch-list`; right agents.settings; params: status = pending|running|answered|awaiting_approval|refused|failed, agent, page):
 * the agent, the asker, the kind, the channel, the message's first line, the status, attempts, the run, when, the reply's excerpt; Retry on a failed one. 25 a page; `?list=1&h=` is the poll (204 when nothing changed).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_right('agents.settings');
$pdo = db();
$status = request_string('status');
if (!isset(DISPATCH_STATUSES[$status])) { $status = ''; }
$agent = request_integer('agent');
$page = max(1, request_integer('page') ?? 1);
$res = find_dispatches($pdo, ['status' => $status, 'agent' => $agent], $page);
$agents = array_map(static fn (array $a): array => ['member_id' => $a['member_id'], 'display_name' => $a['display_name']], agents_here($pdo));
$hash = substr(md5(implode(',', array_map(static fn (array $d): string => $d['dispatch_id'] . $d['status'] . $d['attempts'] . ($d['run_id'] ?? '') . ($d['answered_at'] ?? ''), $res['rows']))), 0, 12);
$data = ['rows' => $res['rows'], 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'status' => $status, 'agent' => $agent, 'agents' => $agents, 'hash' => $hash, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['retried' => ['success', 'Back in the queue: the next pass tries it again.']])];
if (request_string('list') === '1') {
    header('Vary: HX-Request');
    if (($_GET['h'] ?? '') === $hash) { http_response_code(204); exit; }
    echo view('admin/partials/dispatch-list.php', $data);
    exit;
}
log_screen_view($pdo, 'dispatch-list');
if (wants_json()) {
    respond_screen(['status' => $status === '' ? null : $status, 'agent' => $agent, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'dispatches' => array_map('present_dispatch', $res['rows'])]);
}
render_screen('Dispatches', view('admin/dispatches.php', $data), ['activeNav' => 'admin-agents', 'screen' => 'dispatch-list']);
