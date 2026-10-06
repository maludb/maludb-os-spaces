<?php
declare(strict_types=1);

/** Reads of imports (slice 8), through mcp_imports: mine, or everyone's for the Spaces admin. */

const IMPORT_KIND_WORDS = ['markdown' => 'Markdown file', 'markdown_zip' => 'Markdown folder (zip)', 'notion_zip' => 'Notion export (zip)', 'csv' => 'CSV into a database'];
const IMPORT_STATUS_TONE = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];

function import_cast(array $r): array
{
    foreach (['import_id', 'pages_made', 'rows_made', 'blocks_made', 'unsupported', 'created_by', 'target_space_id'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null) { $r[$k] = (int) $r[$k]; }
    }
    return $r;
}

/** The past imports, newest first: mine; $all (the admin) everyone's. */
function find_imports(PDO $pdo, int $limit = 30): array
{
    $st = $pdo->prepare('SELECT i.*, m.display_name AS by_name, s.name AS space_name, p.plain_title AS parent_title, d.plain_title AS database_title
                           FROM mcp_imports i LEFT JOIN members m ON m.id = i.created_by LEFT JOIN spaces s ON s.id = i.target_space_id
                           LEFT JOIN pages p ON p.id = i.target_page_id AND p.id IN (SELECT sp_visible_page_ids()) LEFT JOIN pages d ON d.id = i.target_database_id AND d.id IN (SELECT sp_visible_page_ids())
                          ORDER BY i.import_id DESC LIMIT :l');
    $st->bindValue('l', max(1, min(100, $limit)), PDO::PARAM_INT);
    $st->execute();
    return array_map('import_cast', $st->fetchAll());
}

function find_import(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, m.display_name AS by_name, s.name AS space_name, p.plain_title AS parent_title, d.plain_title AS database_title
                           FROM mcp_imports i LEFT JOIN members m ON m.id = i.created_by LEFT JOIN spaces s ON s.id = i.target_space_id
                           LEFT JOIN pages p ON p.id = i.target_page_id AND p.id IN (SELECT sp_visible_page_ids()) LEFT JOIN pages d ON d.id = i.target_database_id AND d.id IN (SELECT sp_visible_page_ids())
                          WHERE i.import_id = :i');
    $st->execute(['i' => $id]);
    $r = $st->fetch();
    return $r === false ? null : import_cast($r);
}

/** Where a person may import: the spaces they are in (for a root) — the screen's choices. [{space_id, name}] */
function import_spaces(PDO $pdo): array
{
    return $pdo->query("SELECT space_id, name FROM mcp_spaces WHERE archived_at IS NULL AND (i_am_member OR i_am_owner) ORDER BY name")->fetchAll();
}

/** The databases a CSV may go into: visible, live, at least edit. [{database_id, title}] */
function import_databases(PDO $pdo): array
{
    return $pdo->query("SELECT d.database_id, d.title FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id WHERE d.archived_at IS NULL AND p.my_level IN ('edit', 'full') ORDER BY d.title LIMIT 200")->fetchAll();
}
