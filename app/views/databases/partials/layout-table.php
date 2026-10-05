<?php
/** The table layout (id database-rows). Data: d, view, cols (visible properties), rows, total, limit, may, here, members, schema */
$groupKey = $view['group_by'] ?? null;
$subKey = $view['sub_group_by'] ?? null;
$ncol = count($cols);
$render = static function (array $r) use ($cols, $d, $may, $here, $view, $members): string { return view('databases/partials/row-row.php', ['row' => $r, 'cols' => $cols, 'd' => $d, 'may' => $may, 'here' => $here, 'view' => $view, 'members' => $members]); };
?>
<div class="card" id="database-rows"><div class="table-responsive sp-table-wrap"><table class="table table-sm align-middle mb-0 sp-db-table<?= $view['wrap'] ? ' sp-wrap' : '' ?>" id="database-table">
    <thead><tr>
        <?php foreach ($cols as $key => $def): ?><th scope="col" class="<?= $key === $d['title_property_key'] ? 'sp-sticky ' : '' ?>text-nowrap fs-12 text-muted fw-semibold" id="database-col-<?= kid($key) ?>"><?= e($def['name'] ?? $key) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody id="database-rows-body">
    <?php if ($rows === []): ?><tr><td colspan="<?= max(1, $ncol) ?>" class="text-muted p-3" id="database-empty"><?= $may['edit'] ? 'No rows yet — add the first with New row.' : 'No rows yet.' ?></td></tr><?php endif; ?>
    <?php if ($groupKey !== null && isset($schema[$groupKey]) && $rows !== []): ?>
        <?php foreach (group_rows($rows, $groupKey, $schema[$groupKey]) as $g): ?>
            <tr class="sp-group-row" id="table-group-<?= e($g['slug']) ?>"><th colspan="<?= max(1, $ncol) ?>" class="fs-13"><?= $g['color'] !== null || $g['value'] !== '' ? render_chip($g['label'], $g['color']) : '<span class="text-muted">' . e($g['label']) . '</span>' ?> <span class="text-muted fs-12 ms-1"><?= count($g['rows']) ?></span></th></tr>
            <?php if ($subKey !== null && isset($schema[$subKey])): ?>
                <?php foreach (group_rows($g['rows'], $subKey, $schema[$subKey], false, 'sub_group_value') as $sg): ?>
                    <tr class="sp-subgroup-row" id="table-group-<?= e($g['slug']) ?>-<?= e($sg['slug']) ?>"><th colspan="<?= max(1, $ncol) ?>" class="fs-12 ps-4 text-muted"><?= e($sg['label']) ?> <span class="ms-1"><?= count($sg['rows']) ?></span></th></tr>
                    <?php foreach ($sg['rows'] as $r) { echo $render($r); } ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($g['rows'] as $r) { echo $render($r); } ?>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <?php foreach ($rows as $r) { echo $render($r); } ?>
    <?php endif; ?>
    </tbody>
</table></div>
<div class="card-footer d-flex justify-content-between align-items-center fs-12 text-muted" id="database-rows-foot">
    <span><?= count($rows) ?> of <?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($total > count($rows)): ?><?= hx_link(preg_replace('/[?&]limit=\d+/', '', $here) . (str_contains(preg_replace('/[?&]limit=\d+/', '', $here), '?') ? '&' : '?') . 'limit=' . ($limit + 100), 'Load more', 'btn btn-light btn-touch', 'id="database-load-more"') ?><?php endif; ?>
</div></div>
