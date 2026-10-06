<?php
declare(strict_types=1);

/**
 * Running an import (slice 8). start_import() files the upload (a `record_type = 'import'` attachment, the file under storage/attachments/import/) and the `imports` row (queued);
 * run_import() executes the PLAN of readers.php — a page, a tree of pages, databases from CSVs — acting as the member who asked (the request's own session, or the worker setting
 * app.member_id for the pass), one transaction per page or database with app.sp_bulk on (the search index is caught up after), the one converter for every body, the images and
 * files of a zip stored as attachments and linked as blocks. A page the database refuses fails the import with its sentence; what was made stays (a person trashes it).
 * A request runs a file under 2 MB at once; anything larger waits for the worker's `imports` step (import_pass()), and the screen polls the row.
 */
require_once dirname(__DIR__, 2) . '/richtext/markdown.php';
require_once dirname(__DIR__) . '/blocks/queries.php';
require_once dirname(__DIR__) . '/blocks/write.php';
require_once dirname(__DIR__) . '/pages/queries.php';
require_once dirname(__DIR__) . '/databases/queries.php';
require_once dirname(__DIR__) . '/databases/filters.php';
require_once dirname(__DIR__) . '/databases/write.php';
require_once dirname(__DIR__) . '/files/store.php';
require_once dirname(__DIR__) . '/files/queries.php';
require_once __DIR__ . '/readers.php';

const IMPORT_INLINE_BYTES = 2097152;                   // a file under 2 MB runs in the request
const IMPORT_LOG_PROBLEMS = 200;
const IMPORT_LOG_LINES = 2000;
const IMPORT_PREVIEW_DIR = '/storage/imports/preview';

/**
 * File an upload: $fields = [kind ('' = from the file), space, parent, database], $file = the $_FILES entry (or any array with name, tmp_name, size, error). Returns the import's id.
 * The destination is the caller's to have gated; a refused file type or size is a DomainException in words.
 */
function start_import(PDO $pdo, array $fields, array $file, int $by): int
{
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'import')));
    $kind = (string) ($fields['kind'] ?? '');
    if ($kind === '') {
        $kind = import_kind_from_file($name, (string) ($file['tmp_name'] ?? '')) ?? throw new DomainException('Send a .md, .zip or .csv file.');
    }
    if (!in_array($kind, ['markdown', 'markdown_zip', 'notion_zip', 'csv'], true)) {
        throw new DomainException('The kind is markdown, markdown_zip, notion_zip or csv.');
    }
    $isZip = str_ends_with($kind, '_zip');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (($isZip && $ext !== 'zip') || ($kind === 'csv' && $ext !== 'csv') || ($kind === 'markdown' && !in_array($ext, ['md', 'markdown'], true))) {
        throw new DomainException('A ' . str_replace('_', ' ', $kind) . ' import takes a ' . ($isZip ? '.zip' : ($kind === 'csv' ? '.csv' : '.md')) . ' file.');
    }
    if ($kind === 'csv' && ($fields['database'] ?? null) === null) {
        throw new DomainException('Say which database the CSV goes into.');
    }
    if ($kind !== 'csv' && ($fields['space'] ?? null) === null && ($fields['parent'] ?? null) === null) {
        throw new DomainException('Say where the pages go: a space or a page.');
    }
    $st = $pdo->prepare('INSERT INTO imports (kind, file_name, target_space_id, target_page_id, target_database_id, created_by) VALUES (:k, :n, :s, CAST(:p AS uuid), CAST(:d AS uuid), :by) RETURNING id');
    $st->execute(['k' => $kind, 'n' => mb_substr($name, 0, 200), 's' => $fields['space'] ?? null, 'p' => $fields['parent'] ?? null, 'd' => $fields['database'] ?? null, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    store_attachment($pdo, $file, 'import', $id, $by);
    return $id;
}

/** The stored file of an import, as a full path, or null. */
function import_file_path(PDO $pdo, int $importId): ?string
{
    $p = one_value($pdo, "SELECT storage_path FROM attachments WHERE record_type = 'import' AND record_id = :i ORDER BY id DESC LIMIT 1", ['i' => $importId]);
    return $p === null ? null : APP_ROOT . '/storage/' . $p;
}

/** Run as $by for the duration of $fn (the worker has no session): app.member_id is set and put back. */
function as_acting_member(PDO $pdo, int $by, callable $fn): mixed
{
    $was = (string) one_value($pdo, "SELECT COALESCE(current_setting('app.member_id', true), '')");
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) $by]);
    try {
        return $fn();
    } finally {
        $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => $was]);
    }
}

