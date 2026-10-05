<?php
/**
 * The filter editor (id filter-editor): rows of property · condition · value joined by and/or, one nested level. It posts f[n], fj and g[i]; the server builds Notion's filter object from them
 * (the hidden `filter` keeps its JSON, for a filter more nested than this draws). Data: schema, ff (filter_to_form()), raw (the stored filter as JSON text|null)
 */
$allConds = [];
foreach (['title', 'number', 'select', 'multi_select', 'date', 'checkbox', 'people', 'rollup'] as $t) {
    foreach (property_conditions($t) as $c => $lbl) { $allConds[$c] = $allConds[$c] ?? ['label' => $lbl, 'types' => []]; }
}
foreach (array_keys($schema) as $k) {
    $t = (string) $schema[$k]['type'];
    foreach (property_conditions($t) as $c => $lbl) { $allConds[$c] ??= ['label' => $lbl, 'types' => []]; $allConds[$c]['types'][$t] = true; }
}
$row = static function (string $prefix, int $n, array $r) use ($schema, $allConds): string {
    $h = '<div class="sp-filter-row row g-2 align-items-center mb-2" id="filter-row-' . e(str_replace(['[', ']'], ['-', ''], $prefix)) . $n . '">';
    $h .= '<div class="col-12 col-md-4"><select name="' . e($prefix) . '[' . $n . '][property]" class="form-select btn-touch sp-filter-prop" aria-label="Property"><option value="">—</option>';
    foreach ($schema as $k => $def) { $h .= '<option value="' . e($k) . '" data-type="' . e($def['type']) . '"' . (($r['property'] ?? '') === $k ? ' selected' : '') . '>' . e($def['name'] ?? $k) . '</option>'; }
    $h .= '</select></div><div class="col-12 col-md-4"><select name="' . e($prefix) . '[' . $n . '][condition]" class="form-select btn-touch sp-filter-cond" aria-label="Condition"><option value="">—</option>';
    foreach ($allConds as $c => $info) { $h .= '<option value="' . e($c) . '" data-types="' . e(implode(' ', array_keys($info['types']))) . '"' . (($r['condition'] ?? '') === $c ? ' selected' : '') . '>' . e($info['label']) . '</option>'; }
    $h .= '</select></div><div class="col-12 col-md-4"><input type="text" name="' . e($prefix) . '[' . $n . '][value]" class="form-control btn-touch sp-filter-value" aria-label="Value" value="' . e($r['value'] ?? '') . '"></div></div>';
    return $h;
};
$topRows = $ff['rows'];
$groups = $ff['groups'];
?>
<div id="filter-editor">
<?php if ($ff['complex']): ?><div class="alert alert-warning fs-12" id="filter-editor-complex">This filter nests deeper than the editor draws. What it shows is part of it; saving here replaces it with what you see. To keep it as it is, leave these rows alone and use the JSON below.</div><?php endif; ?>
    <div class="d-flex align-items-center gap-2 mb-2"><label class="fs-12 text-muted mb-0" for="filter-join">Match</label>
        <select name="fj" id="filter-join" class="form-select btn-touch" style="max-width: 10rem"><option value="and" <?= $ff['join'] === 'and' ? 'selected' : '' ?>>all of these</option><option value="or" <?= $ff['join'] === 'or' ? 'selected' : '' ?>>any of these</option></select></div>
    <div id="filter-rows">
        <?php $n = 0; foreach ($topRows as $r) { echo $row('f', $n++, $r); } for ($i = 0; $i < 2; $i++) { echo $row('f', $n++, []); } ?>
    </div>
    <button type="button" class="btn btn-light btn-touch mb-3 sp-filter-add" id="filter-add-row" hidden>Add a condition</button>
    <?php $gi = 0; foreach (array_merge($groups, count($groups) < 2 ? [['join' => 'and', 'rows' => []]] : []) as $g): ?>
        <fieldset class="border rounded p-2 mb-2 sp-filter-group" id="filter-group-<?= $gi ?>">
            <legend class="fs-12 text-muted float-none w-auto px-1 mb-1">A group</legend>
            <div class="d-flex align-items-center gap-2 mb-2"><label class="fs-12 text-muted mb-0" for="filter-group-<?= $gi ?>-join">Match</label>
                <select name="g[<?= $gi ?>][join]" id="filter-group-<?= $gi ?>-join" class="form-select btn-touch" style="max-width: 10rem"><option value="and" <?= $g['join'] === 'and' ? 'selected' : '' ?>>all of these</option><option value="or" <?= $g['join'] === 'or' ? 'selected' : '' ?>>any of these</option></select></div>
            <?php $m = 0; foreach ($g['rows'] as $r) { echo $row('g[' . $gi . '][rows]', $m++, $r); } for ($i = 0; $i < 2; $i++) { echo $row('g[' . $gi . '][rows]', $m++, []); } ?>
        </fieldset>
    <?php $gi++; endforeach; ?>
    <input type="hidden" name="filter" id="view-form-field-filter" value="<?= e($raw ?? '') ?>">
</div>
