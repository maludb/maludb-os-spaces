<?php
/**
 * The view tabs (id database-views) and the toolbar (id database-toolbar): New row, search, filter / sort / group (the view's own form), properties (hide or show), Views (add, edit, reorder,
 * delete) and Schema. Data: d, views, view, may, here, q, schema, members, base (the view's URL)
 */
$own = array_values(array_filter($views, static fn (array $v): bool => $v['linked_from_page_id'] === null));
$linked = array_values(array_filter($views, static fn (array $v): bool => $v['linked_from_page_id'] !== null));
$did = $d['database_id'];
$nf = $view['filter'] === null ? 0 : (isset($view['filter']['and']) ? count($view['filter']['and']) : (isset($view['filter']['or']) ? count($view['filter']['or']) : 1));
$ns = count($view['sort']);
$vp = $view['visible_properties'];
?>
<nav class="sp-view-tabs mb-3" id="database-views" aria-label="Views">
    <?php foreach ($own as $v): ?><?= hx_link('/databases/' . $did . '?view=' . $v['view_id'], e($v['name']), 'btn btn-touch ' . ($v['view_id'] === $view['view_id'] ? 'btn-primary' : 'btn-light'), 'id="view-tab-' . e($v['view_id']) . '"' . ($v['view_id'] === $view['view_id'] ? ' aria-current="page"' : '')) ?><?php endforeach; ?>
    <?php if ($view['linked_from_page_id'] !== null): ?><span class="btn btn-touch btn-primary disabled" id="view-tab-<?= e($view['view_id']) ?>"><?= e($view['name']) ?> <small>(linked)</small></span><?php endif; ?>
    <?php if ($may['schema']): ?><?= hx_link('/databases/' . $did . '/views/new', '<i class="feather-plus me-1"></i>View', 'btn btn-touch btn-light', 'id="view-add-link"') ?><?php endif; ?>
