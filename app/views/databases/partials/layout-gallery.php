<?php /** The gallery (id database-rows). Data: d, view, cols, rows, total, may, here, schema, covers (row id => attachment id), mimes */
$size = $view['card_size'] ?? 'medium'; ?>
<div class="row g-3 sp-gallery sp-gallery-<?= e($size) ?>" id="database-rows">
    <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted" id="database-empty">No rows yet.</div></div></div><?php endif; ?>
    <?php foreach ($rows as $r):
        $cover = $covers[$r['row_id']] ?? null; ?>
        <div class="col-12 col-sm-6 <?= $size === 'small' ? 'col-md-3 col-xl-2' : ($size === 'large' ? 'col-md-6 col-xl-4' : 'col-md-4 col-xl-3') ?>"><?= view('databases/partials/row-card.php', ['row' => $r, 'cols' => $cols, 'd' => $d, 'here' => $here, 'mode' => 'gallery', 'cover' => $cover, 'mime' => $cover === null ? null : ($mimes[$cover]['mime_type'] ?? null), 'may' => $may]) ?></div>
    <?php endforeach; ?>
</div>
<div class="fs-12 text-muted mt-2 d-flex justify-content-between align-items-center" id="database-rows-foot"><span><?= count($rows) ?> of <?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($total > count($rows)): ?><?= hx_link(preg_replace('/[?&]limit=\d+/', '', $here) . (str_contains(preg_replace('/[?&]limit=\d+/', '', $here), '?') ? '&' : '?') . 'limit=' . ($limit + 100), 'Load more', 'btn btn-light btn-touch', 'id="database-load-more"') ?><?php endif; ?></div>
