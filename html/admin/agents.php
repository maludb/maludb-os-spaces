<?php
declare(strict_types=1);
/** /admin/agents — which agents are members, their dispatches, last reply (screen `agent-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (agents.settings). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-agents', NAV_SLICES['admin-agents'], 'agents.settings');
