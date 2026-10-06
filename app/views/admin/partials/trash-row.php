<?php /** One trashed page (`trash-row-{uuid}`): a card; the purge date `warning` within 3 days. Data: t, may, here, tz */ $pid = (string) $t['page_id']; ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="trash-row-<?= e($pid) ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="fw-bold text-truncate"><?= hx_link(with_back('/pages/' . $pid, $here), e((($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . (($t['plain_title'] ?? '') !== '' ? $t['plain_title'] : 'Untitled')), 'text-dark') ?></div>
    <div class="fs-12 text-muted mt-1"><?= e((string) ($t['space_name'] ?? 'Private')) ?> · trashed by <?= e((string) ($t['archived_by_name'] ?? 'someone')) ?> <?= e(format_ts($t['archived_at'], $tz, 'M j')) ?></div>
    <div class="mt-1"><span class="badge bg-<?= $t['soon'] ? 'warning text-dark' : 'soft-secondary text-secondary' ?>" id="trash-row-<?= e($pid) ?>-purge-at">purged on <?= e(format_date($t['purge_at'])) ?></span></div>
    <div class="d-flex gap-2 mt-auto pt-2">
        <form method="post" action="/pages/restore.php" hx-post="/pages/restore.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/admin/trash"><button type="submit" class="btn btn-light btn-touch w-100" id="trash-row-<?= e($pid) ?>-restore-btn">Restore</button></form>
        <?php if ($may['purge']): ?><form method="post" action="/pages/purge.php" hx-post="/pages/purge.php" hx-target="#flash" hx-confirm="Delete <?= e(($t['plain_title'] ?? '') ?: 'this page') ?> for good?" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/admin/trash"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="trash-row-<?= e($pid) ?>-purge-btn">Purge</button></form><?php endif; ?>
    </div>
</div></div></div>
