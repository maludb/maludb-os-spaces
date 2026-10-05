<?php
declare(strict_types=1);
/** /pages/ — the pages I may see, by space (screen `page-list`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (spaces.join|spaces.guest). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('pages', NAV_SLICES['pages'], 'spaces.join|spaces.guest');
