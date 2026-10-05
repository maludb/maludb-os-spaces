<?php
declare(strict_types=1);
/** /saved — the messages I saved (Later) and my reminders (screen `saved`) — built by its slice (NAV_SLICES); until then the shell's placeholder, 200 after the right (spaces.join|spaces.guest). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('saved', NAV_SLICES['saved'], 'spaces.join|spaces.guest');