function import_note(array &$cx, string $line, bool $problem = false): void
{
    if ($problem) {
        $cx['problems']++;
        if ($cx['problems'] <= IMPORT_LOG_PROBLEMS) { $cx['log'][] = '! ' . $line; } elseif ($cx['problems'] === IMPORT_LOG_PROBLEMS + 1) { $cx['log'][] = '! more problems follow; only the first ' . IMPORT_LOG_PROBLEMS . ' are kept.'; }
    } elseif (count($cx['log']) < IMPORT_LOG_LINES) {
        $cx['log'][] = $line;
    }
}

/** A tree of converter nodes → blocks, in the order given; a relative image or file of the zip is stored and linked. Returns the number of blocks made. */
function import_insert_tree(array &$cx, string $pageId, array $nodes, ?string $parent, ?string $mdPath): int
{
    $pdo = $cx['pdo'];
    $prev = null;
    $n = 0;
    foreach ($nodes as $node) {
        $type = (string) $node['type'];
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];
        $rel = null;
        if ($mdPath !== null && $cx['z'] !== null && in_array($type, ['image', 'bookmark'], true) && isset($content['url'])) {
            $rel = zip_resolve($cx['entries'], $mdPath, (string) $content['url']);
            if ($rel !== null) { unset($content['url'], $content['external']); }
        }
        $id = insert_block($pdo, $pageId, $type, $content, $parent, $prev, false);
        $n++;
        if ($type === 'unsupported') { $cx['made']['unsupported']++; }
        if ($rel !== null) {
            try {
                $tmp = tempnam(sys_get_temp_dir(), 'spimp');
                file_put_contents($tmp, zipfile_read($cx['z'], $cx['entries'][$rel]));
                $a = store_attachment($pdo, ['name' => basename($rel), 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 'block', $id, $cx['by']);
                $b = find_block($pdo, $id);
                $content['attachment_id'] = $a['id'];
                $content['url'] = '/files/' . $a['id'];
                $content['name'] = $a['filename'];
                if ($a['width'] !== null) { $content['width'] = $a['width']; $content['height'] = $a['height']; }
                $isImage = str_starts_with($a['mime_type'], 'image/');
                update_block($pdo, $id, (int) $b['version'], $content, $isImage ? 'image' : 'file');
                $cx['made']['files']++;
            } catch (DomainException $e) {
                import_note($cx, $rel . ': ' . $e->getMessage() . ' The block stays without its file.', true);
            } finally {
                if (isset($tmp) && is_file($tmp)) { @unlink($tmp); }
            }
        }
        if (!empty($node['children'])) { $n += import_insert_tree($cx, $pageId, $node['children'], $id, $mdPath); }
        $prev = $id;
    }
    return $n;
}

/** One transaction with the bulk flag on: the search index is caught up after, once. */
function import_tx(array &$cx, callable $fn): mixed
{
    $pdo = $cx['pdo'];
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT set_config('app.sp_bulk', '1', true)");
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** A Notion id when free, else null (the database numbers it). */
function import_free_id(PDO $pdo, ?string $id): ?string
{
    return $id !== null && !(bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM pages WHERE id = CAST(:i AS uuid))', ['i' => $id]) ? $id : null;
}

/** A page: its body from the Markdown, its children after it. */
function import_exec_page(array &$cx, array $node, ?string $parent): void
{
    $pdo = $cx['pdo'];
    $md = '';
    if ($node['md'] !== null) {
        $md = zipfile_read($cx['z'], $cx['entries'][$node['md']]);
        if ($cx['notion']) { $md = import_notion_prepare($md); }
        [, $md] = import_title_of($md, (string) $node['title'], false);
    }
    $id = import_tx($cx, function () use (&$cx, $pdo, $node, $parent, $md): string {
        $st = $pdo->prepare("SELECT sp_page_create(:s, CAST(:p AS uuid), sp_rich_text(:t), 'page', NULL, NULL, CAST(:id AS uuid))::text");
        $st->execute(['s' => $cx['space'], 'p' => $parent, 't' => $node['title'], 'id' => import_free_id($pdo, $node['id'])]);
        $pid = (string) $st->fetchColumn();
        $blocks = 0;
        if (trim($md) !== '') {
            $blocks = import_insert_tree($cx, $pid, markdown_to_blocks($md, $cx['mdctx']), null, $node['md']);
            $pdo->prepare("SELECT sp_version_save(CAST(:p AS uuid), 'import')")->execute(['p' => $pid]);
        }
        $cx['made']['pages']++;
        $cx['made']['blocks'] += $blocks;
        import_note($cx, ($node['md'] ?? '(folder)') . ' → page "' . mb_substr((string) $node['title'], 0, 80) . '" (' . $blocks . ' blocks)');
        return $pid;
    });
    foreach ($node['children'] as $child) { import_exec_node($cx, $child, $id); }
}

/** One value of a CSV cell in the shape coerce_row_values() takes. */
function import_cell(array $col, string $v): mixed
{
    $v = trim($v);
    return match ($col['type']) {
        'date' => $v === '' ? null : (import_normalize_date($v) ?? $v),
        'multi_select' => array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $x): bool => $x !== '')),
        'checkbox' => $v === '' ? false : (in_array(strtolower($v), ['true', 'yes', 'checked'], true) ? true : (in_array(strtolower($v), ['false', 'no', 'unchecked'], true) ? false : $v)),
        default => $v,
    };
}

