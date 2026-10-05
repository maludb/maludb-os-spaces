<?php
declare(strict_types=1);
/** /dm/ — my direct messages (screen `dm-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (dm.write|spaces.guest). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('dms', NAV_SLICES['dms'], 'dm.write|spaces.guest');