</nav>
<div class="sp-toolbar mb-3" id="database-toolbar">
    <?php if ($may['edit']): ?>
    <details class="sp-newrow" id="database-new-row">
        <summary class="btn btn-primary btn-touch" id="database-new-row-btn"><i class="feather-plus me-1"></i>New row</summary>
        <div class="mt-2"><?= view('databases/partials/properties-panel.php', ['d' => $d, 'schema' => $schema, 'values' => [], 'row' => null, 'may' => true, 'here' => $here, 'members' => $members, 'mode' => 'create']) ?></div>
    </details>
    <?php endif; ?>
    <form method="get" action="/databases/<?= e($did) ?>" class="d-flex gap-1" id="database-search" hx-get="/databases/<?= e($did) ?>" hx-target="#page-content" hx-push-url="true" hx-trigger="submit">
        <input type="hidden" name="view" value="<?= e($view['view_id']) ?>">
        <input type="search" name="q" class="form-control btn-touch" style="max-width: 11rem" value="<?= e($q) ?>" placeholder="Search" aria-label="Search the rows">
    </form>
    <?php if ($may['schema']): ?>
        <?= hx_link('/databases/' . $did . '/views/' . $view['view_id'] . '/edit#view-form-filter', '<i class="feather-filter me-1"></i>Filter' . ($nf > 0 ? ' · ' . $nf : ''), 'btn btn-touch btn-light', 'id="database-toolbar-filter"') ?>
        <?= hx_link('/databases/' . $did . '/views/' . $view['view_id'] . '/edit#view-form-sort', '<i class="feather-sliders me-1"></i>Sort' . ($ns > 0 ? ' · ' . $ns : ''), 'btn btn-touch btn-light', 'id="database-toolbar-sort"') ?>
        <?= hx_link('/databases/' . $did . '/views/' . $view['view_id'] . '/edit#view-form-group', '<i class="feather-layers me-1"></i>Group' . ($view['group_by'] !== null ? ' · 1' : ''), 'btn btn-touch btn-light', 'id="database-toolbar-group"') ?>
        <details class="sp-pop" id="database-toolbar-properties">
            <summary class="btn btn-touch btn-light"><i class="feather-eye me-1"></i>Properties</summary>
            <form method="post" action="/databases/views/save.php" hx-post="/databases/views/save.php" hx-target="#flash" class="sp-pop-body card shadow p-2" id="database-properties-form">
                <?= csrf_field() ?><input type="hidden" name="view" value="<?= e($view['view_id']) ?>"><input type="hidden" name="return_to" value="<?= e($base) ?>">
                <input type="hidden" name="visible_properties[]" value="<?= e($d['title_property_key']) ?>">
                <?php foreach ($schema as $k => $def): if ($k === $d['title_property_key']) { continue; } ?>
                    <label class="d-flex align-items-center gap-2 btn-touch px-1"><input type="checkbox" class="form-check-input mt-0" name="visible_properties[]" value="<?= e($k) ?>" <?= $vp === null || in_array($k, $vp, true) ? 'checked' : '' ?>><?= e($def['name'] ?? $k) ?></label>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary btn-touch w-100 mt-1" id="database-properties-save">Apply</button>
            </form>
        </details>
    <?php endif; ?>
    <?php if ($may['schema']): ?>
        <details class="sp-pop" id="database-toolbar-views">
            <summary class="btn btn-touch btn-light"><i class="feather-layout me-1"></i>Views</summary>
            <div class="sp-pop-body card shadow p-2" id="database-views-manage">
                <?php foreach ($views as $i => $v):
                    $prevOwn = null; foreach ($own as $j => $o) { if ($o['view_id'] === $v['view_id']) { $prevOwn = $j; } } ?>
                    <div class="d-flex align-items-center gap-1 py-1 border-bottom" id="view-row-<?= e($v['view_id']) ?>">
                        <span class="flex-grow-1 text-truncate"><?= e($v['name']) ?> <span class="badge bg-light text-dark"><?= e($v['layout']) ?></span><?= $v['linked_from_page_id'] !== null ? ' <span class="badge bg-soft-info text-info">linked</span>' : '' ?></span>
                        <?= hx_link('/databases/' . $did . '/views/' . $v['view_id'] . '/edit', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="view-edit-' . e($v['view_id']) . '"') ?>
                        <?php if ($prevOwn !== null && count($own) > 1): ?>
                            <?php if ($prevOwn > 0): ?><form method="post" action="/databases/views/reorder.php" hx-post="/databases/views/reorder.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="view" value="<?= e($v['view_id']) ?>"><input type="hidden" name="after" value="<?= $prevOwn > 1 ? e($own[$prevOwn - 2]['view_id']) : '' ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" aria-label="Move <?= e($v['name']) ?> earlier" id="view-up-<?= e($v['view_id']) ?>"><i class="feather-arrow-up"></i></button></form><?php endif; ?>
                            <?php if ($prevOwn < count($own) - 1): ?><form method="post" action="/databases/views/reorder.php" hx-post="/databases/views/reorder.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="view" value="<?= e($v['view_id']) ?>"><input type="hidden" name="after" value="<?= e($own[$prevOwn + 1]['view_id']) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" aria-label="Move <?= e($v['name']) ?> later" id="view-down-<?= e($v['view_id']) ?>"><i class="feather-arrow-down"></i></button></form><?php endif; ?>
                        <?php endif; ?>
                        <details class="d-inline"><summary class="btn btn-light btn-sm btn-touch" aria-label="Delete <?= e($v['name']) ?>"><i class="feather-trash-2"></i></summary>
                            <form method="post" action="/databases/views/delete.php" hx-post="/databases/views/delete.php" hx-target="#flash" class="sp-pop-body card shadow p-2"><?= csrf_field() ?><input type="hidden" name="view" value="<?= e($v['view_id']) ?>"><button type="submit" class="btn btn-danger btn-touch w-100" id="view-delete-<?= e($v['view_id']) ?>">Delete "<?= e($v['name']) ?>"</button></form></details>
                    </div>
                <?php endforeach; ?>
                <?= hx_link('/databases/' . $did . '/views/new', '<i class="feather-plus me-1"></i>Add a view', 'btn btn-light btn-touch w-100 mt-2', 'id="view-add-link-2"') ?>
            </div>
        </details>
    <?php endif; ?>
    <?= hx_link('/databases/' . $did . '/schema', '<i class="feather-settings me-1"></i>Schema', 'btn btn-touch btn-light', 'id="database-toolbar-schema"') ?>
</div>
