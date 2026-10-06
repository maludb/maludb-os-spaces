<?php
declare(strict_types=1);

/** The prelude of the exports screen and its actions (slice 8). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/pages/queries.php';
require_once dirname(__DIR__) . '/spaces/queries.php';
require_once dirname(__DIR__) . '/channels/queries.php';
require_once __DIR__ . '/run.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

function export_notices(): array
{
    return ['made' => ['success', 'Your export is ready below.'], 'queued' => ['info', 'Your export is queued: the worker makes it within a minute and this list updates itself.'], 'deleted' => ['success', 'The export file is deleted.'], 'failed' => ['danger', 'That export failed: its row says why.']];
}

/** What an action answers after a request-run export: the words and the notice key. */
function export_outcome(array $r): array
{
    return $r['status'] === 'done' ? ['Made the export', 'made'] : ($r['status'] === 'failed' ? ['The export failed', 'failed'] : ['Queued the export', 'queued']);
}
