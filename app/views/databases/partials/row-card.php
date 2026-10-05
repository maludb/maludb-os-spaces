<?php
/** A row as a card (board, gallery and list). Data: row, cols, d, here, mode (board|gallery|list), cover (an attachment id or null), mime (its mime type), may */
$rid = (string) $row['row_id'];
$lines = card_lines($cols, $row['properties'], $d['title_property_key'], $here, $d['database_id']);
$title = display_value('title', $row['properties'][$d['title_property_key']] ?? []) ?: ($row['title'] !== '' ? $row['title'] : 'Untitled');
?>
<?php if ($mode === 'list'): ?>
<div class="list-group-item d-flex flex-wrap align-items-center gap-2 py-2" id="row-card-<?= e($rid) ?>" data-row="<?= e($rid) ?>">
    <span class="fw-semibold"><?= ($row['icon'] ?? '') !== '' ? e($row['icon']) . ' ' : '' ?><?= hx_link(row_url($d['database_id'], $rid, $here), e($title), 'text-dark') ?></span>
    <?php foreach ($lines as [$n, $html, $t]): ?><span class="fs-12 text-muted" title="<?= e($n) ?>"><?= $html ?></span><?php endforeach; ?>
</div>
<?php else: ?>
<div class="card sp-card sp-card-<?= e($mode) ?>" id="row-card-<?= e($rid) ?>" data-row="<?= e($rid) ?>">
    <?php if ($mode === 'gallery' && $cover !== null): ?>
        <a href="<?= e(row_url($d['database_id'], $rid, $here)) ?>" class="sp-card-cover"><?php if (str_starts_with((string) $mime, 'image/')): ?><img src="/files/<?= (int) $cover ?>/thumb" alt="" loading="lazy"><?php else: ?><span class="sp-card-cover-file"><i class="feather-file"></i></span><?php endif; ?></a>
    <?php elseif ($mode === 'gallery'): ?>
        <a href="<?= e(row_url($d['database_id'], $rid, $here)) ?>" class="sp-card-cover sp-card-cover-empty" aria-hidden="true" tabindex="-1"><?= ($row['icon'] ?? '') !== '' ? e($row['icon']) : '▦' ?></a>
    <?php endif; ?>
    <div class="card-body p-2">
        <div class="fw-semibold text-break"><?= $mode !== 'gallery' && ($row['icon'] ?? '') !== '' ? e($row['icon']) . ' ' : '' ?><?= hx_link(row_url($d['database_id'], $rid, $here), e($title), 'text-dark') ?></div>
        <?php foreach ($lines as [$n, $html, $t]): ?><div class="fs-12 mt-1"><span class="text-muted"><?= e($n) ?>:</span> <?= $html ?></div><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
