<?php
declare(strict_types=1);
/** /activity — my Activity: who mentioned me, replied to me, reacted to me, commented on my pages (screen `activity`; sp_activity_feed). Built by slice 6. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('activity', NAV_SLICES['activity'], 'spaces.join|spaces.guest');
