<?php
declare(strict_types=1);

/**
 * Running an export (slice 8). start_export() files the request (an `exports` row, queued, with its parameters — db/023's `params`); run_export() writes the file under storage/exports/<id>/ AS THE
 * MEMBER WHO ASKED (so every reader of writers.php answers for them and nothing they may not see is written), marks the row done with its size and count — and a `record_type = 'export'`
 * attachment so the gated /files/{id} door serves it too — or failed with the sentence. A page or a database runs in the request; a space, a channel or everything waits for the worker's `exports`
 * step (export_pass()), and the list polls. 7 days later sp_pass_exports_expire() takes the path away and the worker removes the file.
 */
require_once dirname(__DIR__) . '/imports/run.php';
require_once dirname(__DIR__) . '/spaces/queries.php';
require_once dirname(__DIR__) . '/channels/queries.php';
require_once __DIR__ . '/writers.php';

const EXPORT_FORMATS = ['page' => ['md', 'html'], 'database' => ['csv', 'json'], 'space' => ['md', 'html', 'json'], 'channel' => ['json', 'md', 'csv'], 'all' => ['zip']];
const EXPORT_MIME = ['md' => 'text/markdown', 'html' => 'text/html', 'csv' => 'text/csv', 'json' => 'application/json', 'zip' => 'application/zip'];
const EXPORT_STATUS_TONE = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];

/**
 * File an export request. $target: page (uuid) + include_subpages, database (uuid) + view, space (id), channel (id) + from + to. The gate (who may ask) is the handler's; a format a kind does not
 * have, or a missing or malformed target, is a DomainException in words. Returns the export's id.
 */
function start_export(PDO $pdo, string $kind, string $format, array $target, int $by): int
{
    if (!isset(EXPORT_FORMATS[$kind])) { throw new DomainException('An export is of a page, a database, a space, a channel or everything.'); }
    $format = $format === '' ? EXPORT_FORMATS[$kind][0] : ($kind === 'space' && $format === 'zip' ? 'md' : $format);
    if (!in_array($format, EXPORT_FORMATS[$kind], true)) { throw new DomainException('A ' . $kind . ' export is ' . implode(' or ', EXPORT_FORMATS[$kind]) . '.'); }
    $params = [];
    foreach (['from', 'to'] as $d) {
        if (($target[$d] ?? null) !== null && $target[$d] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $target[$d]) || strtotime((string) $target[$d]) === false) { throw new DomainException(ucfirst($d) . ' is a date like 2026-10-31.'); }
            $params[$d] = (string) $target[$d];
        }
    }
    if (isset($params['from'], $params['to']) && $params['from'] > $params['to']) { throw new DomainException('The period ends before it starts.'); }
    if (($target['view'] ?? null) !== null && $target['view'] !== '') { $params['view_id'] = (string) $target['view']; }
    $st = $pdo->prepare('INSERT INTO exports (kind, format, page_id, space_id, channel_id, include_subpages, created_by, params) VALUES (:k, :f, CAST(:p AS uuid), :s, :c, :i, :by, CAST(:params AS jsonb)) RETURNING id');
    $page = $kind === 'page' ? (string) ($target['page'] ?? '') : ($kind === 'database' ? (string) ($target['database'] ?? '') : '');
    if (in_array($kind, ['page', 'database'], true) && !is_uuid($page)) { throw new DomainException('Say which ' . $kind . '.'); }
    if ($kind === 'space' && !isset($target['space'])) { throw new DomainException('Say which space.'); }
    if ($kind === 'channel' && !isset($target['channel'])) { throw new DomainException('Say which channel.'); }
    $st->execute(['k' => $kind, 'f' => $format, 'p' => $page === '' ? null : $page, 's' => $kind === 'space' ? (int) $target['space'] : null, 'c' => $kind === 'channel' ? (int) $target['channel'] : null,
                  'i' => ($target['include_subpages'] ?? ($kind === 'page' ? false : true)) ? 't' : 'f', 'by' => $by, 'params' => json_encode((object) $params)]);
    return (int) $st->fetchColumn();
}

/** The word that names a file: "<title>.zip". Returns [relative dir under storage/, full dir]. */
function export_dir(int $id): array
{
    return ['exports/' . $id, APP_ROOT . '/storage/exports/' . $id];
}

