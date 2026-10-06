<?php
declare(strict_types=1);

/** Whitelists over an export row (slice 8). The file itself is downloaded through /exports/download.php; the log is a count in words, never a page's text. */
function present_export(array $e): array
{
    $state = export_state($e);
    return ['export_id' => (int) $e['export_id'], 'kind' => $e['kind'], 'format' => $e['format'], 'subject' => export_subject($e), 'status' => $state, 'byte_size' => $e['byte_size'] === null ? null : (int) $e['byte_size'],
            'item_count' => (int) $e['item_count'], 'log' => $e['log'], 'created_by' => $e['created_by'] === null ? null : (int) $e['created_by'], 'created_at' => json_ts($e['created_at']), 'finished_at' => json_ts($e['finished_at']),
            'expires_at' => json_ts($e['expires_at']), 'downloaded_at' => json_ts($e['downloaded_at'] ?? null), 'available' => (bool) $e['available'],
            'download_url' => $state === 'done' ? '/exports/download.php?id=' . (int) $e['export_id'] : null];
}

function export_size_words(?int $bytes): string
{
    if ($bytes === null) { return ''; }
    return $bytes < 1024 ? $bytes . ' B' : ($bytes < 1048576 ? round($bytes / 1024, 1) . ' KB' : round($bytes / 1048576, 1) . ' MB');
}
