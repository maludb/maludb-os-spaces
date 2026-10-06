<?php
declare(strict_types=1);

/** The prelude of the import screen and its action (slice 8): the feature's files, the destination gate, the preview store. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/spaces/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/run.php';

/** A preview's directory for a token that is mine (<member>-<16 hex>), or null. */
function import_preview_dir(string $token, int $me): ?string
{
    if (!preg_match('/^(\d+)-[0-9a-f]{16}$/', $token, $m) || (int) $m[1] !== $me) { return null; }
    $d = APP_ROOT . IMPORT_PREVIEW_DIR . '/' . $token;
    return is_dir($d) ? $d : null;
}

/** The one file of a preview directory: [path, name] or null. */
function import_preview_file(string $dir): ?array
{
    foreach (scandir($dir) ?: [] as $f) { if ($f !== '.' && $f !== '..' && is_file($dir . '/' . $f)) { return [$dir . '/' . $f, $f]; } }
    return null;
}

/** The notices of the import screen. */
function import_notices(): array
{
    return ['done' => ['success', 'The import is done.'], 'queued' => ['info', 'The import is queued: the worker runs it within a minute, and this page shows its progress.'], 'failed' => ['danger', 'The import failed: the log says why.']];
}
