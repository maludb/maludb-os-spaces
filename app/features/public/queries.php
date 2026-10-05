<?php
declare(strict_types=1);

/**
 * The public door (design §4, D13; pages-tree.md "public-page"): no session — the hashed token is the authority; the lookup runs as the writer
 * with no acting member. Rate-limited (60 a minute per address, counted from the trail's page.public_view rows). Nothing here is reached by
 * the voice surface.
 */
require_once dirname(__DIR__, 2) . '/richtext/render.php';

/** The live publication a token opens, or null. */
function public_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT publication_id, page_id, include_subpages, noindex, public_properties, layout FROM sp_public_page_lookup(:h)');
    $st->execute(['h' => hash('sha256', $token)]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['publication_id'] = (int) $r['publication_id'];
    $r['include_subpages'] = (bool) $r['include_subpages'];
    $r['noindex'] = (bool) $r['noindex'];
    $r['public_properties'] = pg_text_array((string) $r['public_properties']);
    return $r;
}

/** The pages a publication exposes (the page, and its subtree when include_subpages). */
function public_page_ids(PDO $pdo, int $publicationId): array
{
    $st = $pdo->prepare('SELECT sp_public_page_ids(:p)::text');
    $st->execute(['p' => $publicationId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** A public page's row (the base table — the door has no member): title, icon, kind, properties, parent, the subpages. */
function public_page(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare('SELECT id::text AS page_id, plain_title, icon, kind, parent_page_id::text AS parent_page_id, properties, parent_database_id, last_edited_at FROM pages WHERE id = CAST(:id AS uuid) AND archived_at IS NULL');
    $st->execute(['id' => $uuid]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function public_children(PDO $pdo, string $uuid, array $publicIds): array
{
    if ($publicIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id::text AS page_id, plain_title, icon, kind FROM pages WHERE parent_page_id = CAST(:id AS uuid) AND archived_at IS NULL AND id = ANY (CAST(:ids AS uuid[])) ORDER BY position');
    $st->execute(['id' => $uuid, 'ids' => '{' . implode(',', $publicIds) . '}']);
    return $st->fetchAll();
}

/** The titles of the published pages (for links inside the tree; anything else is "a page you cannot see"). */
function public_titles(PDO $pdo, array $publicIds): array
{
    if ($publicIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id::text AS id, plain_title FROM pages WHERE id = ANY (CAST(:ids AS uuid[]))');
    $st->execute(['ids' => '{' . implode(',', $publicIds) . '}']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['id']] = $r['plain_title'] !== '' ? $r['plain_title'] : 'Untitled';
    }
    return $out;
}

/** An attachment one of the published pages carries (a block's, a cover, a row's files), or null. */
function public_attachment(PDO $pdo, int $attachmentId, array $publicIds): ?array
{
    if ($publicIds === []) {
        return null;
    }
    $st = $pdo->prepare("SELECT a.id, a.filename, a.mime_type, a.byte_size, a.storage_path, a.thumbnail_path FROM attachments a
                          WHERE a.id = :id AND ((a.record_type = 'block' AND EXISTS (SELECT 1 FROM blocks b WHERE b.id = a.record_uuid AND b.page_id = ANY (CAST(:ids AS uuid[]))))
                                             OR (a.record_type IN ('page_cover', 'page_icon', 'row_files') AND a.record_uuid = ANY (CAST(:ids AS uuid[]))))");
    $st->execute(['id' => $attachmentId, 'ids' => '{' . implode(',', $publicIds) . '}']);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** More than 60 public views from one address in the last minute. */
function public_rate_limited(PDO $pdo, string $ip): bool
{
    return (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'page.public_view' AND ip_address = CAST(:ip AS inet) AND occurred_at > now() - interval '1 minute'", ['ip' => $ip]) >= 60;
}

function bump_public_views(PDO $pdo, int $publicationId): void
{
    $pdo->prepare('UPDATE page_publications SET views = views + 1, last_viewed_at = now() WHERE id = :p')->execute(['p' => $publicationId]);
}
