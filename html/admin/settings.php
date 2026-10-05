<?php
declare(strict_types=1);
/** /admin/settings — the workspace settings (screen `admin-settings`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-settings', NAV_SLICES['admin-settings'], 'settings.manage');
