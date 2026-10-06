<?php
declare(strict_types=1);

/** Whitelists over an import row for the JSON branch of /import and the agents (slice 8). The log is the import's own account (file names, counts, sentences): never a page's text. */
function present_import(array $i): array
{
    return ['import_id' => (int) $i['import_id'], 'kind' => $i['kind'], 'file_name' => $i['file_name'], 'status' => $i['status'], 'pages_made' => (int) $i['pages_made'], 'rows_made' => (int) $i['rows_made'],
            'blocks_made' => (int) $i['blocks_made'], 'unsupported' => (int) $i['unsupported'], 'log' => $i['log'],
            'target' => ['space_id' => isset($i['target_space_id']) ? (int) $i['target_space_id'] : null, 'page_id' => $i['target_page_id'] ?? null, 'database_id' => $i['target_database_id'] ?? null],
            'created_by' => isset($i['created_by']) ? (int) $i['created_by'] : null, 'created_at' => json_ts($i['created_at'] ?? null), 'finished_at' => json_ts($i['finished_at'] ?? null)];
}

function present_import_preview(array $p): array
{
    return ['kind' => $p['kind'], 'pages' => (int) $p['pages_total'], 'databases' => (int) $p['databases'], 'rows' => (int) $p['rows'], 'unsupported' => (int) $p['unsupported'], 'tree' => $p['tree']];
}
