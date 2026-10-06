<?php
declare(strict_types=1);

/**
 * The import readers (slice 8): a file or a zip → a PLAN, the tree of what will be made (pages, databases with their rows and column types, relations, counts), and the one
 * preview the screen shows and the one run executes — so the preview's counts are the run's. Markdown goes through the one converter (app/richtext/markdown.php) and never
 * a second one: Notion's own spellings are first put into the converter's (the unsupported marker of D6) by a pre-pass, nothing more. No database is touched here.
 *   import_kind_from_file(name, path): markdown | markdown_zip | notion_zip | csv | null     preview_zip(path, kind?): the plan's counts and tree
 *   infer_property_type(values): number | checkbox | date | url | email | multi_select | select | rich_text
 */

const IMPORT_MAX_ENTRIES = 5000;
const IMPORT_MAX_UNPACKED = 524288000;                 // 500 MB of unpacked bytes
const IMPORT_MAX_READ = 20971520;                      // one file of a zip: 20 MB
const IMPORT_SELECT_LIMIT = 20;                        // a select or multi-select has at most this many distinct options
const NOTION_UNSUPPORTED = ['meeting-notes' => 'meeting_notes', 'meeting_notes' => 'meeting_notes', 'transcription' => 'transcription', 'tab' => 'tab'];

function import_kind_from_file(string $name, string $path): ?string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'md' || $ext === 'markdown') { return 'markdown'; }
    if ($ext === 'csv') { return 'csv'; }
    if ($ext !== 'zip') { return null; }
    try {
        $z = zip_open_safe($path);
        foreach (zip_entries($z) as $p => $_) {
            if (preg_match('/ [0-9a-f]{32}\.(md|csv)$/i', basename($p)) === 1) { return 'notion_zip'; }
        }
        return 'markdown_zip';
    } catch (DomainException) {
        return 'markdown_zip';                          // the run says what is wrong with it, in words
    }
}

/** Open a zip or say, in words, why not. */
function zip_open_safe(string $path): ZipArchive
{
    $z = new ZipArchive();
    if (!is_file($path) || $z->open($path, ZipArchive::RDONLY) !== true) {
        throw new DomainException('That is not a zip file this application can open.');
    }
    return $z;
}

/** The files of a zip: [normalized path => ['index', 'size']]; folders, __MACOSX and dotfiles skipped; a path that climbs out or an absurd zip refused in words. */
function zip_entries(ZipArchive $z): array
{
    if ($z->numFiles > IMPORT_MAX_ENTRIES) {
        throw new DomainException('A zip holds at most ' . IMPORT_MAX_ENTRIES . ' files.');
    }
    $out = [];
    $total = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $s = $z->statIndex($i);
        $name = str_replace('\\', '/', (string) ($s['name'] ?? ''));
        if ($name === '' || str_ends_with($name, '/')) { continue; }
        $segs = array_values(array_filter(explode('/', $name), static fn (string $x): bool => $x !== '' && $x !== '.'));
        if (in_array('..', $segs, true) || str_starts_with($name, '/')) {
            throw new DomainException('The zip holds a path that leaves its folder (' . mb_substr($name, 0, 60) . '): nothing was imported.');
        }
        if ($segs === [] || in_array('__MACOSX', $segs, true) || str_starts_with((string) end($segs), '.')) { continue; }
        $total += (int) $s['size'];
        if ($total > IMPORT_MAX_UNPACKED) {
            throw new DomainException('That zip unpacks to more than 500 MB.');
        }
        $out[implode('/', $segs)] = ['index' => $i, 'size' => (int) $s['size']];
    }
    return $out;
}

function zipfile_read(ZipArchive $z, array $entry): string
{
    if ($entry['size'] > IMPORT_MAX_READ) {
        throw new DomainException('A file inside the zip is larger than 20 MB.');
    }
    $d = $z->getFromIndex($entry['index']);
    if ($d === false) {
        throw new DomainException('A file inside the zip could not be read: the zip is damaged.');
    }
    return $d;
}

