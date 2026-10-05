<?php
declare(strict_types=1);
/** /proposals/ — the Librarian's proposals (screen `proposal-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (agents.settings). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-proposals', NAV_SLICES['admin-proposals'], 'agents.settings');
