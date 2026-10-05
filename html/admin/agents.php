<?php
declare(strict_types=1);
/**
 * /admin/agents — the agents that are members here (screen `agent-list`; right agents.settings): their roles, the spaces and channels they are in, their last reply, pending and failed dispatches.
 * Read-only: hiring, grants and duties are the kernel's — links to the OS's Agent HR.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_right('agents.settings');
$pdo = db();
$agents = agents_here($pdo);
log_screen_view($pdo, 'agent-list');
if (wants_json()) {
    respond_screen(['agents' => array_map('present_agent', $agents), 'os' => ['agent_hr' => ($b = rtrim((string) env('OS_LAUNCHER_URL', ''), '/')) === '' ? null : $b . '/agents']]);
}
render_screen('Agents', view('admin/agents.php', ['agents' => $agents, 'here' => here_url(), 'tz' => member_timezone()]), ['activeNav' => 'admin-agents', 'screen' => 'agent-list']);
