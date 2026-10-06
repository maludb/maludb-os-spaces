<?php
declare(strict_types=1);

/**
 * Retention (slice 8, design D9: off by default): a channel of a space keeps its messages for `retention_days` (1 to 3650; NULL = for ever) and the worker's hourly `retention` step deletes the older ones
 * (sp_pass_retention()) — then the files of what it deleted go too (remove_attachment_files()). A direct message has no retention in v1.
 */

/** Set (or clear, with null) a channel's retention. The database's guard decides what it will not take; a DM or an out-of-range value is refused in words. */
function set_retention(PDO $pdo, int $channelId, ?int $days, int $by): void
{
    $kind = one_value($pdo, 'SELECT kind FROM channels WHERE id = :c', ['c' => $channelId]);
    if ($kind === null) { throw new DomainException('Not found.'); }
    if (in_array($kind, ['dm', 'group_dm'], true)) { throw new DomainException('A direct message has no retention: only a channel of a space keeps messages for a time.'); }
    if ($days !== null && ($days < 1 || $days > 3650)) { throw new DomainException('Days is 1 to 3650, or empty to keep forever.'); }
    $pdo->prepare('UPDATE channels SET retention_days = :d WHERE id = :id')->execute(['d' => $days, 'id' => $channelId]);
}

/** Remove files under storage/ by their stored (relative) path; a path that leaves storage/ is ignored. Returns how many files went. A parent directory left empty goes too. */
function remove_attachment_files(array $paths): int
{
    $root = realpath(APP_ROOT . '/storage');
    $n = 0;
    foreach ($paths as $p) {
        $p = (string) $p;
        if ($p === '' || $p === 'pending' || str_contains($p, '..') || $root === false) { continue; }
        $full = $root . '/' . ltrim($p, '/');
        if (is_file($full) && @unlink($full)) { $n++; }
        $dir = dirname($full);
        if ($dir !== $root && str_starts_with($dir, $root . '/') && is_dir($dir) && count(scandir($dir) ?: []) <= 2) { @rmdir($dir); }
    }
    return $n;
}

/** Delete attachment rows (and their files and thumbnails). $rows: [{id, storage_path, thumbnail_path}]. Returns the files removed. */
function drop_attachments(PDO $pdo, array $rows): int
{
    if ($rows === []) { return 0; }
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    $pdo->prepare('DELETE FROM attachments WHERE id = ANY (CAST(:ids AS bigint[]))')->execute(['ids' => pg_array_literal($ids)]);
    $paths = [];
    foreach ($rows as $r) { $paths[] = $r['storage_path']; if (($r['thumbnail_path'] ?? null) !== null) { $paths[] = $r['thumbnail_path']; } }
    return remove_attachment_files($paths);
}
