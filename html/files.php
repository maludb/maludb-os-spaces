<?php
declare(strict_types=1);
/**
 * GET /files/{id} (and /files/{id}/thumb) — the gated attachment door: a session (an anonymous request is 401, never a redirect: a file URL
 * sits in an <img>), and the record's visibility as db/012 sp_can_see_attachment decides it. Streams the file from storage/ with its type.
 * Slices 3–5 upload; this door serves. Not an action of the manifest; no log (a page view logs the page).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sign in required.');
}
$id = request_integer('id');
$pdo = db();
$row = null;
if ($id !== null && db_bool($pdo, 'SELECT sp_can_see_attachment(:id)', ['id' => $id])) {
    $st = $pdo->prepare('SELECT filename, mime_type, byte_size, storage_path, thumbnail_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch() ?: null;
}
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
$path = request_bool('thumb') && ($row['thumbnail_path'] ?? null) !== null ? $row['thumbnail_path'] : $row['storage_path'];
$root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
$full = realpath($root . '/' . ltrim((string) $path, '/'));
if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
$inline = str_starts_with((string) $row['mime_type'], 'image/') || $row['mime_type'] === 'application/pdf' || str_starts_with((string) $row['mime_type'], 'video/') || str_starts_with((string) $row['mime_type'], 'audio/');
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($full);
