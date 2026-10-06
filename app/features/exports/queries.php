<?php
declare(strict_types=1);

/** The choices of the exports screen and the words of an export row (slice 8). Reads through mcp_exports, mcp_pages, mcp_databases, mcp_spaces, mcp_channels: nothing a person may not see is offered. */

const EXPORT_KIND_WORDS = ['page' => ['feather-file-text', 'Page'], 'database' => ['feather-database', 'Database'], 'space' => ['feather-layers', 'Space'], 'channel' => ['feather-hash', 'Channel'], 'all' => ['feather-archive', 'Everything']];

/** What an export is of, in words: the page's title, the space's name, "#channel", "Everything". */
function export_subject(array $e): string
{
    return match ($e['kind']) {
        'page', 'database' => (string) ($e['page_title'] ?? 'a page that is gone'),
        'space' => (string) ($e['space_name'] ?? 'a space that is gone'),
        'channel' => '#' . (string) ($e['channel_name'] ?? 'a channel that is gone'),
        default => 'Everything you may read',
    };
}

/** The state of an export row as the list shows it: queued | running | done | failed | expired. */
function export_state(array $e): string
{
    if ($e['status'] === 'done' && !$e['available']) { return 'expired'; }
    return (string) $e['status'];
}

function export_pick_pages(PDO $pdo, ?string $include = null): array
{
    $rows = $pdo->query("SELECT page_id::text AS id, plain_title AS title FROM mcp_pages WHERE kind = 'page' AND archived_at IS NULL AND NOT is_template ORDER BY last_edited_at DESC LIMIT 200")->fetchAll();
    if ($include !== null && !in_array($include, array_column($rows, 'id'), true)) {
        $st = $pdo->prepare("SELECT page_id::text AS id, plain_title AS title FROM mcp_pages WHERE page_id = CAST(:p AS uuid) AND kind = 'page'");
        $st->execute(['p' => $include]);
        foreach ($st->fetchAll() as $r) { array_unshift($rows, $r); }
    }
    return $rows;
}

function export_pick_databases(PDO $pdo): array
{
    return $pdo->query('SELECT database_id::text AS id, title FROM mcp_databases WHERE archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM mcp_pages p WHERE p.page_id = mcp_databases.database_id AND p.is_template) ORDER BY title LIMIT 200')->fetchAll();
}

/** The spaces the caller may export: the ones they own (the admin: every one they see). */
function export_pick_spaces(PDO $pdo, bool $admin): array
{
    return $pdo->query('SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL' . ($admin ? '' : ' AND i_am_owner') . ' ORDER BY name')->fetchAll();
}

function export_pick_channels(PDO $pdo, bool $admin): array
{
    return $pdo->query("SELECT c.channel_id, c.name, s.name AS space_name FROM mcp_channels c JOIN mcp_spaces s ON s.space_id = c.space_id
                         WHERE c.kind IN ('public', 'private') AND c.archived_at IS NULL" . ($admin ? '' : ' AND s.i_am_owner') . ' ORDER BY s.name, c.name LIMIT 300')->fetchAll();
}
