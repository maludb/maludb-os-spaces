<?php
/**
 * One table cell (id cell-{row}-{key}). Data: row (row_id, properties resolved), key, def, d (the database), may (edit), here, view, members.
 * Computed properties, relations (they open the picker), files and the title are read-only here; the rest is a small form posting row_update with ONE property
 * (HTMX re-renders the row; with JavaScript off the form posts and lands on the row's page).
 */
$type = (string) $def['type'];
$rid = (string) $row['row_id'];
$val = $row['properties'][$key] ?? null;
$cid = 'cell-' . $rid . '-' . kid($key);
$editable = $may['edit'] && !in_array($type, COMPUTED_PROPERTY_TYPES, true) && !in_array($type, ['title', 'files', 'relation'], true);
$popover = in_array($type, ['multi_select', 'people', 'date'], true);
?>
<td id="<?= e($cid) ?>" class="sp-cell sp-cell-<?= e($type) ?>" data-key="<?= e($key) ?>">
<?php if ($type === 'title'): ?>
    <?= ($row['icon'] ?? '') !== '' ? '<span class="me-1">' . e($row['icon']) . '</span>' : '' ?><?= render_value('title', $def, ($row['properties'][$key] ?? []) ?: [['plain_text' => '']], $rid, $here, $d['database_id']) ?>
<?php elseif ($type === 'relation'): ?>
    <?= render_value('relation', $def, $val, $rid, $here, $d['database_id']) ?>
    <?php if ($may['edit']): ?> <?= hx_link(with_back('/databases/rows/relation.php?row=' . $rid . '&property=' . rawurlencode($key), $here), '<i class="feather-link-2"></i>', 'btn btn-light btn-sm sp-icon-btn', 'aria-label="Link ' . e($def['name'] ?? $key) . '" id="cell-' . e($rid) . '-' . kid($key) . '-link"') ?><?php endif; ?>
<?php elseif ($editable): ?>
    <form method="post" action="/databases/rows/save.php" class="sp-cell-form" hx-post="/databases/rows/save.php" hx-trigger="<?= $popover ? 'submit' : 'change' ?>" hx-target="#row-row-<?= e($rid) ?>" hx-swap="outerHTML" id="cell-form-<?= e($rid) ?>-<?= kid($key) ?>">
        <?= csrf_field() ?><input type="hidden" name="row" value="<?= e($rid) ?>"><input type="hidden" name="render" value="row"><input type="hidden" name="view" value="<?= e($view['view_id'] ?? '') ?>"><input type="hidden" name="here" value="<?= e($here) ?>"><input type="hidden" name="return_to" value="<?= e('/databases/' . $d['database_id'] . '/rows/' . $rid) ?>">
        <?= view('databases/partials/field.php', ['key' => $key, 'def' => $def, 'value' => $val, 'id' => 'cellfield-' . $rid . '-' . kid($key), 'mode' => 'cell', 'members' => $members ?? []]) ?>
        <noscript><button type="submit" class="btn btn-light btn-sm">Save</button></noscript>
    </form>
<?php else: ?>
    <?= render_value($type, $def, $val, $rid, $here, $d['database_id']) ?>
<?php endif; ?>
</td>
