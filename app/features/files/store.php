<?php
declare(strict_types=1);

/**
 * Attachments (slice 3; design D14): store_attachment() — finfo decides the type, the allow-list (images, PDF, text, office documents, audio,
 * video, zip; HEIC kept; never HTML, SVG or a program), the workspace's size limit, the sha256, the file under storage/attachments/<kind>/<id>/,
 * a GD thumbnail for images. The row's record is a block, a page (its cover or icon), a comment, a row's files, a message (slice 4), a space's icon.
 */

const ATTACHMENT_MIME_ALLOW = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/avif', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json',
    'application/zip', 'application/x-zip-compressed', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'video/mp4', 'video/webm', 'video/quicktime'];
const ATTACHMENT_RECORD_KINDS = ['block', 'message', 'comment', 'page_cover', 'page_icon', 'row_files', 'space_icon', 'import', 'export'];

function attachment_max_bytes(PDO $pdo): int
{
    $env = (int) (env('ATTACHMENT_MAX_BYTES', '0') ?: 0);
    $set = (int) (one_value($pdo, 'SELECT max_attachment_bytes FROM sp_settings WHERE id = 1') ?: 26214400);
    return max(1048576, $env > 0 ? min($env, $set) : $set);
}

/** Store an uploaded file ($_FILES entry) on a record. Returns: id, filename, mime_type, byte_size, width, height, has_thumbnail, sha256. */
function store_attachment(PDO $pdo, array $file, string $recordType, int|string $recordId, int $by): array
{
    if (!in_array($recordType, ATTACHMENT_RECORD_KINDS, true)) {
        throw new DomainException('That is not a record a file attaches to.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $max = attachment_max_bytes($pdo);
    $size = (int) ($file['size'] ?? 0);
    if ($size > $max) {
        throw new DomainException('A file is at most ' . intdiv($max, 1048576) . ' MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file';
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll|html?|svg|php)$/i', $name)) {
        throw new DomainException('HTML, SVG and programs are not accepted; images, PDFs, text, office documents, audio, video and zip are.');
    }
    $sha = hash_file('sha256', $tmp);
    $width = null;
    $height = null;
    if (str_starts_with($mime, 'image/') && !in_array($mime, ['image/heic', 'image/heif'], true)) {
        $info = @getimagesize($tmp);
        if (is_array($info)) { $width = (int) $info[0] ?: null; $height = (int) $info[1] ?: null; }
    }
    $isUuid = is_uuid($recordId);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, record_uuid, filename, mime_type, byte_size, sha256, storage_path, uploaded_by, width, height) VALUES (:t, :rid, CAST(:ruuid AS uuid), :n, :m, :s, :h, :p, :by, :w, :hgt) RETURNING id');
    $st->execute(['t' => $recordType, 'rid' => $isUuid ? null : (int) $recordId, 'ruuid' => $isUuid ? (string) $recordId : null, 'n' => $name, 'm' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'by' => $by, 'w' => $width, 'hgt' => $height]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/' . $recordType . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $recordType . '/' . $id . '/' . substr($sha, 0, 12) . '-' . $name;
    $full = APP_ROOT . '/storage/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    $thumb = null;
    if ($width !== null && in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) && function_exists('imagecreatefromstring')) {
        $thumb = make_thumbnail($full, $dir . '/thumb.jpg') ? 'attachments/' . $recordType . '/' . $id . '/thumb.jpg' : null;
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p, thumbnail_path = :th WHERE id = :id')->execute(['p' => $path, 'th' => $thumb, 'id' => $id]);
    return ['id' => $id, 'filename' => $name, 'mime_type' => $mime, 'byte_size' => $size, 'width' => $width, 'height' => $height, 'has_thumbnail' => $thumb !== null, 'sha256' => $sha];
}

/** A JPEG thumbnail 320 px wide (GD). */
function make_thumbnail(string $src, string $dst, int $maxW = 320): bool
{
    $data = @file_get_contents($src);
    if ($data === false) { return false; }
    $im = @imagecreatefromstring($data);
    if ($im === false) { return false; }
    $w = imagesx($im); $h = imagesy($im);
    if ($w < 1 || $h < 1) { return false; }
    $tw = min($maxW, $w); $th = (int) max(1, round($h * $tw / $w));
    $t = imagecreatetruecolor($tw, $th);
    $white = imagecolorallocate($t, 255, 255, 255);
    imagefill($t, 0, 0, $white);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($t, $dst, 82);
    imagedestroy($t); imagedestroy($im);
    return $ok;
}

/** Remove an attachment's row and files. Returns its loggable facts. */
function delete_attachment(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT id, record_type, record_id, record_uuid::text AS record_uuid, filename, mime_type, byte_size, storage_path, thumbnail_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $a = $st->fetch();
    if ($a === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    foreach ([$a['storage_path'], $a['thumbnail_path']] as $p) {
        if ($p !== null && $p !== '' && $p !== 'pending') { @unlink(APP_ROOT . '/storage/' . $p); }
    }
    @rmdir(APP_ROOT . '/storage/attachments/' . $a['record_type'] . '/' . $id);
    return ['attachment_id' => (int) $a['id'], 'record_type' => $a['record_type'], 'record_id' => $a['record_id'] === null ? null : (int) $a['record_id'], 'record_uuid' => $a['record_uuid'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => (int) $a['byte_size']];
}
