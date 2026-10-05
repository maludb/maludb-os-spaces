<?php
declare(strict_types=1);
/** /admin/published — every published page (screen `admin-published`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-published', NAV_SLICES['admin-published'], 'settings.manage');
