<?php
declare(strict_types=1);
/**
 * /import — import a Markdown file or zip, a Notion export zip, a CSV into a database (screen `import`; params: space, parent, database; id = one import (JSON, or the polled row with row=1);
 * preview = a stored zip's token: its tree and counts before anything is made). Past imports with their logs: mine, the admin's everyone's.
 */
require_once dirname(__DIR__) . '/app/features/imports/handler.php';
require_login();
require_human();
$pdo = db();
if (!has_right('pages.write') && !has_right('databases.write')) { refuse(403, 'You may not import: ask a space owner to add you to a space.'); }
$me = (int) current_member_id();
$id = request_integer('id');
if ($id !== null) {
    $imp = find_import($pdo, $id) ?? refuse(404, 'Import not found.');
    if (wants_json()) { respond_screen(['import' => present_import($imp)]); }
    if (request_bool('row')) { echo view('import/partials/row.php', ['i' => $imp, 'tz' => member_timezone(), 'open' => true]); exit; }
}
$space = request_integer('space');
$parent = request_string('parent');
$parent = is_uuid($parent) ? $parent : '';
$database = request_string('database');
$database = is_uuid($database) ? $database : '';
$spaces = import_spaces($pdo);
$databases = import_databases($pdo);
$parentPage = $parent !== '' ? find_page($pdo, $parent) : null;
if ($parentPage === null) { $parent = ''; }
$preview = null;
$token = request_string('preview');
$previewError = null;
if ($token !== '') {
    $dir = import_preview_dir($token, $me);
    $f = $dir === null ? null : import_preview_file($dir);
    if ($f === null) { $previewError = 'That preview has gone: choose the file again.'; $token = ''; }
    else {
        try { $preview = preview_zip($f[0], request_string('kind') !== '' ? request_string('kind') : null) + ['file_name' => $f[1]]; }
        catch (DomainException $e) { $previewError = $e->getMessage(); $token = ''; }
    }
}
$rows = find_imports($pdo, 30);
log_screen_view($pdo, 'import');
if (wants_json()) {
    respond_screen(['imports' => array_map('present_import', $rows), 'preview' => $preview === null ? null : present_import_preview($preview), 'error' => $previewError, 'spaces' => array_map(static fn (array $s): array => ['space_id' => (int) $s['space_id'], 'name' => $s['name']], $spaces)]);
}
render_screen('Import', view('import/page.php', ['spaces' => $spaces, 'databases' => $databases, 'space' => $space, 'parent' => $parent, 'parentPage' => $parentPage, 'database' => $database, 'preview' => $preview, 'token' => $token,
    'previewError' => $previewError, 'rows' => $rows, 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, import_notices()), 'kind' => request_string('kind'), 'here' => here_url()]), ['activeNav' => 'import', 'screen' => 'import']);
