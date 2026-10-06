<?php /** One export (card). Data: e (find_my_exports row), tz, all (the admin sees whose it is). Polls itself while queued or running. */
$state = export_state($e); $tone = $state === 'expired' ? 'dark' : (EXPORT_STATUS_TONE[$state] ?? 'secondary'); $live = in_array($state, ['queued', 'running'], true); [$icon, $kindWord] = EXPORT_KIND_WORDS[$e['kind']] ?? ['feather-download', 'Export'];
$id = (int) $e['export_id']; $mine = $e['created_by'] !== null && (int) $e['created_by'] === (int) current_member_id(); ?>
<div class="card mb-2" id="export-row-<?= $id ?>" data-status="<?= e($state) ?>"<?= $live ? ' hx-get="/exports/?row=' . $id . '" hx-trigger="every 3s" hx-swap="outerHTML"' : '' ?>>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div class="min-w-0">
                <div class="fw-semibold text-break"><i class="<?= e($icon) ?> me-1"></i><?= e(export_subject($e)) ?></div>
                <div class="fs-12 text-muted"><?= e($kindWord) ?> as <?= e($e['format']) ?><?= $e['kind'] === 'page' && $e['include_subpages'] ? ' with subpages' : '' ?><?= !empty($all) ? ' · ' . e($e['by_name'] ?? 'someone') : '' ?> · <?= e(format_ts($e['created_at'], $tz)) ?></div>
            </div>
            <span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="export-status-<?= $id ?>"><?= $live ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' : '' ?><?= e($state) ?></span>
        </div>
        <?php if ($state === 'done'): ?>
        <div class="fs-12 mt-2"><?= (int) $e['item_count'] ?> items · <?= e(export_size_words($e['byte_size'] === null ? null : (int) $e['byte_size'])) ?> · available until <?= e(format_ts($e['expires_at'], $tz)) ?></div>
        <?php elseif ($state === 'failed'): ?><div class="fs-12 text-danger mt-2" id="export-error-<?= $id ?>"><?= e($e['log'] ?? 'It failed.') ?></div>
        <?php elseif ($state === 'expired'): ?><div class="fs-12 text-muted mt-2">The file is gone; make the export again if you still need it.</div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if ($state === 'done'): ?><a href="/exports/download.php?id=<?= $id ?>" class="btn btn-primary btn-touch" id="export-download-<?= $id ?>"><i class="feather-download me-1"></i>Download</a><?php endif; ?>
            <?php if ($mine && in_array($state, ['done', 'failed', 'expired'], true)): ?>
            <form method="post" action="/exports/delete.php" hx-post="/exports/delete.php" hx-target="#flash" hx-confirm="Delete this export<?= $state === 'done' ? ' and its file' : '' ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="export" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch" id="export-delete-<?= $id ?>"><i class="feather-trash-2 me-1"></i>Delete</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