/** A Notion name "Title <32 hex>" → [title, the id as a UUID]. Anything else → [name, null]. */
function notion_split(string $name): array
{
    if (preg_match('/^(.*?)\s+([0-9a-f]{32})$/i', $name, $m)) {
        $h = strtolower($m[2]);
        return [trim($m[1]) === '' ? 'Untitled' : trim($m[1]), substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20)];
    }
    return [$name, null];
}

/** CSV text → [header[], rows[][]]; a BOM dropped, quoted newlines kept, blank lines skipped, a short row padded. */
function csv_parse(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $text);
    rewind($fp);
    $header = null;
    $rows = [];
    while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        if ($r === [null] || $r === []) { continue; }
        $r = array_map(static fn ($c): string => (string) $c, $r);
        if ($header === null) { $header = array_map('trim', $r); continue; }
        $rows[] = array_slice(array_pad($r, count($header), ''), 0, count($header));
    }
    fclose($fp);
    return [$header ?? [], $rows];
}

/** A date as Notion or a spreadsheet writes it → "YYYY-MM-DD[ HH:MM]" or null when it is not one. */
function import_normalize_date(string $v): ?string
{
    $v = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $v)) { return strtotime($v) === false ? null : str_replace('T', ' ', $v); }
    if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\.? \d{1,2}, \d{4}( \d{1,2}:\d{2} ?(AM|PM)?)?$/i', $v) && ($t = strtotime($v)) !== false) {
        return preg_match('/\d:\d{2}/', $v) ? date('Y-m-d H:i', $t) : date('Y-m-d', $t);
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $v) && ($t = strtotime($v)) !== false) { return date('Y-m-d', $t); }
    return null;
}

/** The property type a column of text values most plainly is (empty values ignored). */
function infer_property_type(array $values): string
{
    $v = array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), $values), static fn (string $x): bool => $x !== ''));
    if ($v === []) { return 'rich_text'; }
    $all = static function (callable $f) use ($v): bool { foreach ($v as $x) { if (!$f($x)) { return false; } } return true; };
    if ($all(static fn (string $x): bool => preg_match('/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/', $x) === 1)) { return 'number'; }
    if ($all(static fn (string $x): bool => in_array(strtolower($x), ['true', 'false', 'yes', 'no', 'checked', 'unchecked'], true))) { return 'checkbox'; }
    if ($all(static fn (string $x): bool => import_normalize_date($x) !== null)) { return 'date'; }
    if ($all(static fn (string $x): bool => preg_match('#^https?://\S+$#i', $x) === 1)) { return 'url'; }
    if ($all(static fn (string $x): bool => filter_var($x, FILTER_VALIDATE_EMAIL) !== false)) { return 'email'; }
    if ($all(static fn (string $x): bool => mb_strlen($x) <= 100 && !str_contains($x, "\n"))) {
        $parts = [];
        $anyComma = false;
        foreach ($v as $x) {
            if (str_contains($x, ',')) { $anyComma = true; }
            foreach (explode(',', $x) as $p) { $p = trim($p); if ($p !== '') { $parts[strtolower($p)] = true; } }
        }
        if ($anyComma && count($parts) <= IMPORT_SELECT_LIMIT) { return 'multi_select'; }
        if (!$anyComma && count(array_unique(array_map('strtolower', $v))) <= IMPORT_SELECT_LIMIT) { return 'select'; }
    }
    return 'rich_text';
}

