<?php
declare(strict_types=1);

/**
 * The export writers (slice 8): a page, a database, a space, a channel, everything → text and zips. EVERY READER IS THE DATABASE'S, AS THE CALLER: sp_page_markdown(), sp_page_tree(),
 * sp_database_rows(), sp_channel_history(), sp_thread() and the mcp_* views answer for whoever app.member_id names (the person who asked, set by run_export()), so nothing a person may not
 * see is ever written. A writer returns bytes or fills a ZipArchive; images and files a page holds are copied into files/ beside the pages and the links made relative.
 */
require_once dirname(__DIR__, 2) . '/richtext/render.php';
require_once dirname(__DIR__) . '/blocks/queries.php';
require_once dirname(__DIR__) . '/databases/queries.php';
require_once dirname(__DIR__) . '/databases/present.php';
require_once dirname(__DIR__) . '/files/queries.php';

/** A name safe as a file or folder in a zip on any system. */
function export_safe_name(string $s, int $max = 80): string
{
    $s = trim((string) preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', '-', $s));
    $s = trim($s, " .-");
    return $s === '' ? 'Untitled' : mb_substr($s, 0, $max);
}

/** A name not yet used in $used (keyed by the full path, lower-cased): "Name", then "Name (2)". */
function export_unique(array &$used, string $dir, string $name, string $ext): string
{
    $n = $name;
    $i = 2;
    while (isset($used[strtolower($dir . '/' . $n . $ext)])) { $n = $name . ' (' . $i++ . ')'; }
    $used[strtolower($dir . '/' . $n . $ext)] = true;
    $used[strtolower($dir . '/' . $n)] = true;
    return $n;
}

function export_business(PDO $pdo): string
{
    return trim((string) one_value($pdo, 'SELECT business_name FROM sp_settings WHERE id = 1')) ?: app_name();
}

/** A page as a standalone HTML file with the design system's print styles inline. $body is the renderer's HTML. */
function export_html_doc(string $title, string $body, string $business): string
{
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>" . e($title) . "</title>\n"
        . "<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#283c50;line-height:1.55;max-width:820px;margin:0 auto;padding:24px 16px}"
        . "h1{font-size:28px;margin:0 0 16px}h2{font-size:22px}h3{font-size:18px}img{max-width:100%;height:auto}pre,code{background:#f1f3f7;border-radius:4px}pre{padding:12px;overflow:auto}"
        . "blockquote{border-left:3px solid #c9d0dc;margin:12px 0;padding:2px 12px;color:#556}table{border-collapse:collapse}td,th{border:1px solid #d6dbe4;padding:6px 10px}.exp-foot{margin-top:40px;font-size:12px;color:#748a9e;border-top:1px solid #e5e7eb;padding-top:8px}"
        . "@media print{body{max-width:none;padding:0}a{color:inherit;text-decoration:none}.exp-foot{display:none}}</style></head><body>\n<h1>" . e($title) . "</h1>\n" . $body
        . "\n<div class=\"exp-foot\">" . e($business) . " &middot; exported from " . e(app_name()) . "</div></body></html>\n";
}

/** The attachments named by /files/N links in $text become relative paths under files/ (from a file $depth folders deep) and are noted in $files [id => ['name', 'path']]. */
function export_rewrite_files(PDO $pdo, string $text, int $depth, array &$files): string
{
    return (string) preg_replace_callback('#/files/(\d+)(/thumb)?#', static function (array $m) use ($pdo, $depth, &$files): string {
        $id = (int) $m[1];
        if (!isset($files[$id])) {
            $a = attachment_for($pdo, $id);
            if ($a === null || attachment_path($a, false) === null) { return $m[0]; }
            $files[$id] = ['name' => $id . '-' . export_safe_name((string) $a['filename'], 100), 'full' => attachment_path($a, false)];
        }
        return str_repeat('../', $depth) . 'files/' . $files[$id]['name'];
    }, $text);
}

/** The pages' text: Markdown from sp_page_markdown() (the title included), or the renderer's HTML. Files go into $files. */
function export_page_text(PDO $pdo, string $pageId, string $fmt, int $depth, array &$files, string $title): string
{
    if ($fmt === 'html') {
        $tree = page_tree($pdo, $pageId);
        $html = render_blocks($tree, ['file_url' => static fn (int $id): string => '/files/' . $id, 'page_url' => static fn (string $u): ?string => null, 'embed_hosts' => []]);
        return export_html_doc($title, export_rewrite_files($pdo, $html, $depth, $files), export_business($pdo));
    }
    $md = (string) one_value($pdo, 'SELECT sp_page_markdown(CAST(:p AS uuid))', ['p' => $pageId]);
    return export_rewrite_files($pdo, $md, $depth, $files);
}

function export_csv_string(array $header, array $rows): string
{
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $header, ',', '"', '');
    foreach ($rows as $r) { fputcsv($fp, $r, ',', '"', ''); }
    rewind($fp);
    $s = (string) stream_get_contents($fp);
    fclose($fp);
    return $s;
}

