<?php
declare(strict_types=1);
/** /search — pages, rows, messages and comments with the modifiers as chips (screen `search`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (spaces.join|spaces.guest). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('search', NAV_SLICES['search'], 'spaces.join|spaces.guest');
