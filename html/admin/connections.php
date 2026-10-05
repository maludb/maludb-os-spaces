<?php
declare(strict_types=1);
/** /admin/connections — which siblings read us (screen `connection-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-connections', NAV_SLICES['admin-connections'], 'settings.manage');