/** Every row of a database (or of a view: its filter, its sort, its visible properties), as the caller sees them. Returns [schema shown, rows, total]. */
function export_database_rows(PDO $pdo, string $dbUuid, ?string $viewUuid): array
{
    $d = find_database($pdo, $dbUuid) ?? throw new DomainException('That database is not here.');
    $view = $viewUuid !== null ? (find_view($pdo, $viewUuid) ?? throw new DomainException('That view is not here.')) : null;
    if ($view !== null && $view['database_id'] !== $dbUuid) { throw new DomainException('That view belongs to another database.'); }
    $titleKey = (string) ($d['title_property_key'] ?? 'title');
    $schema = visible_schema(schema_ordered($d['properties']), $view['visible_properties'] ?? null, $titleKey);
    $rows = [];
    $off = 0;
    do {
        $page = database_rows($pdo, $dbUuid, $viewUuid, null, null, 500, $off);
        foreach ($page as $r) { $rows[] = $r; }
        $off += 500;
        $total = $page === [] ? 0 : $page[0]['total'];
    } while ($off < $total);
    return [$schema, $rows, $d];
}

/** A database as CSV: the title first, every property as the text a cell shows (a relation by its rows' titles, a rollup as its display, people by name). Returns [csv, row count]. */
function export_database_csv(PDO $pdo, string $dbUuid, ?string $viewUuid): array
{
    [$schema, $rows] = export_database_rows($pdo, $dbUuid, $viewUuid);
    $header = array_map(static fn (array $d): string => (string) ($d['name'] ?? ''), array_values($schema));
    $out = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($schema as $k => $def) { $line[] = display_value((string) $def['type'], $r['properties'][$k] ?? null); }
        $out[] = $line;
    }
    return [export_csv_string($header, $out), count($rows)];
}

/** A database as JSON: its schema and its resolved rows. Returns [json, row count]. */
function export_database_json(PDO $pdo, string $dbUuid, ?string $viewUuid): array
{
    [$schema, $rows, $d] = export_database_rows($pdo, $dbUuid, $viewUuid);
    $out = ['database' => ['database_id' => $dbUuid, 'title' => $d['plain_title'] ?? $d['title'] ?? '', 'properties' => present_schema($schema)], 'rows' => array_map(static fn (array $r): array => present_row($r, $schema), $rows)];
    return [json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($rows)];
}

// ---- the page tree of a space ----------------------------------------------------------------------------------------------------------

