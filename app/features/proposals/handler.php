<?php
declare(strict_types=1);

/** The prelude of a proposal handler (slice 7): the feature's files and the one gate — someone who belongs here (a Member, an owner, an admin, an agent granted Member): sp_is_member_here(). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/pages/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function require_member_here(): void
{
    require_login();
    if (!db_bool(db(), 'SELECT sp_is_member_here()')) {
        refuse(403, 'Proposals are for the people and agents of the workspace, not guests.');
    }
}
