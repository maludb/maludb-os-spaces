<?php
declare(strict_types=1);

/**
 * The prelude of a page handler (slice 2): the feature's files, the reader, the page a request names (through mcp_pages — 404 when
 * unseen; the permission tree decides, never this code), and the one logger stamping entity_uuid and space_id on every row.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/richtext/render.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The page the request names (`page` or `id`), through the view: 404 when unseen. $live refuses one in the trash in words. */
function page_from_request(PDO $pdo, bool $live = true): array
{
    $id = (string) (req_val('page') ?? ($_GET['page'] ?? ($_GET['id'] ?? '')));
    if (!is_uuid($id)) {
        refuse(422, 'Say which page.');
    }
    $p = find_page($pdo, $id) ?? refuse(404, 'Page not found.');
    if ($live && $p['archived_at'] !== null) {
        refuse(422, 'Page "' . $p['plain_title'] . '" is in the trash: restore it first.');
    }
    return $p;
}

/** A row of the trail about a page: entity_uuid and space_id on every one (design §6). Never the page's text. */
function page_log(PDO $pdo, string $action, string $pageUuid, ?int $spaceId, array $opts = []): void
{
    log_activity($pdo, $action, 'page', $pageUuid, ['space_id' => $spaceId] + $opts);
}

/** The notice banners a page screen lands with. */
function page_notices(array $p): array
{
    $t = $p['plain_title'] !== '' ? $p['plain_title'] : 'the page';
    return ['created' => ['success', 'Made ' . $t . '.'], 'saved' => ['success', 'Saved ' . $t . '.'], 'moved' => ['success', 'Moved ' . $t . '.'], 'duplicated' => ['success', 'This is the copy.'],
            'locked' => ['success', $t . ' is locked.'], 'unlocked' => ['success', $t . ' is unlocked.'], 'favorite' => ['success', 'Added to your favorites.'], 'unfavorite' => ['success', 'Removed from your favorites.'],
            'shared' => ['success', 'Shared.'], 'unshared' => ['success', 'No longer shared with them.'], 'restricted' => ['warning', 'Restricted: only the people named here reach ' . $t . '.'], 'unrestricted' => ['success', 'The space\'s default is back.'],
            'published' => ['success', $t . ' is on the web. Copy the link now: it is shown once.'], 'rotated' => ['success', 'A new link. The old one stops now.'], 'unpublished' => ['success', $t . ' is off the web.'],
            'restored' => ['success', $t . ' is back.'], 'verified' => ['success', $t . ' is verified.'], 'owner' => ['success', 'Saved who owns ' . $t . '.'], 'template' => ['success', 'Saved.'], 'trashed' => ['warning', $t . ' is in the trash.']];
}
