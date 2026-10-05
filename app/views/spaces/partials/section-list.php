<?php /** The sections in order with their root pages — the swapped region (space-sections). Data: s, sections, loose, here */ $id = (int) $s['space_id'];
$sectionOptions = static function (?int $current) use ($sections): string { $o = '<option value="">No section</option>'; foreach ($sections as $x) { $o .= '<option value="' . (int) $x['section_id'] . '"' . ($current === (int) $x['section_id'] ? ' selected' : '') . '>' . e($x['name']) . '</option>'; } return $o; };
$pageRow = static function (array $p) use ($id, $sectionOptions): string {
    $pid = (string) $p['page_id'];
    return '<li class="list-group-item d-flex align-items-center gap-2 flex-wrap" id="section-page-' . e($pid) . '"><span class="flex-grow-1 min-w-0 text-truncate">' . e(($p['icon'] ?? '') !== '' ? $p['icon'] . ' ' : '▫ ') . e($p['title'] !== '' ? $p['title'] : 'Untitled') . '</span>'
        . '<form method="post" action="/spaces/sections/assign.php" hx-post="/spaces/sections/assign.php" hx-target="#flash" hx-trigger="change" class="d-flex gap-1">' . csrf_field() . '<input type="hidden" name="page" value="' . e($pid) . '"><input type="hidden" name="return_to" value="/spaces/' . $id . '/sections">'
        . '<select name="section" class="form-select form-select-sm btn-touch" aria-label="Section" id="section-page-' . e($pid) . '-select">' . $sectionOptions($p['section_id'] === null ? null : (int) $p['section_id']) . '</select><noscript><button type="submit" class="btn btn-light btn-sm">Move</button></noscript></form></li>';
}; ?>
<div id="section-list">
    <ul class="list-group mb-3" id="section-sortable">
        <?php if ($sections === []): ?><li class="list-group-item text-muted fs-12" id="section-list-empty">No section yet. Pages sit at the root until you make one.</li><?php endif; ?>
        <?php foreach ($sections as $x): $xid = (int) $x['section_id']; ?>
        <li class="list-group-item" id="section-row-<?= $xid ?>" data-section="<?= $xid ?>">
            <div class="d-flex align-items-center gap-2">
                <span class="sp-drag-handle text-muted" title="Drag to reorder" aria-hidden="true"><i class="feather-move"></i></span>
                <form method="post" action="/spaces/sections/save.php" hx-post="/spaces/sections/save.php" hx-target="#flash" class="d-flex gap-1 flex-grow-1" id="section-row-<?= $xid ?>-rename">
                    <?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="section" value="<?= $xid ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/sections">
                    <input type="text" name="name" class="form-control btn-touch fw-semibold" maxlength="60" value="<?= e($x['name']) ?>" aria-label="Section name" id="section-row-<?= $xid ?>-name">
                    <button type="submit" class="btn btn-light btn-touch" id="section-row-<?= $xid ?>-rename-btn">Rename</button>
                </form>
                <form method="post" action="/spaces/sections/delete.php" hx-post="/spaces/sections/delete.php" hx-target="#flash" hx-confirm="Delete the section <?= e($x['name']) ?>? Its <?= (int) $x['page_count'] ?> page<?= (int) $x['page_count'] === 1 ? '' : 's' ?> go to the root."><?= csrf_field() ?><input type="hidden" name="section" value="<?= $xid ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/sections"><button type="submit" class="btn btn-light btn-touch text-danger" id="section-row-<?= $xid ?>-delete-btn" aria-label="Delete <?= e($x['name']) ?>"><i class="feather-trash-2"></i></button></form>
            </div>
            <ul class="list-group list-group-flush mt-2 ms-4" id="section-row-<?= $xid ?>-pages">
                <?php if ($x['pages'] === []): ?><li class="list-group-item text-muted fs-12">No page in it.</li><?php endif; ?>
                <?php foreach ($x['pages'] as $p) { echo $pageRow($p); } ?>
            </ul>
        </li>
        <?php endforeach; ?>
    </ul>
    <h6 class="fw-bold" id="section-loose-title">Pages with no section</h6>
    <ul class="list-group mb-3" id="section-loose">
        <?php if ($loose === []): ?><li class="list-group-item text-muted fs-12" id="section-loose-empty">None.</li><?php endif; ?>
        <?php foreach ($loose as $p) { echo $pageRow($p); } ?>
    </ul>
</div>