/** The pages of a space the caller sees, grouped by parent: ['root' => [...], '<page uuid>' => [...], 'db:<uuid>' => [rows]]. A page whose parent the caller cannot see is a root. */
function export_page_index(PDO $pdo, int $spaceId): array
{
    $st = $pdo->prepare("SELECT page_id::text AS id, parent_page_id::text AS parent, parent_database_id::text AS database, kind, plain_title AS title FROM mcp_pages
                          WHERE space_id = :s AND archived_at IS NULL AND NOT is_template ORDER BY position, created_at");
    $st->execute(['s' => $spaceId]);
    $all = $st->fetchAll();
    $ids = array_column($all, 'id');
    $by = [];
    foreach ($all as $p) {
        $key = $p['database'] !== null ? 'db:' . $p['database'] : ($p['parent'] !== null && in_array($p['parent'], $ids, true) ? $p['parent'] : 'root');
        $by[$key][] = $p;
    }
    return $by;
}

/**
 * One node and what hangs under it, into the zip: a page → "Name.md" (or .html) and its children in "Name/"; a database → "Name.csv" and its rows as pages in "Name/". $dir has no trailing slash ('' = the
 * zip's root). $cx: pdo, fmt, files (by ref), count (by ref), used (by ref), index (the lines of index.md: [depth, title, path]).
 */
function export_write_node(array &$cx, ZipArchive $z, array $p, string $dir, int $depth, array $by): void
{
    $pdo = $cx['pdo'];
    $ext = $cx['fmt'] === 'html' ? '.html' : '.md';
    $name = export_unique($cx['used'], $dir, export_safe_name($p['title']), $p['kind'] === 'database' ? '.csv' : $ext);
    $path = ($dir === '' ? '' : $dir . '/') . $name;
    if ($p['kind'] === 'database') {
        [$csv, $n] = export_database_csv($pdo, $p['id'], null);
        $z->addFromString($path . '.csv', $csv);
        $cx['count']++;
        $cx['index'][] = [$depth, $p['title'] . ' (database, ' . $n . ' rows)', $path . '.csv'];
        foreach ($by['db:' . $p['id']] ?? [] as $row) { export_write_node($cx, $z, $row, $path, $depth + 1, $by); }
        return;
    }
    $z->addFromString($path . $ext, export_page_text($pdo, $p['id'], $cx['fmt'], substr_count($path, '/'), $cx['files'], $p['title']));
    $cx['count']++;
    $cx['index'][] = [$depth, $p['title'], $path . $ext];
    foreach ($by[$p['id']] ?? [] as $child) { export_write_node($cx, $z, $child, $path, $depth + 1, $by); }
}

/** The files the pages held, copied into files/ at the zip's root (or under $prefix). Returns how many. */
function export_add_files(ZipArchive $z, array $files, string $prefix = ''): int
{
    $n = 0;
    foreach ($files as $f) {
        if (is_file($f['full'])) { $z->addFile($f['full'], $prefix . 'files/' . $f['name']); $n++; }
    }
    return $n;
}

/** index.md: the sidebar as a nested list of links. */
function export_index_md(string $title, array $lines): string
{
    $out = '# ' . $title . "\n\n";
    foreach ($lines as [$depth, $t, $path]) {
        $out .= str_repeat('  ', $depth) . '- [' . str_replace(['[', ']'], ['(', ')'], $t) . '](' . str_replace(' ', '%20', $path) . ")\n";
    }
    return $out;
}

/** A space into $z under $prefix (a folder with a trailing slash, or ''): every page the caller may see as a folder tree, every database as CSV + its rows, index.md. Returns the number of files of content. */
function export_space_into(PDO $pdo, ZipArchive $z, int $spaceId, string $spaceName, string $prefix, string $fmt, array &$files): int
{
    $by = export_page_index($pdo, $spaceId);
    $cx = ['pdo' => $pdo, 'fmt' => $fmt, 'files' => &$files, 'count' => 0, 'used' => [], 'index' => []];
    // the prefix is a real folder: depth is counted from the zip root for the relative file links
    $dir = rtrim($prefix, '/');
    foreach ($by['root'] ?? [] as $p) { export_write_node($cx, $z, $p, $dir, 0, $by); }
    $z->addFromString($prefix . 'index.md', export_index_md($spaceName, $cx['index']));
    return $cx['count'];
}

// ---- channels ----------------------------------------------------------------------------------------------------------------------------

/** A channel's messages as the caller sees them, oldest first, replies nested under their thread; $from/$to dates (inclusive) bound the top-level messages. Returns [channel facts, messages[]]. */
function export_channel_messages(PDO $pdo, int $channelId, ?string $from, ?string $to): array
{
    $ch = $pdo->prepare('SELECT c.channel_id, c.name, c.topic, c.kind, c.space_id, s.name AS space_name FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id WHERE c.channel_id = :c');
    $ch->execute(['c' => $channelId]);
    $c = $ch->fetch() ?: throw new DomainException('That channel is not here.');
    $rows = [];
    $before = null;
    do {
        $st = $pdo->prepare('SELECT m::text FROM sp_channel_history(:c, :b, NULL, 200) m');
        $st->execute(['c' => $channelId, 'b' => $before]);
        $page = array_map(static fn (string $j): array => json_decode($j, true), $st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($page as $m) { $rows[] = $m; }
        $before = $page === [] ? null : (int) $page[0]['message_id'];
    } while (count($page) === 200 && $before !== null);
    usort($rows, static fn (array $a, array $b): int => $a['message_id'] <=> $b['message_id']);
    $out = [];
    foreach ($rows as $m) {
        $day = substr((string) $m['created_at'], 0, 10);
        if (($from !== null && $day < $from) || ($to !== null && $day > $to)) { continue; }
        if ($m['deleted_at'] !== null) { continue; }
        $m['thread'] = [];
        if ((int) $m['reply_count'] > 0 && $m['thread_root_id'] === null) {
            $st = $pdo->prepare('SELECT m::text FROM sp_thread(:r) m');
            $st->execute(['r' => (int) $m['message_id']]);
            foreach (array_map(static fn (string $j): array => json_decode($j, true), $st->fetchAll(PDO::FETCH_COLUMN)) as $r) {
                if ((int) $r['message_id'] !== (int) $m['message_id'] && $r['deleted_at'] === null) { $m['thread'][] = $r; }
            }
        }
        $out[] = $m;
    }
    return [$c, $out];
}

function export_message_json(array $m): array
{
    return ['message_id' => (int) $m['message_id'], 'author' => $m['author_name'], 'at' => $m['created_at'], 'edited_at' => $m['edited_at'], 'text' => $m['markdown'],
            'reactions' => array_map(static fn (array $r): array => ['emoji' => $r['emoji'], 'count' => (int) $r['count']], $m['reactions'] ?? []),
            'attachments' => array_map(static fn (array $a): string => (string) $a['filename'], $m['attachments'] ?? []),
            'thread' => array_map('export_message_json', $m['thread'] ?? [])];
}

/** A channel as JSON, Markdown (day headings, threads indented) or CSV. Returns [bytes, message count]. */
function export_channel_text(PDO $pdo, int $channelId, string $fmt, ?string $from, ?string $to): array
{
    [$c, $msgs] = export_channel_messages($pdo, $channelId, $from, $to);
    $n = 0;
    foreach ($msgs as $m) { $n += 1 + count($m['thread']); }
    $period = ($from ?? 'the beginning') . ' to ' . ($to ?? 'now');
    if ($fmt === 'json') {
        return [json_encode(['channel' => ['name' => $c['name'], 'topic' => $c['topic'], 'space' => $c['space_name']], 'from' => $from, 'to' => $to, 'messages' => array_map('export_message_json', $msgs)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $n];
    }
    if ($fmt === 'csv') {
        $rows = [];
        foreach ($msgs as $m) {
            foreach (array_merge([$m], $m['thread']) as $x) {
                $rows[] = [$x['message_id'], $x['created_at'], $x['author_name'], $x['thread_root_id'] ?? '', $x['markdown'],
                           implode(' ', array_map(static fn (array $r): string => $r['emoji'] . '×' . $r['count'], $x['reactions'] ?? [])), implode('; ', array_map(static fn (array $a): string => (string) $a['filename'], $x['attachments'] ?? []))];
            }
        }
        return [export_csv_string(['message_id', 'at', 'author', 'thread_of', 'text', 'reactions', 'attachments'], $rows), $n];
    }
    $md = '# #' . $c['name'] . ($c['space_name'] !== null ? ' (' . $c['space_name'] . ')' : '') . "\n\n" . ($c['topic'] ? '_' . $c['topic'] . "_\n\n" : '') . 'Messages from ' . $period . "\n";
    $day = '';
    foreach ($msgs as $m) {
        $d = substr((string) $m['created_at'], 0, 10);
        if ($d !== $day) { $md .= "\n## " . $d . "\n\n"; $day = $d; }
        $md .= '**' . $m['author_name'] . '** ' . substr((string) $m['created_at'], 11, 5) . "\n" . $m['markdown'] . "\n";
        if (!empty($m['reactions'])) { $md .= "\n" . implode(' ', array_map(static fn (array $r): string => $r['emoji'] . ' ' . $r['count'], $m['reactions'])) . "\n"; }
        if (!empty($m['attachments'])) { $md .= "\nFiles: " . implode(', ', array_map(static fn (array $a): string => (string) $a['filename'], $m['attachments'])) . "\n"; }
        foreach ($m['thread'] as $r) { $md .= "\n> **" . $r['author_name'] . '** ' . substr((string) $r['created_at'], 11, 5) . "\n> " . str_replace("\n", "\n> ", (string) $r['markdown']) . "\n"; }
        $md .= "\n";
    }
    return [$md, $n];
}