/**
 * Rows into a database from a header, the typed columns and the rows. $cols: [{name, index, type, options?}] (the plan's). A column that is not in the schema is made (type inferred);
 * a select option that is missing is added; a value the property will not take is a problem in the log and the rest of the row stands. Returns [rows made, row ids by index].
 */
function import_rows(array &$cx, string $dbUuid, array $cols, array $rows, int $titleCol, array $bodies = [], ?string $zipForBodies = null): array
{
    $pdo = $cx['pdo'];
    $by = $cx['by'];
    $schema = database_state($pdo, $dbUuid)['properties'];
    $titleKey = 'title';
    foreach ($schema as $k => $d) { if (($d['type'] ?? '') === 'title') { $titleKey = $k; } }
    $keys = [];
    foreach ($cols as $c) {
        if ($c['index'] === $titleCol || $c['type'] === 'title') { $keys[$c['index']] = $titleKey; continue; }
        if ($c['type'] === 'relation') { continue; }                                     // its own phase, after every database is made
        $key = resolve_property_key($pdo, $schema, $c['name']);
        if ($key === null) {
            $spec = ['type' => $c['type']] + (isset($c['options']) ? ['options' => $c['options']] : []);
            $r = save_property($pdo, $dbUuid, $c['name'], $spec, $by);
            $key = $r['key'];
            $schema = database_state($pdo, $dbUuid)['properties'];
        } elseif (isset($c['options']) && in_array($schema[$key]['type'] ?? '', ['select', 'multi_select', 'status'], true)) {
            $have = array_map(static fn (array $o): string => strtolower((string) $o['name']), property_options($schema[$key]));
            $add = array_values(array_filter($c['options'], static fn (string $o): bool => !in_array(strtolower($o), $have, true)));
            if ($add !== []) {
                save_property($pdo, $dbUuid, $key, ['options' => array_merge(array_map(static fn (array $o): array => $o, property_options($schema[$key])), array_map(static fn (string $o): array => ['name' => $o], $add))], $by);
                $schema = database_state($pdo, $dbUuid)['properties'];
            }
        }
        $keys[$c['index']] = $key;
    }
    $made = 0;
    $ids = [];
    foreach ($rows as $ri => $r) {
        $raw = [];
        $titleRaw = null;
        foreach ($cols as $c) {
            if (!isset($keys[$c['index']])) { continue; }
            $k = $keys[$c['index']];
            $type = (string) ($schema[$k]['type'] ?? $c['type']);
            $cell = import_cell(['type' => $type === 'title' ? 'title' : $type] + $c, (string) ($r[$c['index']] ?? ''));
            if ($k === $titleKey) { $titleRaw = (string) $cell; } else { $raw[$k] = $cell; }
        }
        if (trim((string) $titleRaw) === '') { $titleRaw = 'Untitled'; }
        [$values, $errors] = coerce_row_values($pdo, $schema, $titleKey, $raw, $titleRaw);
        foreach ($errors as $field => $sentence) {
            import_note($cx, 'row ' . ($ri + 1) . ': ' . $sentence . ' (the value is left out)', true);
            if (preg_match('/^p\[(.*)\]$/', $field, $m)) { unset($raw[$m[1]]); }
        }
        if ($errors !== []) { [$values] = coerce_row_values($pdo, $schema, $titleKey, $raw, $titleRaw); }
        $rowId = create_row($pdo, $dbUuid, $values, null, $by);
        $ids[$ri] = $rowId;
        $made++;
        if (isset($bodies[$ri]) && $zipForBodies !== null) {
            $md = import_notion_prepare(zipfile_read($cx['z'], $cx['entries'][$bodies[$ri]]));
            [, $md] = import_title_of($md, (string) $titleRaw, false);
            if (trim($md) !== '') { $cx['made']['blocks'] += import_insert_tree($cx, $rowId, markdown_to_blocks($md, $cx['mdctx']), null, $bodies[$ri]); }
        }
    }
    return [$made, $ids];
}

