<?php
declare(strict_types=1);
/** /databases/{id}/rows/{row} — a row as a page (screen `row-view`): slice 2's page screen with a properties panel above the body (the reader, or slice 3's editor for someone with edit_content). */
require_once dirname(__DIR__, 3) . '/app/features/databases/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/pages/screen.php';
require_login();
require_human();
$pdo = db();
$r = find_row($pdo, (string) ($_GET['row'] ?? '')) ?? refuse(404, 'Row not found.');
if ((string) ($_GET['id'] ?? '') !== $r['parent_database_id']) { refuse(404, 'Row not found.'); }
$d = $r['database'] ?? refuse(404, 'Database not found.');
$panel = render_row_panel($pdo, $r, '/databases/' . $d['database_id'] . '/rows/' . $r['page_id']);
page_screen($pdo, $r['page_id'], ['screen' => 'row-view', 'entity' => 'row', 'activeNav' => 'databases', 'panel' => $panel,
    'extra' => ['database' => ['database_id' => $d['database_id'], 'title' => $d['plain_title']], 'properties' => present_schema($d['properties']),  'row' => ['row_id' => $r['page_id'], 'properties' => $r['resolved'], 'values' => (function () use ($d, $r): array { $o = []; foreach ($d['properties'] as $k => $def) { $o[$k] = display_value((string) $def['type'], $r['resolved'][$k] ?? null); } return $o; })()]]]);