/** The Markdown of an import as the converter takes it: Notion's four unsupported kinds and level-4+ headings marked as D6 says (the text kept after the marker). */
function import_notion_prepare(string $md): string
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $md) ?: [] as $line) {
        if (preg_match('/^\s*#{4,}\s+(.*)$/', $line, $m)) { $out[] = '<!-- unsupported block heading_4 -->'; $out[] = $m[1]; continue; }
        if (preg_match('/^\s*<([a-z_-]+)(\s[^>]*)?>\s*$/i', $line, $m) && isset(NOTION_UNSUPPORTED[strtolower($m[1])])) { $out[] = '<!-- unsupported block ' . NOTION_UNSUPPORTED[strtolower($m[1])] . ' -->'; continue; }
        if (preg_match('/^\s*<\/([a-z_-]+)>\s*$/i', $line, $m) && isset(NOTION_UNSUPPORTED[strtolower($m[1])])) { continue; }
        $out[] = $line;
    }
    return implode("\n", $out);
}

function import_count_unsupported(string $md): int
{
    return preg_match_all('/^<!-- unsupported block\b/m', $md);
}

/** [title, markdown] — the first non-empty line "# X" is the page's title when $useH1, or is dropped when it repeats $fallback. */
function import_title_of(string $md, string $fallback, bool $useH1): array
{
    $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
    foreach ($lines as $i => $l) {
        if (trim($l) === '') { continue; }
        if (preg_match('/^#\s+(.+?)\s*#*\s*$/', $l, $m)) {
            $t = trim($m[1]);
            if ($useH1 || strcasecmp($t, $fallback) === 0) {
                array_splice($lines, $i, 1);
                return [$useH1 ? mb_substr($t, 0, 300) : $fallback, ltrim(implode("\n", $lines), "\n")];
            }
        }
        break;
    }
    return [$fallback, $md];
}

/** The path a relative link points at inside the zip, or null (an absolute URL, an anchor or a file the zip does not hold). */
function zip_resolve(array $entries, string $mdPath, string $url): ?string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/|\#)#i', $url)) { return null; }
    $url = rawurldecode(explode('#', explode('?', $url)[0])[0]);
    $segs = explode('/', dirname($mdPath) === '.' ? '' : dirname($mdPath));
    foreach (explode('/', $url) as $s) {
        if ($s === '' || $s === '.') { continue; }
        if ($s === '..') { array_pop($segs); continue; }
        $segs[] = $s;
    }
    $p = implode('/', array_filter($segs, static fn (string $x): bool => $x !== ''));
    return isset($entries[$p]) ? $p : null;
}

// ---- the plan ------------------------------------------------------------------------------------------------------------------------

/** Make a zip's directory tree: ['files' => [name => path], 'dirs' => [name => tree]]. */
function zip_tree(array $entries): array
{
    $root = ['files' => [], 'dirs' => []];
    foreach ($entries as $path => $_) {
        $segs = explode('/', $path);
        $name = array_pop($segs);
        $node = &$root;
        foreach ($segs as $s) {
            $node['dirs'][$s] ??= ['files' => [], 'dirs' => []];
            $node = &$node['dirs'][$s];
        }
        $node['files'][$name] = $path;
        unset($node);
    }
    return $root;
}

function import_cmp(string $a, string $b): int { return strnatcasecmp($a, $b); }

/** A database node from a CSV: columns with their inferred types (the title column first), rows. */
function plan_database(ZipArchive $z, array $entries, string $csvPath, string $title, ?string $id, bool $notion): array
{
    [$header, $rows] = csv_parse(zipfile_read($z, $entries[$csvPath]));
    if ($header === []) {
        throw new DomainException('The CSV "' . basename($csvPath) . '" has no header row.');
    }
    $titleCol = 0;
    if ($notion) { foreach ($header as $i => $h) { if (strcasecmp($h, 'Name') === 0) { $titleCol = $i; break; } } }
    $cols = [];
    foreach ($header as $i => $h) {
        $name = $h === '' ? 'Column ' . ($i + 1) : $h;
        $col = ['name' => $name, 'index' => $i, 'type' => $i === $titleCol ? 'title' : infer_property_type(array_column($rows, $i))];
        if (in_array($col['type'], ['select', 'multi_select'], true)) {
            $opts = [];
            foreach (array_column($rows, $i) as $v) { foreach ($col['type'] === 'multi_select' ? explode(',', (string) $v) : [(string) $v] as $p) { $p = trim($p); if ($p !== '') { $opts[strtolower($p)] = $p; } } }
            $col['options'] = array_values($opts);
        }
        $cols[] = $col;
    }
    return ['type' => 'database', 'title' => $title, 'id' => $id, 'csv' => $csvPath, 'columns' => $cols, 'title_col' => $titleCol, 'rows' => $rows, 'bodies' => [], 'relations' => [], 'ref' => 0, 'notion' => $notion];
}

