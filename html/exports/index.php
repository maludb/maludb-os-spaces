<?php
declare(strict_types=1);
/** /exports/ — exports of a page, a space, a channel, everything (screen `export-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (export.all). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-exports', NAV_SLICES['admin-exports'], 'export.all');
