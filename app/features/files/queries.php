<?php
declare(strict_types=1);

/** Attachments (slice 3): the one the caller may see (mcp_attachments / sp_can_see_attachment), the path on disk, the streaming. */

function attachment_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.record_type, a.record_id, a.record_uuid::text AS record_uuid, a.filename, a.mime_type, a.byte_size, a.width, a.height, a.has_thumbnail, a.uploaded_by, a.created_at, b.storage_path, b.thumbnail_path
                           FROM mcp_attachments a JOIN attachments b ON b.id = a.attachment_id WHERE a.attachment_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['attachment_id'] = (int) $r['attachment_id'];
    $r['byte_size'] = (int) $r['byte_size'];
    $r['has_thumbnail'] = (bool) $r['has_thumbnail'];
    return $r;
}

/** The absolute path of an attachment's file (or its thumbnail), inside storage/, or null. */
function attachment_path(array $a, bool $thumb): ?string
{
    $rel = $thumb && ($a['thumbnail_path'] ?? null) !== null ? $a['thumbnail_path'] : $a['storage_path'];
    $root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
    $full = realpath($root . '/' . ltrim((string) $rel, '/'));
    return $full !== false && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full) ? $full : null;
}

/** Stream it: nosniff, inline for images, PDFs, audio and video, a download otherwise; a thumbnail is a JPEG. */
function serve_attachment(array $a, bool $thumb): never
{
    $full = attachment_path($a, $thumb);
    if ($full === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found.');
    }
    $mime = $thumb && ($a['thumbnail_path'] ?? null) !== null ? 'image/jpeg' : (string) $a['mime_type'];
    $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf' || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $a['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($full);
    exit;
}

function present_attachment(array $a): array
{
    return ['attachment_id' => $a['attachment_id'], 'record_type' => $a['record_type'], 'record_id' => $a['record_id'] === null ? null : (int) $a['record_id'], 'record_uuid' => $a['record_uuid'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'],
            'byte_size' => $a['byte_size'], 'width' => $a['width'] === null ? null : (int) $a['width'], 'height' => $a['height'] === null ? null : (int) $a['height'], 'has_thumbnail' => $a['has_thumbnail'], 'url' => '/files/' . $a['attachment_id'], 'thumb_url' => $a['has_thumbnail'] ? '/files/' . $a['attachment_id'] . '/thumb' : null];
}