/** A database node: made in its own transaction with its rows; its relation columns wait. */
function import_exec_database(array &$cx, array $node, ?string $parent): void
{
    $pdo = $cx['pdo'];
    $titleName = $node['columns'][$node['title_col']]['name'];
    $res = import_tx($cx, function () use (&$cx, $pdo, $node, $parent, $titleName): array {
        $schema = ['title' => ['id' => 'title', 'name' => $titleName, 'type' => 'title', 'order' => 0]];
        $st = $pdo->prepare('SELECT sp_database_create(:s, CAST(:p AS uuid), sp_rich_text(:t), CAST(:props AS jsonb), false, CAST(:id AS uuid))::text');
        $st->execute(['s' => $cx['space'], 'p' => $parent, 't' => $node['title'], 'props' => json_encode($schema), 'id' => import_free_id($pdo, $node['id'])]);
        $uuid = (string) $st->fetchColumn();
        [$made, $ids] = import_rows($cx, $uuid, $node['columns'], $node['rows'], $node['title_col'], $node['bodies'], $cx['z'] !== null ? 'zip' : null);
        $cx['made']['rows'] += $made;
        $cx['made']['pages']++;
        import_note($cx, $node['csv'] . ' → database "' . mb_substr((string) $node['title'], 0, 80) . '" (' . $made . ' rows, ' . count($node['columns']) . ' columns)');
        return [$uuid, $ids];
    });
    $titles = [];
    foreach ($node['rows'] as $ri => $r) { $titles[strtolower(trim($r[$node['title_col']]))] ??= $res[1][$ri] ?? null; }
    $cx['dbs'][$node['ref']] = ['uuid' => $res[0], 'ids' => $res[1], 'titles' => $titles, 'node' => $node];
}

function import_exec_node(array &$cx, array $node, ?string $parent): void
{
    $node['type'] === 'database' ? import_exec_database($cx, $node, $parent) : import_exec_page($cx, $node, $parent);
}

/** Phase two of a zip: every relation column is made (one-way) and each row's links set by title. */
function import_exec_relations(array &$cx): void
{
    $pdo = $cx['pdo'];
    foreach ($cx['dbs'] as $db) {
        foreach ($db['node']['columns'] as $c) {
            if ($c['type'] !== 'relation') { continue; }
            $target = $cx['dbs'][$c['target']] ?? null;
            if ($target === null) { continue; }
            import_tx($cx, function () use (&$cx, $pdo, $db, $c, $target): void {
                $r = save_property($pdo, $db['uuid'], $c['name'], ['type' => 'relation', 'relation_database' => $target['uuid'], 'two_way' => false], $cx['by']);
                foreach ($db['node']['rows'] as $ri => $row) {
                    $ids = [];
                    foreach (import_relation_values((string) ($row[$c['index']] ?? ''), (bool) $db['node']['notion']) as $t) {
                        if (isset($target['titles'][strtolower($t)])) { $ids[] = $target['titles'][strtolower($t)]; }
                    }
                    if ($ids !== [] && isset($db['ids'][$ri])) { set_row_relation($pdo, $db['ids'][$ri], $r['key'], $ids, $cx['by']); }
                }
                import_note($cx, 'relation "' . $c['name'] . '" in "' . mb_substr((string) $db['node']['title'], 0, 60) . '" → "' . mb_substr((string) $target['node']['title'], 0, 60) . '"');
            });
        }
    }
}

