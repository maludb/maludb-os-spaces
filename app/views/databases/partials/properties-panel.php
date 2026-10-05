<?php
/**
 * The properties panel (id properties-panel): one line per property in schema order, each editable in place by its type; rollups and the computed ones read-only.
 * mode `panel` = a row's lines, each its own small form (row_update with ONE property; HTMX re-renders the panel); mode `create` = ONE form (row_create) with every settable property.
 * Data: d (the database), schema, values (resolved), row (the row page or null), may (edit_content), here, members, mode, title (the row's title)
 */
$create = $mode === 'create';
$rid = $row['page_id'] ?? '';
$return = $create ? '/databases/' . $d['database_id'] : '/databases/' . $d['database_id'] . '/rows/' . $rid;
$settable = static fn (array $def): bool => !in_array((string) $def['type'], COMPUTED_PROPERTY_TYPES, true) && !in_array((string) $def['type'], ['files', 'relation'], true);
?>
<section class="card mb-3" id="properties-panel"<?= $create ? ' data-mode="create"' : '' ?>>
<?php if ($create): ?>
<form method="post" action="/databases/rows/save.php" hx-post="/databases/rows/save.php" hx-target="#flash" id="row-form">
    <?= csrf_field() ?><input type="hidden" name="database" value="<?= e($d['database_id']) ?>">
<?php endif; ?>
<div class="card-body p-3 sp-props">
    <?php foreach ($schema as $key => $def):
        $type = (string) $def['type']; $val = $values[$key] ?? null; $fid = 'property-field-' . kid($key); ?>
        <div class="sp-prop-line" id="property-line-<?= kid($key) ?>">
            <div class="sp-prop-name"><label for="<?= e($fid) ?>-input"><?= e($def['name'] ?? $key) ?></label> <?= type_chip($type) ?></div>
            <div class="sp-prop-value" id="<?= e($fid) ?>">
            <?php if ($create): ?>
                <?php if ($settable($def)): ?>
                    <?= view('databases/partials/field.php', ['key' => $key, 'def' => $def, 'value' => null, 'id' => $fid . '-input', 'mode' => 'create', 'members' => $members]) ?>
                <?php else: ?><span class="text-muted fs-12"><?= $type === 'files' ? 'Attach files after the row is made.' : ($type === 'relation' ? 'Link rows after the row is made.' : 'Computed.') ?></span><?php endif; ?>
            <?php elseif ($type === 'title' && $may): ?>
                <form method="post" action="/databases/rows/save.php" hx-post="/databases/rows/save.php" hx-trigger="change" hx-target="#properties-panel" hx-swap="outerHTML" class="sp-prop-form">
                    <?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="render" value="panel"><input type="hidden" name="return_to" value="<?= e($return) ?>">
                    <?= view('databases/partials/field.php', ['key' => $key, 'def' => $def, 'value' => $values[$key] ?? [], 'id' => $fid . '-input', 'mode' => 'panel', 'members' => $members]) ?>
                    <noscript><button type="submit" class="btn btn-light btn-sm">Save</button></noscript>
                </form>
            <?php elseif ($type === 'relation'): ?>
                <?= render_value('relation', $def, $val, null, $here, $d['database_id']) ?: '<span class="text-muted">None</span>' ?>
                <?php if ($may): ?> <?= hx_link(with_back('/databases/rows/relation.php?row=' . $rid . '&property=' . rawurlencode($key), $here), '<i class="feather-link-2 me-1"></i>Choose', 'btn btn-light btn-sm btn-touch ms-1', 'id="' . e($fid) . '-choose"') ?><?php endif; ?>
            <?php elseif ($type === 'files'): ?>
                <?php foreach ((array) $val as $f): ?>
                    <span class="d-inline-flex align-items-center gap-1 me-2"><a href="/files/<?= (int) $f['id'] ?>"><?= e($f['filename']) ?></a>
                    <?php if ($may): ?><form method="post" action="/databases/rows/save.php" class="d-inline" hx-post="/databases/rows/save.php" hx-target="#properties-panel" hx-swap="outerHTML"><?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="render" value="panel"><input type="hidden" name="return_to" value="<?= e($return) ?>">
                        <input type="hidden" name="p[<?= e($key) ?>][]" value=""><?php foreach ((array) $val as $g) { if ($g['id'] !== $f['id']) { echo '<input type="hidden" name="p[' . e($key) . '][]" value="' . (int) $g['id'] . '">'; } } ?>
                        <button type="submit" class="btn btn-light btn-sm sp-icon-btn" aria-label="Remove <?= e($f['filename']) ?>"><i class="feather-x"></i></button></form><?php endif; ?></span>
                <?php endforeach; ?>
                <?php if ((array) $val === []): ?><span class="text-muted">None</span><?php endif; ?>
                <?php if ($may): ?>
                <form method="post" action="/files/upload.php" enctype="multipart/form-data" class="mt-1 d-flex gap-2 align-items-center flex-wrap" id="<?= e($fid) ?>-upload"><?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="property" value="<?= e($key) ?>">
                    <input type="file" name="file" class="form-control btn-touch" style="max-width: 18rem" aria-label="Attach a file to <?= e($def['name'] ?? $key) ?>" required><button type="submit" class="btn btn-light btn-touch">Attach</button></form>
                <?php endif; ?>
            <?php elseif (in_array($type, COMPUTED_PROPERTY_TYPES, true) || !$may): ?>
                <?= render_value($type, $def, $val, null, $here, $d['database_id']) ?: '<span class="text-muted">—</span>' ?>
            <?php else: ?>
                <form method="post" action="/databases/rows/save.php" hx-post="/databases/rows/save.php" hx-trigger="<?= in_array($type, ['multi_select', 'people'], true) ? 'submit' : 'change' ?>" hx-target="#properties-panel" hx-swap="outerHTML" class="sp-prop-form">
                    <?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="render" value="panel"><input type="hidden" name="return_to" value="<?= e($return) ?>">
                    <?= view('databases/partials/field.php', ['key' => $key, 'def' => $def, 'value' => $val, 'id' => $fid . '-input', 'mode' => 'panel', 'members' => $members]) ?>
                    <noscript><button type="submit" class="btn btn-light btn-sm">Save</button></noscript>
                </form>
            <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($create): ?>
    <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="row-form-save-btn">Add the row</button></div>
</form>
<?php endif; ?>
</section>
