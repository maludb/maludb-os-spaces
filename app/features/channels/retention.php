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

/** The ids of the attachments held by a page and everything under it (its subpages and rows): covers, icons, row files, the blocks' files, the comments' files. Read BEFORE a purge, so the files can follow the pages. (Slice 9: the manual purge used to leave them behind; the worker's trash pass has its own copy of this query.) */
function subtree_attachment_ids(PDO $pdo, string $root): array
{
    $st = $pdo->prepare("WITH RECURSIVE sub AS (SELECT id FROM pages WHERE id = CAST(:r AS uuid) UNION ALL SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id OR c.parent_database_id = sub.id)
                         SELECT COALESCE((SELECT array_agg(a.id) FROM attachments a WHERE
                                (a.record_type IN ('page_cover', 'page_icon', 'row_files') AND a.record_uuid IN (SELECT id FROM sub))
                             OR (a.record_type = 'block' AND a.record_uuid IN (SELECT b.id FROM blocks b WHERE b.page_id IN (SELECT id FROM sub)))
                             OR (a.record_type = 'comment' AND a.record_uuid IN (SELECT c.id FROM comments c WHERE c.page_id IN (SELECT id FROM sub)))), '{}') AS ids");
    $st->execute(['r' => $root]);
    return pg_int_array((string) $st->fetchColumn());
}

/** After a purge: of these attachments, the ones whose record is gone lose their row and their file. Returns the files removed. */
function drop_gone_attachments(PDO $pdo, array $ids): int
{
    if ($ids === []) { return 0; }
    $st = $pdo->prepare("SELECT a.id, a.storage_path, a.thumbnail_path FROM attachments a WHERE a.id = ANY (CAST(:ids AS bigint[])) AND NOT (
            (a.record_type IN ('page_cover', 'page_icon', 'row_files') AND EXISTS (SELECT 1 FROM pages p WHERE p.id = a.record_uuid))
         OR (a.record_type = 'block' AND EXISTS (SELECT 1 FROM blocks b WHERE b.id = a.record_uuid))
         OR (a.record_type = 'comment' AND EXISTS (SELECT 1 FROM comments c WHERE c.id = a.record_uuid)))");
    $st->execute(['ids' => pg_array_literal($ids)]);
    return drop_attachments($pdo, $st->fetchAll());
}
