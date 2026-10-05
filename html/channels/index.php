<?php
declare(strict_types=1);
/** /channels/ — browse channels per space; join, follow (screen `channel-browse`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (spaces.join|spaces.guest). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('channels', NAV_SLICES['channels'], 'spaces.join|spaces.guest');