/** A CSV into an existing database: the header's columns added when missing, the rows made. The title column is the database's title property's, else the first. */
function import_exec_csv(array &$cx, string $path, string $dbUuid, string $fileName): void
{
    $pdo = $cx['pdo'];
    [$header, $rows] = csv_parse((string) file_get_contents($path));
    if ($header === []) { throw new DomainException('The CSV has no header row.'); }
    $schema = database_state($pdo, $dbUuid)['properties'] ?? throw new DomainException('That database is not here.');
    $titleName = '';
    foreach ($schema as $d) { if (($d['type'] ?? '') === 'title') { $titleName = (string) $d['name']; } }
    $titleCol = 0;
    foreach ($header as $i => $h) { if ($titleName !== '' && strcasecmp($h, $titleName) === 0) { $titleCol = $i; break; } }
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
    $made = import_tx($cx, function () use (&$cx, $dbUuid, $cols, $rows, $titleCol): int {
        [$n] = import_rows($cx, $dbUuid, $cols, $rows, $titleCol);
        return $n;
    });
    $cx['made']['rows'] += $made;
    import_note($cx, $fileName . ' → ' . $made . ' rows in the database');
}

/** Catch the search index up on what an import made (it ran with the bulk flag). */
function import_index_catch_up(PDO $pdo): void
{
    for ($i = 0; $i < 20; $i++) {
        if ((int) $pdo->query('SELECT sp_search_catch_up(500)')->fetchColumn() < 500) { break; }
    }
}

/**
 * Run an import by id, as the member who made it. Marks it running (a second runner finds it taken), executes, marks it done with its counts and log, or failed with the sentence.
 * Returns ['status', 'pages_made', 'rows_made', 'blocks_made', 'unsupported', 'error'?]. Logs page.import (the ids and counts, never a title's body).
 */
