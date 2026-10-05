<?php /** The list (id database-rows). Data: d, view, cols, rows, total, limit, may, here */ ?>
<div class="card" id="database-rows"><div class="list-group list-group-flush">
    <?php if ($rows === []): ?><div class="list-group-item text-muted" id="database-empty">No rows yet.</div><?php endif; ?>
    <?php foreach ($rows as $r) { echo view('databases/partials/row-card.php', ['row' => $r, 'cols' => $cols, 'd' => $d, 'here' => $here, 'mode' => 'list', 'cover' => null, 'mime' => null, 'may' => $may]); } ?>
</div>
<div class="card-footer d-flex justify-content-between align-items-center fs-12 text-muted" id="database-rows-foot"><span><?= count($rows) ?> of <?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($total > count($rows)): ?><?= hx_link(preg_replace('/[?&]limit=\d+/', '', $here) . (str_contains(preg_replace('/[?&]limit=\d+/', '', $here), '?') ? '&' : '?') . 'limit=' . ($limit + 100), 'Load more', 'btn btn-light btn-touch', 'id="database-load-more"') ?><?php endif; ?></div></div>
