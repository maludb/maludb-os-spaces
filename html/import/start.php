<?php
declare(strict_types=1);
/**
 * Action `import_start` (log `page.import`: import_id, kind, pages_made, rows_made, unsupported; confirm): a multipart `file` (.md, .zip or .csv), `kind` (markdown, markdown_zip, notion_zip, csv — from the file by default),
 * `space` or `parent` (where pages land: a member of the space, or edit on the page), `database` (a CSV's target: edit). A file under 2 MB runs in the request; a larger one waits for the worker's next pass and the
 * screen polls. `preview=yes` stores a zip and answers the screen with its tree and counts instead; `preview_token` imports what was previewed.
 */
require_once dirname(__DIR__, 2) . '/app/features/imports/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$kind = req_val('kind') ?? '';
$spaceId = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
$parent = (string) (req_val('parent') ?? '');
$database = (string) (req_val('database') ?? '');
if ($parent !== '' && !is_uuid($parent)) { sp_refuse_fields(['parent' => 'Choose the page from the list.']); }
if ($database !== '' && !is_uuid($database)) { sp_refuse_fields(['database' => 'Choose the database from the list.']); }
if ($kind !== '' && !in_array($kind, ['markdown', 'markdown_zip', 'notion_zip', 'csv'], true)) { sp_refuse_fields(['kind' => 'The kind is markdown, markdown_zip, notion_zip or csv.']); }
// the destination's gate: a CSV's database (edit), else a page (edit) or a space the caller is in
if ($kind === 'csv' || ($database !== '' && $kind === '')) {
    if ($database === '') { sp_refuse_fields(['database' => 'Say which database the CSV goes into.']); }
    require_page_level($database, 'edit', 'Database');
    $spaceId = null; $parent = '';
} elseif ($parent !== '') {
    require_page_level($parent, 'edit');
    $spaceId = null;
} elseif ($spaceId !== null) {
    $s = find_space($pdo, $spaceId) ?? refuse(404, 'Space not found.');
    if (!($s['i_am_member'] ?? false) && !($s['i_am_owner'] ?? false) && !is_sp_admin()) { refuse(403, 'You are not in ' . $s['name'] . ': ask an owner to add you.'); }
    if (!has_right('pages.write')) { refuse(403, 'You may not write pages.'); }
} else {
    sp_refuse_fields(['space' => 'Say where the pages go: a space or a page.']);
}
if (!has_right('pages.write') && !has_right('databases.write')) { refuse(403, 'You may not import.'); }
// the file: an upload, or a zip already previewed
$token = (string) (req_val('preview_token') ?? '');
$file = $_FILES['file'] ?? null;
$fromPreview = null;
if ($token !== '') {
    $dir = import_preview_dir($token, $me) ?? sp_refuse_fields(['preview_token' => 'That preview has gone: choose the file again.']);
    $f = import_preview_file($dir) ?? sp_refuse_fields(['preview_token' => 'That preview has gone: choose the file again.']);
    $file = ['name' => $f[1], 'tmp_name' => $f[0], 'size' => filesize($f[0]), 'error' => UPLOAD_ERR_OK];
    $fromPreview = $dir;
} elseif (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    sp_refuse_fields(['file' => 'Send the file as multipart `file`: a .md, .zip or .csv.']);
}
$fields = ['kind' => $kind, 'space' => $spaceId, 'parent' => $parent === '' ? null : $parent, 'database' => $database === '' ? null : $database];
$dest = $spaceId !== null ? 'space=' . $spaceId : ($parent !== '' ? 'parent=' . $parent : 'database=' . $database);
if (sp_yes('preview', false)) {
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'import.zip')));
    $k = $kind !== '' ? $kind : (import_kind_from_file($name, $tmp) ?? '');
    if (!in_array($k, ['markdown_zip', 'notion_zip'], true)) { sp_refuse_fields(['file' => 'Only a zip has a preview: a Markdown file or a CSV is imported at once.']); }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_file($tmp)) { sp_refuse_fields(['file' => 'The upload did not arrive.']); }
    $tok = $me . '-' . bin2hex(random_bytes(8));
    $dir = APP_ROOT . IMPORT_PREVIEW_DIR . '/' . $tok;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) { refuse(500, 'The preview store is not writable.'); }
    $safe = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'import.zip';
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $safe) : copy($tmp, $dir . '/' . $safe))) { refuse(500, 'The preview could not be stored.'); }
    try { preview_zip($dir . '/' . $safe, $k); }
    catch (DomainException $e) { @unlink($dir . '/' . $safe); @rmdir($dir); sp_refuse_fields(['file' => $e->getMessage()]); }
    sp_done('Previewed ' . $safe, null, '/import?preview=' . $tok . '&kind=' . $k . '&' . $dest, '');
}
$size = (int) ($file['size'] ?? 0);
$importId = (int) sp_guard($pdo, static function () use ($pdo, $fields, $file, $me): int {
    $pdo->beginTransaction();
    $id = start_import($pdo, $fields, $file, $me);
    $pdo->commit();
    return $id;
});
if ($fromPreview !== null) { @rmdir($fromPreview); }
$result = $size < IMPORT_INLINE_BYTES ? run_import($pdo, $importId) : ['status' => 'queued'];
$notice = $result['status'] === 'failed' ? 'failed' : ($result['status'] === 'queued' ? 'queued' : 'done');
$words = $result['status'] === 'queued' ? 'Queued the import' : ($result['status'] === 'failed' ? 'The import failed' : 'Imported ' . (int) $result['pages_made'] . ' pages and ' . (int) $result['rows_made'] . ' rows');
sp_done($words, $importId, '/import?id=' . $importId . '&notice=' . $notice, '', ['status' => $result['status'], 'import_id' => $importId] + array_intersect_key($result, array_flip(['pages_made', 'rows_made', 'blocks_made', 'unsupported', 'error'])));