/** Does a folder (or anything under it) hold a Markdown or CSV file? A folder of images alone is not a page. */
function dir_has_content(array $dir): bool
{
    foreach ($dir['files'] as $name => $_) { if (preg_match('/\.(md|markdown|csv)$/i', $name)) { return true; } }
    foreach ($dir['dirs'] as $d) { if (dir_has_content($d)) { return true; } }
    return false;
}

/** The directory's nodes: a page per .md (its folder of the same name its subtree), a container page per other folder, a database per .csv (under the page of the same name when there is one). */
function plan_dir(ZipArchive $z, array $entries, array $dir, bool $notion, array &$st): array
{
    $mds = []; $csvs = [];
    foreach ($dir['files'] as $name => $path) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        if ($ext === 'md' || $ext === 'markdown') { $mds[$stem] = $path; } elseif ($ext === 'csv') { $csvs[$stem] = $path; }
    }
    $names = array_unique(array_merge(array_keys($mds), array_keys($csvs), array_keys(array_filter($dir['dirs'], 'dir_has_content'))));
    usort($names, 'import_cmp');
    $nodes = [];
    foreach ($names as $s) {
        $md = $mds[$s] ?? null; $csv = $csvs[$s] ?? null; $sub = $dir['dirs'][$s] ?? null;
        [$title, $id] = $notion ? notion_split($s) : [$s, null];
        $db = null;
        if ($csv !== null) {
            $db = plan_database($z, $entries, $csv, $title, $id, $notion);
            $st['databases']++; $st['rows'] += count($db['rows']);
            if ($notion && $md === null) { $id = null; }
        }
        if ($csv !== null && $md === null) {
            $rest = $sub ?? ['files' => [], 'dirs' => []];
            if ($notion && $sub !== null) {                                    // the rows' own pages: a md named like a row's title is that row's body
                $byTitle = [];
                foreach ($db['rows'] as $ri => $r) { $byTitle[strtolower(trim($r[$db['title_col']]))] ??= $ri; }
                foreach ($sub['files'] as $fname => $fpath) {
                    if (!preg_match('/\.(md|markdown)$/i', $fname)) { continue; }
                    [$ft] = notion_split(pathinfo($fname, PATHINFO_FILENAME));
                    if (isset($byTitle[strtolower($ft)])) { $db['bodies'][$byTitle[strtolower($ft)]] = $fpath; unset($rest['files'][$fname]); }
                }
            }
            $nodes[] = $db;
            foreach (plan_dir($z, $entries, $rest, $notion, $st) as $n) { $nodes[] = $n; }
            continue;
        }
        $kids = $sub !== null ? plan_dir($z, $entries, $sub, $notion, $st) : [];
        if ($db !== null) { array_unshift($kids, $db); }
        $st['pages']++;
        if ($md !== null) { $st['unsupported'] += import_count_unsupported($notion ? import_notion_prepare(zipfile_read($z, $entries[$md])) : zipfile_read($z, $entries[$md])); $st['files']++; }
        $nodes[] = ['type' => 'page', 'title' => mb_substr($title, 0, 300), 'id' => $id, 'md' => $md, 'children' => $kids];
    }
    return $nodes;
}

