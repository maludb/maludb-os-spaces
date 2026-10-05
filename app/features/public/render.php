<?php
declare(strict_types=1);

/** The public page's render: the same reader, links kept inside the publication, images through the publication's own door. */
function public_render_page(PDO $pdo, array $page, string $token, array $publicIds, array $embedHosts): string
{
    $tree = json_decode((string) one_value($pdo, 'SELECT sp_page_tree(CAST(:id AS uuid))::text', ['id' => $page['page_id']]), true) ?: [];
    return render_blocks($tree, [
        'file_url' => static fn (int $id): string => '/p/' . $token . '/files/' . $id,
        'page_url' => static fn (string $uuid): ?string => in_array($uuid, $publicIds, true) ? '/p/' . $token . '/' . $uuid : null,
        'titles' => public_titles($pdo, $publicIds),
        'embed_hosts' => $embedHosts,
    ]);
}