/** Build the export's bytes into a file. Returns [file name, mime, item count, log line]. $dir is the destination folder (made). */
function export_build(PDO $pdo, array $e, string $dir): array
{
    $params = json_decode((string) ($e['params'] ?? '{}'), true) ?: [];
    $fmt = (string) $e['format'];
    $files = [];
    $write = static function (string $name, string $bytes) use ($dir): string { file_put_contents($dir . '/' . $name, $bytes); return $name; };
    $zipTo = static function (string $name) use ($dir): ZipArchive {
        $z = new ZipArchive();
        if ($z->open($dir . '/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new DomainException('The export file could not be made.'); }
        return $z;
    };
    switch ($e['kind']) {
        case 'page':
            $pid = (string) $e['page_id'];
            $p = find_page($pdo, $pid) ?? throw new DomainException('That page is not here.');
            $title = (string) $p['plain_title'];
            $base = export_safe_name($title);
            $subs = !empty($e['include_subpages']) ? export_page_subtree($pdo, $pid) : [];
            if ($subs === []) {
                $text = export_page_text($pdo, $pid, $fmt, 0, $files, $title);
                if ($files === []) { return [$write($base . ($fmt === 'html' ? '.html' : '.md'), $text), EXPORT_MIME[$fmt], 1, 'one page']; }
                $name = $base . '.zip';
                $z = $zipTo($name);
                $z->addFromString($base . ($fmt === 'html' ? '.html' : '.md'), $text);
                $nf = export_add_files($z, $files);
                $z->close();
                return [$name, 'application/zip', 1 + $nf, 'one page and ' . $nf . ' files'];
            }
            $name = $base . '.zip';
            $z = $zipTo($name);
            $cx = ['pdo' => $pdo, 'fmt' => $fmt, 'files' => &$files, 'count' => 0, 'used' => [], 'index' => []];
            $ext = $fmt === 'html' ? '.html' : '.md';
            $z->addFromString($base . $ext, export_page_text($pdo, $pid, $fmt, 0, $files, $title));
            $cx['count']++;
            $cx['used'][strtolower($base . $ext)] = true;
            $cx['used'][strtolower($base)] = true;
            $by = ['root' => []] + $subs;
            foreach ($subs[$pid] ?? [] as $child) { export_write_node($cx, $z, $child, $base, 0, $subs); }
            $nf = export_add_files($z, $files);
            $z->close();
            return [$name, 'application/zip', $cx['count'] + $nf, $cx['count'] . ' pages and ' . $nf . ' files'];
        case 'database':
            $dbid = (string) $e['page_id'];
            $title = (string) (find_database($pdo, $dbid)['title'] ?? 'Database');
            [$bytes, $n] = $fmt === 'json' ? export_database_json($pdo, $dbid, $params['view_id'] ?? null) : export_database_csv($pdo, $dbid, $params['view_id'] ?? null);
            return [$write(export_safe_name($title) . '.' . $fmt, $bytes), EXPORT_MIME[$fmt], $n, $n . ' rows'];
        case 'space':
            $sid = (int) $e['space_id'];
            $sp = find_space($pdo, $sid) ?? throw new DomainException('That space is not here.');
            if ($fmt === 'json') {
                $doc = ['space' => ['space_id' => $sid, 'name' => $sp['name']], 'pages' => [], 'databases' => []];
                $by = export_page_index($pdo, $sid);
                foreach ($by as $k => $list) {
                    foreach ($list as $p) {
                        if ($p['kind'] === 'database') { [$j] = export_database_json($pdo, $p['id'], null); $doc['databases'][] = json_decode($j, true); continue; }
                        $doc['pages'][] = ['page_id' => $p['id'], 'title' => $p['title'], 'parent_page_id' => $p['parent'], 'database_id' => $p['database'], 'markdown' => (string) one_value($pdo, 'SELECT sp_page_markdown(CAST(:p AS uuid), false)', ['p' => $p['id']])];
                    }
                }
                $n = count($doc['pages']) + count($doc['databases']);
                return [$write(export_safe_name((string) $sp['name']) . '.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'application/json', $n, $n . ' pages and databases'];
            }
            $name = export_safe_name((string) $sp['name']) . '.zip';
            $z = $zipTo($name);
            $n = export_space_into($pdo, $z, $sid, (string) $sp['name'], '', $fmt, $files);
            $nf = export_add_files($z, $files);
            $z->close();
            return [$name, 'application/zip', $n + $nf, $n . ' pages and ' . $nf . ' files'];
        case 'channel':
            [$bytes, $n] = export_channel_text($pdo, (int) $e['channel_id'], $fmt, $params['from'] ?? null, $params['to'] ?? null);
            $c = $pdo->prepare('SELECT c.name, s.name AS space_name FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id WHERE c.channel_id = :c');
            $c->execute(['c' => (int) $e['channel_id']]);
            $ch = $c->fetch() ?: ['name' => 'channel', 'space_name' => null];
            return [$write(export_safe_name(($ch['space_name'] !== null ? $ch['space_name'] . '-' : '') . $ch['name']) . '.' . $fmt, $bytes), EXPORT_MIME[$fmt], $n, $n . ' messages'];
        case 'all':
            $name = 'spaces-' . date('Ymd') . '.zip';
            $z = $zipTo($name);
            $n = 0;
            $used = [];
            $spaces = $pdo->query('SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL ORDER BY name')->fetchAll();
            foreach ($spaces as $sp) {
                $folder = export_unique($used, '', export_safe_name((string) $sp['name']), '');
                $n += export_space_into($pdo, $z, (int) $sp['space_id'], (string) $sp['name'], $folder . '/', 'md', $files);
                $chs = $pdo->prepare("SELECT channel_id, name FROM mcp_channels WHERE space_id = :s AND kind IN ('public', 'private') AND archived_at IS NULL ORDER BY name");
                $chs->execute(['s' => (int) $sp['space_id']]);
                foreach ($chs->fetchAll() as $c) {
                    [$md, $m] = export_channel_text($pdo, (int) $c['channel_id'], 'md', null, null);
                    $z->addFromString($folder . '/channels/' . export_safe_name((string) $c['name']) . '.md', $md);
                    $n++;
                }
            }
            $nf = export_add_files($z, $files);
            $z->close();
            return [$name, 'application/zip', $n + $nf, count($spaces) . ' spaces, ' . $n . ' pages and channels, ' . $nf . ' files'];
    }
    throw new DomainException('That kind of export is not known.');
}

/** The visible subpages of a page (databases included, their rows too), grouped by parent as export_page_index() does. [] when there are none. */
function export_page_subtree(PDO $pdo, string $pageId): array
{
    $st = $pdo->prepare("WITH RECURSIVE sub AS (SELECT page_id, 0 AS d FROM mcp_pages WHERE page_id = CAST(:p AS uuid)
                              UNION ALL SELECT c.page_id, sub.d + 1 FROM mcp_pages c JOIN sub ON c.parent_page_id = sub.page_id OR c.parent_database_id = sub.page_id WHERE c.archived_at IS NULL AND NOT c.is_template)
                         SELECT c.page_id::text AS id, c.parent_page_id::text AS parent, c.parent_database_id::text AS database, c.kind, c.plain_title AS title
                           FROM sub JOIN mcp_pages c ON c.page_id = sub.page_id WHERE sub.d > 0 ORDER BY c.position, c.created_at");
    $st->execute(['p' => $pageId]);
    $by = [];
    foreach ($st->fetchAll() as $p) { $by[$p['database'] !== null ? 'db:' . $p['database'] : $p['parent']][] = $p; }
    return $by;
}

/** Run an export by id, as the member who asked. Returns ['status', 'item_count', 'byte_size', 'error'?]. */
function run_export(PDO $pdo, int $exportId): array
{
    $claim = $pdo->prepare("UPDATE exports SET status = 'running' WHERE id = :i AND status = 'queued' RETURNING id, kind, format, page_id::text AS page_id, space_id, channel_id, include_subpages, params::text AS params, created_by");
    $claim->execute(['i' => $exportId]);
    $e = $claim->fetch();
    if ($e === false) { return ['status' => 'skipped']; }
    $by = (int) $e['created_by'];
    [$rel, $dir] = export_dir($exportId);
    $error = null;
    $made = null;
    as_acting_member($pdo, $by, function () use ($pdo, $e, $dir, &$made, &$error, $exportId): void {
        try {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) { throw new DomainException('The export store is not writable.'); }
            $made = export_build($pdo, $e, $dir);
        } catch (Throwable $t) {
            $error = $t instanceof DomainException ? $t->getMessage() : ($t instanceof PDOException ? db_message($t, 'The database refused part of the export.') : 'The export stopped: ' . mb_substr($t->getMessage(), 0, 160));
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (!($t instanceof DomainException)) { error_log('export ' . $exportId . ': ' . $t::class . ': ' . $t->getMessage()); }
        }
    });
    if ($error !== null || $made === null) {
        foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        $pdo->prepare("UPDATE exports SET status = 'failed', log = :l, finished_at = now() WHERE id = :i")->execute(['l' => $error ?? 'The export produced nothing.', 'i' => $exportId]);
        return ['status' => 'failed', 'error' => $error ?? 'The export produced nothing.'];
    }
    [$name, $mime, $count, $words] = $made;
    $full = $dir . '/' . $name;
    $size = (int) filesize($full);
    $pdo->prepare("UPDATE exports SET status = 'done', storage_path = :p, byte_size = :s, item_count = :n, log = :l, finished_at = now() WHERE id = :i")
        ->execute(['p' => $rel . '/' . $name, 's' => $size, 'n' => $count, 'l' => $words, 'i' => $exportId]);
    $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('export', :i, :n, :m, :s, :h, :p, :by)")
        ->execute(['i' => $exportId, 'n' => $name, 'm' => $mime, 's' => $size, 'h' => hash_file('sha256', $full), 'p' => $rel . '/' . $name, 'by' => $by]);
    return ['status' => 'done', 'item_count' => $count, 'byte_size' => $size, 'file_name' => $name];
}

/** The worker's step: exports still queued (a space, a channel, everything), oldest first; one stuck running for an hour is failed. Returns ['run', 'done', 'failed', 'interrupted']. */
function export_pass(PDO $pdo, int $limit): array
{
    $out = ['run' => 0, 'done' => 0, 'failed' => 0, 'interrupted' => 0];
    $out['interrupted'] = (int) $pdo->exec("UPDATE exports SET status = 'failed', log = 'The export was interrupted.', finished_at = now() WHERE status = 'running' AND created_at < now() - interval '1 hour'");
    $ids = $pdo->query("SELECT id FROM exports WHERE status = 'queued' ORDER BY id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $r = run_export($pdo, (int) $id);
        if ($r['status'] === 'skipped') { continue; }
        $out['run']++;
        $out[$r['status']]++;
    }
    return $out;
}

/** My exports (the admin: everyone's) newest first, with the names the list shows. */
function find_my_exports(PDO $pdo, int $memberId, bool $all): array
{
    $st = $pdo->prepare('SELECT e.export_id, e.kind, e.format, e.page_id::text AS page_id, e.space_id, e.channel_id, e.include_subpages, e.status, e.byte_size, e.item_count, e.log, e.created_by, e.created_at, e.finished_at, e.expires_at, e.downloaded_at,
                                e.available, e.params::text AS params, m.display_name AS by_name, s.name AS space_name, c.name AS channel_name, p.plain_title AS page_title
                           FROM mcp_exports e LEFT JOIN members m ON m.id = e.created_by LEFT JOIN mcp_spaces s ON s.space_id = e.space_id LEFT JOIN mcp_channels c ON c.channel_id = e.channel_id
                           LEFT JOIN mcp_pages p ON p.page_id = e.page_id
                          WHERE (:all OR e.created_by = :me) ORDER BY e.export_id DESC LIMIT 100');
    $st->bindValue('all', $all ? 't' : 'f');
    $st->bindValue('me', $memberId, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The file of an export the member may download (their own; the admin's every one), or null: [storage_path, full path, file name, mime, byte_size, kind]. */
function export_file(PDO $pdo, int $exportId, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT e.export_id, e.kind, e.byte_size, e.created_by, b.storage_path FROM mcp_exports e JOIN exports b ON b.id = e.export_id WHERE e.export_id = :i AND e.status = \'done\' AND e.available AND e.expires_at > now()');
    $st->execute(['i' => $exportId]);
    $r = $st->fetch();
    if ($r === false || $r['storage_path'] === null) { return null; }
    $root = realpath(APP_ROOT . '/storage');
    $full = $root === false ? false : realpath($root . '/' . ltrim((string) $r['storage_path'], '/'));
    if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) { return null; }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    return ['export_id' => (int) $r['export_id'], 'kind' => $r['kind'], 'full' => $full, 'file_name' => basename($full), 'mime' => EXPORT_MIME[$ext] ?? 'application/octet-stream', 'byte_size' => (int) $r['byte_size']];
}
