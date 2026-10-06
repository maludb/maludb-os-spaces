<?php /** One past import (card). Data: i (find_import row), tz, open? (the log shown at once). Polls itself while it queued or running. */
$s = (string) $i['status']; $tone = IMPORT_STATUS_TONE[$s] ?? 'secondary'; $live = in_array($s, ['queued', 'running'], true);
$where = $i['kind'] === 'csv' ? ($i['database_title'] ?? 'a database') : ($i['parent_title'] ?? $i['space_name'] ?? 'the workspace'); ?>
<div class="card mb-2" id="import-row-<?= (int) $i['import_id'] ?>" data-status="<?= e($s) ?>"<?= $live ? ' hx-get="/import?id=' . (int) $i['import_id'] . '&row=1" hx-trigger="every 3s" hx-swap="outerHTML"' : '' ?>>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div class="min-w-0">
                <div class="fw-semibold text-break"><i class="feather-upload-cloud me-1"></i><?= e($i['file_name']) ?></div>
                <div class="fs-12 text-muted"><?= e(IMPORT_KIND_WORDS[$i['kind']] ?? $i['kind']) ?> into <?= e($where) ?> · <?= e($i['by_name'] ?? 'someone') ?> · <?= e(format_ts($i['created_at'], $tz)) ?></div>
            </div>
            <span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="import-status-<?= (int) $i['import_id'] ?>"><?= $live ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' : '' ?><?= e($s) ?></span>
        </div>
        <?php if (!$live): ?>
        <div class="d-flex flex-wrap gap-3 fs-12 mt-2">
            <span><strong><?= (int) $i['pages_made'] ?></strong> pages</span><span><strong><?= (int) $i['rows_made'] ?></strong> rows</span>
            <span><strong><?= (int) $i['blocks_made'] ?></strong> blocks</span><span><strong><?= (int) $i['unsupported'] ?></strong> unsupported</span>
        </div>
        <?php endif; ?>
        <?php if (($i['log'] ?? '') !== ''): ?>
        <details class="mt-2"<?= !empty($open) || $s === 'failed' ? ' open' : '' ?>><summary class="fs-12 text-muted btn-touch d-flex align-items-center" id="import-log-toggle-<?= (int) $i['import_id'] ?>">The log</summary>
            <pre class="fs-12 mb-0 p-2 bg-soft-secondary rounded" style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto" id="import-log-<?= (int) $i['import_id'] ?>"><?= e($i['log']) ?></pre>
        </details>
        <?php endif; ?>
    </div>
</div>
