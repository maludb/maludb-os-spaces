<?php /** One database as a card (database-list; id database-card-{uuid}). Data: d, here, tz */ $did = (string) $d['database_id']; $where = $d['space_name'] ?? 'Private'; ?>
<div class="card h-100" id="database-card-<?= e($did) ?>"><div class="card-body p-3 d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate"><?= hx_link(with_back('/databases/' . $did, $here), e(($d['icon'] ?? '') !== '' ? $d['icon'] . ' ' : '▦ ') . e($d['plain_title'] !== '' ? $d['plain_title'] : 'Untitled'), 'text-dark') ?></div>
        <?php if ($d['is_inline']): ?><span class="badge bg-soft-secondary text-secondary">inline</span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1 text-truncate"><?= e($where) ?><?php foreach ($d['breadcrumb'] ?? [] as $c): ?> › <?= e($c['title'] ?: 'Untitled') ?><?php endforeach; ?></div>
    <div class="d-flex flex-wrap gap-1 mt-2">
        <span class="badge bg-soft-info text-info"><?= (int) $d['row_count'] ?> row<?= $d['row_count'] === 1 ? '' : 's' ?></span>
        <span class="badge bg-soft-secondary text-secondary"><?= (int) $d['property_count'] ?> propert<?= $d['property_count'] === 1 ? 'y' : 'ies' ?></span>
        <?php if ($d['is_locked']): ?><span class="badge bg-dark"><i class="feather-lock"></i></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-auto pt-2">Edited <?= e(format_ts($d['last_edited_at'], $tz, 'M j, g:i A')) ?><?= $d['editor_name'] ? ' by ' . e($d['editor_name']) : '' ?></div>
</div></div>
