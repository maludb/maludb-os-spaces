<?php
declare(strict_types=1);
/** /admin/retention — retention on channels, versions and the trash (screen `admin-retention`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (retention.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin-retention', NAV_SLICES['admin-retention'], 'retention.manage');
