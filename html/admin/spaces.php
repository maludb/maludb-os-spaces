<?php
declare(strict_types=1);
/** /admin/spaces — every space, the private ones marked and logged when opened (screen `admin-spaces`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-spaces', NAV_SLICES['admin-spaces'], 'settings.manage');
