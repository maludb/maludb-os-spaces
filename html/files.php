<?php
declare(strict_types=1);
/**
 * GET /files/{id} (and /files/{id}/thumb) — the gated attachment door: a session (an anonymous request is 401, never a redirect: a file URL sits in an
 * <img>), and the record's visibility as db/012 sp_can_see_attachment decides it (through mcp_attachments). Streams with nosniff, inline for images,
 * PDFs, audio and video, a download otherwise. Not an action of the manifest; no log (a page view logs the page).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/files/queries.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sign in required.');
}
$id = request_integer('id');
$a = $id === null ? null : attachment_for(db(), $id);
if ($a === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
serve_attachment($a, request_bool('thumb'));
