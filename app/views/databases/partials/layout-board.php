<?php
/** The board (id database-rows): a column per value of the group property, cards you drag between them. Data: d, view, cols, rows, total, may, here, schema */
$key = (string) $view['group_by'];
$def = $schema[$key] ?? null;
?>
<?php if ($def === null): ?><div class="alert alert-warning" id="database-board-nogroup">This board groups by a property that is not there any more. Edit the view to pick another.</div>
<?php else: $cols_ = group_rows($rows, $key, $def, true); ?>
<div class="sp-board" id="database-rows" data-key="<?= e($key) ?>" data-type="<?= e($def['type']) ?>" data-may="<?= $may['edit'] ? '1' : '0' ?>">
    <?php foreach ($cols_ as $g): ?>
    <section class="sp-board-col" id="board-column-<?= e($g['slug']) ?>" data-value="<?= e($g['value']) ?>" aria-label="<?= e($g['label']) ?>">
        <header class="sp-board-head d-flex align-items-center justify-content-between gap-2"><span><?= $g['value'] !== '' ? render_chip($g['label'], $g['color']) : '<span class="text-muted fs-13">' . e($g['label']) . '</span>' ?></span><span class="badge bg-light text-dark sp-board-count" id="board-column-<?= e($g['slug']) ?>-count"><?= count($g['rows']) ?></span></header>
        <div class="sp-board-cards" data-column="<?= e($g['slug']) ?>" data-value="<?= e($g['value']) ?>">
            <?php foreach ($g['rows'] as $r) { echo view('databases/partials/row-card.php', ['row' => $r, 'cols' => $cols, 'd' => $d, 'here' => $here, 'mode' => 'board', 'cover' => null, 'mime' => null, 'may' => $may]); } ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<div class="fs-12 text-muted mt-2" id="database-rows-foot"><?= count($rows) ?> of <?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?><?= $may['edit'] ? ' · drag a card to another column to change ' . e($def['name'] ?? $key) : '' ?></div>
<?php endif; ?>
