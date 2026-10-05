<?php
/**
 * Add a view / change a view (screens `view-add`, `view-edit`). Data: d, schema, cur (the view or null), homes (pages a linked view may sit on), ff, raw, here
 */
$did = $d['database_id'];
$vid = $cur['view_id'] ?? null;
$screen = $cur === null ? 'view-add' : 'view-edit';
$back = $vid === null ? ['/databases/' . $did, $d['plain_title'] ?: 'the database'] : ['/databases/' . $did . '?view=' . $vid, $d['plain_title'] ?: 'the database'];
$v = $cur ?? ['name' => '', 'layout' => 'table', 'filter' => null, 'sort' => [], 'group_by' => null, 'sub_group_by' => null, 'visible_properties' => null, 'calendar_by' => null, 'timeline_start' => null, 'timeline_end' => null, 'card_size' => 'medium', 'card_cover' => null, 'wrap' => false, 'linked_from_page_id' => null];
$dates = array_filter($schema, static fn (array $x): bool => in_array($x['type'], ['date', 'created_time', 'last_edited_time'], true));
$files = array_filter($schema, static fn (array $x): bool => $x['type'] === 'files');
$sort = $v['sort'];
$vp = $v['visible_properties'];
$sel = static fn (array $opts, ?string $cur, string $none = '—'): string => '<option value="">' . e($none) . '</option>' . implode('', array_map(static fn (string $k): string => '<option value="' . e($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . e($opts[$k]['name'] ?? $k) . '</option>', array_keys($opts)));
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New view' : 'Change ' . $v['name'], 'crumbs' => [['Home', '/'], ['Databases', '/databases/'], [$d['plain_title'] ?: 'Untitled', '/databases/' . $did], [$cur === null ? 'New view' : $v['name'], null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/databases/views/save.php" hx-post="/databases/views/save.php" hx-target="#flash" id="view-form">
        <?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><?php if ($vid !== null): ?><input type="hidden" name="view" value="<?= e($vid) ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The view</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="view-form-field-name">Name</label><input type="text" name="name" id="view-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($v['name']) ?>">
            <label class="form-label fs-12 text-muted" for="view-form-field-layout">Layout</label>
            <select name="layout" id="view-form-field-layout" class="form-select btn-touch mb-2"><?php foreach (['table' => 'Table', 'board' => 'Board (needs a group property)', 'gallery' => 'Gallery', 'list' => 'List', 'calendar' => 'Calendar (needs a date)', 'timeline' => 'Timeline (needs a start date)'] as $l => $lbl): ?><option value="<?= e($l) ?>" <?= $v['layout'] === $l ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
            <div class="fs-12 text-muted mb-3">A calendar places rows by a date; a timeline draws a bar from a start to an end; a board has a column for each value of the property it groups by.</div>
            <label class="form-label fs-12 text-muted" for="view-form-field-calendar_by">Calendar by (a date property)</label><select name="calendar_by" id="view-form-field-calendar_by" class="form-select btn-touch mb-3"><?= $sel($dates, $v['calendar_by']) ?></select>
            <div class="row g-2 mb-1"><div class="col-6"><label class="form-label fs-12 text-muted" for="view-form-field-timeline_start">Timeline starts</label><select name="timeline_start" id="view-form-field-timeline_start" class="form-select btn-touch"><?= $sel($dates, $v['timeline_start']) ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="view-form-field-timeline_end">Timeline ends</label><select name="timeline_end" id="view-form-field-timeline_end" class="form-select btn-touch"><?= $sel($dates, $v['timeline_end']) ?></select></div></div>
        </div></div>
        <div class="card mb-3" id="view-form-filter"><div class="card-header"><h5 class="card-title mb-0">Filter</h5></div><div class="card-body">
            <?= view('databases/partials/filter-editor.php', ['schema' => $schema, 'ff' => $ff, 'raw' => $raw]) ?>
        </div></div>
        <div class="card mb-3" id="view-form-sort"><div class="card-header"><h5 class="card-title mb-0">Sort</h5></div><div class="card-body">
            <?php for ($i = 0; $i < 3; $i++): $s = $sort[$i] ?? ['property' => '', 'direction' => 'ascending']; ?>
                <div class="row g-2 mb-2" id="view-form-sort-<?= $i ?>"><div class="col-8"><select name="sort[<?= $i ?>][property]" class="form-select btn-touch" aria-label="Sort <?= $i + 1 ?> property"><?= $sel($schema, (string) $s['property']) ?></select></div>
                    <div class="col-4"><select name="sort[<?= $i ?>][direction]" class="form-select btn-touch" aria-label="Sort <?= $i + 1 ?> direction"><option value="ascending" <?= ($s['direction'] ?? '') !== 'descending' ? 'selected' : '' ?>>Ascending</option><option value="descending" <?= ($s['direction'] ?? '') === 'descending' ? 'selected' : '' ?>>Descending</option></select></div></div>
            <?php endfor; ?>
        </div></div>
        <div class="card mb-3" id="view-form-group"><div class="card-header"><h5 class="card-title mb-0">Group</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="view-form-field-group_by">Group by</label><select name="group_by" id="view-form-field-group_by" class="form-select btn-touch mb-3"><?= $sel($schema, $v['group_by']) ?></select>
            <label class="form-label fs-12 text-muted" for="view-form-field-sub_group_by">Then by (a table's sub-group)</label><select name="sub_group_by" id="view-form-field-sub_group_by" class="form-select btn-touch"><?= $sel($schema, $v['sub_group_by']) ?></select>
        </div></div>
        <div class="card mb-3" id="view-form-properties"><div class="card-header"><h5 class="card-title mb-0">Properties shown</h5></div><div class="card-body">
            <input type="hidden" name="visible_properties[]" value="<?= e($d['title_property_key']) ?>">
            <?php foreach ($schema as $k => $def): if ($k === $d['title_property_key']) { continue; } ?>
                <label class="d-flex align-items-center gap-2 btn-touch" for="view-form-prop-<?= kid($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="visible_properties[]" id="view-form-prop-<?= kid($k) ?>" value="<?= e($k) ?>" <?= $vp === null || in_array($k, $vp, true) ? 'checked' : '' ?>><?= e($def['name'] ?? $k) ?></label>
            <?php endforeach; ?>
        </div></div>
        <div class="card mb-3" id="view-form-cards"><div class="card-header"><h5 class="card-title mb-0">Cards and wrapping</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="view-form-field-card_size">Card size</label><select name="card_size" id="view-form-field-card_size" class="form-select btn-touch mb-3"><?php foreach (['small', 'medium', 'large'] as $z): ?><option value="<?= $z ?>" <?= ($v['card_size'] ?? 'medium') === $z ? 'selected' : '' ?>><?= ucfirst($z) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="view-form-field-card_cover">Card cover</label><select name="card_cover" id="view-form-field-card_cover" class="form-select btn-touch mb-3"><option value="">The page cover</option><option value="page_icon" <?= $v['card_cover'] === 'page_icon' ? 'selected' : '' ?>>The page icon</option><?php foreach ($files as $k => $x): ?><option value="<?= e($k) ?>" <?= $v['card_cover'] === $k ? 'selected' : '' ?>>The first file of <?= e($x['name'] ?? $k) ?></option><?php endforeach; ?></select>
            <input type="hidden" name="wrap" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="view-form-field-wrap"><input type="checkbox" class="form-check-input mt-0" name="wrap" id="view-form-field-wrap" value="yes" <?= $v['wrap'] ? 'checked' : '' ?>>Wrap long text in the table</label>
        </div></div>
        <div class="card mb-3" id="view-form-linked"><div class="card-header"><h5 class="card-title mb-0">On another page</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="view-form-field-linked_from">Show this view on a page (read-only, as a linked view)</label>
            <select name="linked_from" id="view-form-field-linked_from" class="form-select btn-touch"><option value="">Not on a page</option><?php foreach ($homes as $p): ?><option value="<?= e($p['page_id']) ?>" <?= ($v['linked_from_page_id'] ?? null) === $p['page_id'] ? 'selected' : '' ?>><?= e($p['plain_title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="view-form-save-btn"><?= $cur === null ? 'Make the view' : 'Save the view' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="view-form-cancel-link"') ?>
    </form>
</div>
<link rel="stylesheet" href="/assets/css/databases.css">
<script src="/assets/js/databases.js"></script>