/** Name the relation columns: a column called like another database of the zip whose every value is a title of that database's rows. Fills each database's `relations`. Returns the databases by ref. */
function plan_relations(array &$nodes): void
{
    $dbs = [];
    $walk = static function (array &$list) use (&$walk, &$dbs): void {
        foreach ($list as &$n) {
            if ($n['type'] === 'database') { $n['ref'] = count($dbs) + 1; $dbs[$n['ref']] = &$n; }
            elseif (!empty($n['children'])) { $walk($n['children']); }
        }
        unset($n);
    };
    $walk($nodes);
    $byTitle = [];
    foreach ($dbs as $ref => $d) { $byTitle[strtolower($d['title'])] = $ref; }
    foreach ($dbs as $ref => &$d) {
        foreach ($d['columns'] as &$c) {
            if ($c['type'] === 'title' || !isset($byTitle[strtolower($c['name'])]) || $byTitle[strtolower($c['name'])] === $ref) { continue; }
            $t = $dbs[$byTitle[strtolower($c['name'])]];
            $titles = [];
            foreach ($t['rows'] as $r) { $titles[strtolower(trim($r[$t['title_col']]))] = true; }
            $any = false; $every = true;
            foreach (array_column($d['rows'], $c['index']) as $v) {
                foreach (import_relation_values((string) $v, $d['notion']) as $p) { $any = true; if (!isset($titles[strtolower($p)])) { $every = false; } }
            }
            if ($any && $every) { $c['type'] = 'relation'; $c['target'] = $byTitle[strtolower($c['name'])]; unset($c['options']); }
        }
        unset($c);
    }
    unset($d);
}

/** A relation cell's titles: comma-separated, a Notion "(https://www.notion.so/…)" suffix dropped. */
function import_relation_values(string $v, bool $notion): array
{
    $out = [];
    foreach (explode(',', $v) as $p) {
        $p = trim($notion ? (string) preg_replace('/\s*\(https?:\/\/[^)]*\)\s*$/', '', $p) : $p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

/** The plan of a zip: ['nodes' => [...], 'stats' => [pages (databases included), databases, rows, files, unsupported]]. */
function plan_import(string $zipPath, string $kind): array
{
    $z = zip_open_safe($zipPath);
    $entries = zip_entries($z);
    $notion = $kind === 'notion_zip';
    $tree = zip_tree($entries);
    if ($notion && $tree['files'] === [] && count($tree['dirs']) === 1) {                 // Notion wraps its export in one folder
        $tree = array_values($tree['dirs'])[0];
    }
    $st = ['pages' => 0, 'databases' => 0, 'rows' => 0, 'files' => 0, 'unsupported' => 0];
    $nodes = plan_dir($z, $entries, $tree, $notion, $st);
    plan_relations($nodes);
    if ($st['pages'] + $st['databases'] === 0) {
        throw new DomainException('The zip holds no Markdown or CSV file to import.');
    }
    $st['pages_total'] = $st['pages'] + $st['databases'];
    return ['nodes' => $nodes, 'stats' => $st, 'entries' => $entries];
}

/** What the screen shows before the import: the counts and the tree (titles and kinds only). A bad zip is a DomainException with its sentence. */
function preview_zip(string $path, ?string $kind = null): array
{
    $kind ??= import_kind_from_file('x.zip', $path) ?? 'markdown_zip';
    $plan = plan_import($path, $kind);
    $tree = static function (array $nodes) use (&$tree): array {
        $o = [];
        foreach ($nodes as $n) {
            $o[] = ['type' => $n['type'], 'title' => $n['title'], 'rows' => $n['type'] === 'database' ? count($n['rows']) : null, 'columns' => $n['type'] === 'database' ? count($n['columns']) : null, 'children' => $n['type'] === 'page' ? $tree($n['children']) : []];
        }
        return $o;
    };
    return ['kind' => $kind] + $plan['stats'] + ['tree' => $tree($plan['nodes'])];
}
