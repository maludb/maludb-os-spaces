<?php
declare(strict_types=1);
/** /admin/trash — the whole trash (screen `admin-trash`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (trash.purge). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-trash', NAV_SLICES['admin-trash'], 'trash.purge');
