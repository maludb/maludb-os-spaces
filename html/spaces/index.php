<?php
declare(strict_types=1);
/** /spaces/ — the spaces as cards by kind (screen `space-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (spaces.join). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('spaces', NAV_SLICES['spaces'], 'spaces.join');