function run_import(PDO $pdo, int $importId): array
{
    $claim = $pdo->prepare("UPDATE imports SET status = 'running' WHERE id = :i AND status = 'queued' RETURNING kind, file_name, target_space_id, target_page_id::text AS target_page_id, target_database_id::text AS target_database_id, created_by");
    $claim->execute(['i' => $importId]);
    $imp = $claim->fetch();
    if ($imp === false) { return ['status' => 'skipped']; }
    $by = (int) $imp['created_by'];
    $cx = ['pdo' => $pdo, 'by' => $by, 'z' => null, 'entries' => [], 'notion' => $imp['kind'] === 'notion_zip', 'space' => null, 'made' => ['pages' => 0, 'rows' => 0, 'blocks' => 0, 'unsupported' => 0, 'files' => 0], 'log' => [], 'problems' => 0, 'dbs' => []];
    $error = null;
    $path = import_file_path($pdo, $importId);
    as_acting_member($pdo, $by, function () use ($pdo, $imp, $importId, $path, &$cx, &$error): void {
        try {
            if ($path === null || !is_file($path)) { throw new DomainException('The uploaded file is gone.'); }
            $cx['mdctx'] = markdown_context($pdo);
            $parent = $imp['target_page_id'];
            $cx['space'] = $imp['target_space_id'] === null ? null : (int) $imp['target_space_id'];
            if ($parent !== null) {
                $p = find_page($pdo, $parent) ?? throw new DomainException('The page to import under is not here.');
                $cx['space'] = $p['space_id'];
            }
            if ($imp['kind'] === 'csv') {
                if ($imp['target_database_id'] === null || find_database($pdo, $imp['target_database_id']) === null) { throw new DomainException('That database is not here.'); }
                import_exec_csv($cx, $path, $imp['target_database_id'], (string) $imp['file_name']);
            } elseif ($imp['kind'] === 'markdown') {
                $md = (string) file_get_contents($path);
                if ($md === '') { throw new DomainException('The Markdown file is empty.'); }
                [$title, $body] = import_title_of($md, pathinfo((string) $imp['file_name'], PATHINFO_FILENAME), true);
                $tmpNode = ['type' => 'page', 'title' => $title, 'id' => null, 'md' => null, 'children' => []];
                import_exec_markdown_file($cx, $tmpNode, $body, $parent);
            } else {
                $plan = plan_import($path, (string) $imp['kind']);
                $cx['z'] = zip_open_safe($path);
                $cx['entries'] = $plan['entries'];
                foreach ($plan['nodes'] as $n) { import_exec_node($cx, $n, $parent); }
                import_exec_relations($cx);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = $e instanceof DomainException ? $e->getMessage() : ($e instanceof PDOException ? db_message($e, 'The database refused part of the import.') : 'The import stopped: ' . mb_substr($e->getMessage(), 0, 160));
            if (!($e instanceof DomainException)) { error_log('import ' . $importId . ': ' . $e::class . ': ' . $e->getMessage()); }
        }
        try { import_index_catch_up($pdo); } catch (Throwable $e) { error_log('import catch-up: ' . $e->getMessage()); }
    });
    $log = implode("\n", $cx['log']) . ($error !== null ? "\n! FAILED: " . $error : '');
    $m = $cx['made'];
    $pdo->prepare("UPDATE imports SET status = :s, pages_made = :p, rows_made = :r, blocks_made = :b, unsupported = :u, log = :l, finished_at = now() WHERE id = :i")
        ->execute(['s' => $error === null ? 'done' : 'failed', 'p' => $m['pages'], 'r' => $m['rows'], 'b' => $m['blocks'], 'u' => $m['unsupported'], 'l' => $log === '' ? null : $log, 'i' => $importId]);
    log_activity($pdo, 'page.import', 'import', $importId, ['actor_member_id' => $by, 'space_id' => $cx['space'], 'after' => ['import_id' => $importId, 'kind' => $imp['kind'], 'status' => $error === null ? 'done' : 'failed', 'pages_made' => $m['pages'], 'rows_made' => $m['rows'], 'unsupported' => $m['unsupported']]]);
    return ['status' => $error === null ? 'done' : 'failed', 'pages_made' => $m['pages'], 'rows_made' => $m['rows'], 'blocks_made' => $m['blocks'], 'unsupported' => $m['unsupported']] + ($error === null ? [] : ['error' => $error]);
}

/** A single Markdown file: one page, its Markdown in the converter. */
function import_exec_markdown_file(array &$cx, array $node, string $md, ?string $parent): void
{
    $pdo = $cx['pdo'];
    import_tx($cx, function () use (&$cx, $pdo, $node, $md, $parent): void {
        $st = $pdo->prepare("SELECT sp_page_create(:s, CAST(:p AS uuid), sp_rich_text(:t), 'page', NULL, NULL, NULL)::text");
        $st->execute(['s' => $cx['space'], 'p' => $parent, 't' => $node['title']]);
        $pid = (string) $st->fetchColumn();
        $blocks = trim($md) === '' ? 0 : import_insert_tree($cx, $pid, markdown_to_blocks($md, $cx['mdctx']), null, null);
        if ($blocks > 0) { $pdo->prepare("SELECT sp_version_save(CAST(:p AS uuid), 'import')")->execute(['p' => $pid]); }
        $cx['made']['pages']++;
        $cx['made']['blocks'] += $blocks;
        import_note($cx, 'page "' . mb_substr((string) $node['title'], 0, 80) . '" (' . $blocks . ' blocks)');
    });
}

/** The worker's step: imports still queued (those over 2 MB), oldest first; one stuck running for an hour is failed. Returns ['run', 'done', 'failed', 'interrupted']. */
function import_pass(PDO $pdo, int $limit): array
{
    $out = ['run' => 0, 'done' => 0, 'failed' => 0, 'interrupted' => 0];
    $out['interrupted'] = (int) $pdo->exec("UPDATE imports SET status = 'failed', log = COALESCE(log || E'\\n', '') || '! FAILED: The import was interrupted.', finished_at = now() WHERE status = 'running' AND created_at < now() - interval '1 hour'");
    $ids = $pdo->query("SELECT id FROM imports WHERE status = 'queued' ORDER BY id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $r = run_import($pdo, (int) $id);
        if ($r['status'] === 'skipped') { continue; }
        $out['run']++;
        $out[$r['status']]++;
    }
    return $out;
}

/** Previews older than a day go (the worker's housekeeping calls this). */
function import_preview_sweep(): int
{
    $n = 0;
    foreach (glob(APP_ROOT . IMPORT_PREVIEW_DIR . '/*') ?: [] as $d) {
        if (is_dir($d) && filemtime($d) < time() - 86400) { foreach (glob($d . '/*') ?: [] as $f) { @unlink($f); } if (@rmdir($d)) { $n++; } }
    }
    return $n;
}
