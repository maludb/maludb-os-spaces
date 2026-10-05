<?php
/** The schema (screen `database-schema`). Data: d, may, targets (databases a relation may name), relTitles, here, notice */
$did = $d['database_id'];
$schema = $d['properties'];
$types = ['rich_text', 'number', 'select', 'multi_select', 'status', 'date', 'people', 'files', 'checkbox', 'url', 'email', 'phone_number', 'relation', 'rollup', 'created_time', 'created_by', 'last_edited_time', 'last_edited_by', 'unique_id'];
$relations = array_filter($schema, static fn (array $x): bool => ($x['type'] ?? '') === 'relation');
$retypeTo = static function (string $from): array {
    return match ($from) { 'number', 'url', 'email', 'phone_number', 'status', 'multi_select' => ['rich_text'], 'select' => ['multi_select', 'rich_text'], 'checkbox' => ['select', 'rich_text'], default => [] };
};
?>
<?= view('shared/header.php', ['id' => 'database-schema', 'title' => 'Schema', 'crumbs' => [['Home', '/'], ['Databases', '/databases/'], [$d['plain_title'] ?: 'Untitled', '/databases/' . $did], ['Schema', null]], 'back' => back_link() ?? ['/databases/' . $did, $d['plain_title'] ?: 'the database']]) ?>
<div class="main-content" id="database-schema-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['schema']): ?>
    <form method="post" action="/databases/save.php" hx-post="/databases/save.php" hx-target="#flash" class="card mb-3" id="database-details"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><input type="hidden" name="return_to" value="<?= e('/databases/' . $did . '/schema') ?>">
        <div class="card-header"><h5 class="card-title mb-0">The database</h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="database-field-title">Title</label><input type="text" name="title" id="database-field-title" class="form-control btn-touch mb-3" maxlength="300" required value="<?= e($d['plain_title']) ?>">
            <label class="form-label fs-12 text-muted" for="database-field-description">Description</label><textarea name="description" id="database-field-description" class="form-control mb-3" rows="2" maxlength="5000"><?= e(display_value('rich_text', $d['description'])) ?></textarea>
            <?php if ($d['parent_page_id'] !== null): ?><input type="hidden" name="inline" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="database-field-inline"><input type="checkbox" class="form-check-input mt-0" name="inline" value="yes" id="database-field-inline" <?= $d['is_inline'] ? 'checked' : '' ?>>Shown inside its page (inline)</label><?php endif; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="database-details-save-btn">Save</button>
        </div>
    </form>
    <?php endif; ?>
    <div class="card mb-3" id="schema-table-card"><div class="card-header"><h5 class="card-title mb-0">Properties</h5></div>
    <div class="table-responsive sp-table-wrap"><table class="table table-sm align-middle mb-0" id="schema-table">
        <thead><tr><th scope="col">Name</th><th scope="col">Key</th><th scope="col">Type</th><th scope="col">Settings</th><?php if ($may['schema']): ?><th scope="col">Change</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($schema as $key => $def): $type = (string) $def['type']; ?>
            <tr id="property-row-<?= kid($key) ?>">
                <td class="fw-semibold"><?= e($def['name'] ?? $key) ?></td>
                <td><code><?= e($key) ?></code></td>
                <td><?= type_chip($type) ?></td>
                <td class="fs-12">
                    <?php if (in_array($type, ['select', 'multi_select', 'status'], true)): ?>
                        <?php foreach (property_options($def) as $o) { echo render_chip((string) $o['name'], $o['color'] ?? null) . ' '; } ?><?= property_options($def) === [] ? '<span class="text-muted">no options yet</span>' : '' ?>
                    <?php elseif ($type === 'relation'): ?>to <strong><?= e($relTitles[$def['relation']['database_id']] ?? 'a database') ?></strong> · <?= !empty($def['relation']['two_way']) ? 'two-way' . (isset($def['relation']['dual_property']) ? ' (' . e($def['relation']['dual_property']) . ')' : '') : 'one-way' ?>
                    <?php elseif ($type === 'rollup'): ?><?= e(str_replace('_', ' ', (string) ($def['rollup']['function'] ?? ''))) ?> of <strong><?= e($def['rollup']['property'] ?? '') ?></strong> through <strong><?= e($def['rollup']['relation'] ?? '') ?></strong>
                    <?php elseif ($type === 'unique_id'): ?>prefix <code><?= e($def['unique_id']['prefix'] ?? '') ?: '—' ?></code>
                    <?php elseif ($type === 'number'): ?>format <?= e($def['number']['format'] ?? 'number') ?>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <?php if ($may['schema']): ?>
                <td>
                    <details class="sp-pop"><summary class="btn btn-light btn-sm btn-touch" id="property-change-<?= kid($key) ?>">Change</summary>
                    <div class="sp-pop-body card shadow p-2 sp-pop-wide">
                        <form method="post" action="/databases/properties/save.php" hx-post="/databases/properties/save.php" hx-target="#flash" class="mb-2" id="property-rename-<?= kid($key) ?>"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><input type="hidden" name="key" value="<?= e($key) ?>">
                            <label class="fs-12 text-muted" for="property-rename-<?= kid($key) ?>-name">Rename</label>
                            <div class="d-flex gap-1"><input type="text" name="name" id="property-rename-<?= kid($key) ?>-name" class="form-control btn-touch" maxlength="100" value="<?= e($def['name'] ?? $key) ?>"><button type="submit" class="btn btn-light btn-touch">Rename</button></div></form>
                        <?php if (in_array($type, ['select', 'multi_select', 'status'], true)): ?>
                        <form method="post" action="/databases/properties/save.php" hx-post="/databases/properties/save.php" hx-target="#flash" class="mb-2" id="property-options-<?= kid($key) ?>"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><input type="hidden" name="key" value="<?= e($key) ?>">
                            <label class="fs-12 text-muted" for="property-options-<?= kid($key) ?>-field">Options (one per line; <em>Name | color</em>)</label>
                            <textarea name="options" id="property-options-<?= kid($key) ?>-field" class="form-control mb-1" rows="4"><?= e(implode("\n", array_map(static fn (array $o): string => (string) $o['name'] . (isset($o['color']) ? ' | ' . $o['color'] : ''), property_options($def)))) ?></textarea>
                            <button type="submit" class="btn btn-light btn-touch w-100">Save the options</button></form>
                        <?php endif; ?>
                        <?php if ($retypeTo($type) !== []): ?>
                        <form method="post" action="/databases/properties/save.php" hx-post="/databases/properties/save.php" hx-target="#flash" class="mb-2" id="property-retype-<?= kid($key) ?>"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><input type="hidden" name="key" value="<?= e($key) ?>">
                            <label class="fs-12 text-muted" for="property-retype-<?= kid($key) ?>-type">Change the type (the values follow)</label>
                            <div class="d-flex gap-1"><select name="type" id="property-retype-<?= kid($key) ?>-type" class="form-select btn-touch"><?php foreach ($retypeTo($type) as $t): ?><option value="<?= e($t) ?>"><?= e(TYPE_LABELS[$t] ?? $t) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-light btn-touch">Change</button></div></form>
                        <?php endif; ?>
                        <?php if ($type !== 'title'): ?>
                        <form method="post" action="/databases/properties/remove.php" hx-post="/databases/properties/remove.php" hx-target="#flash" id="property-remove-<?= kid($key) ?>"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>"><input type="hidden" name="key" value="<?= e($key) ?>">
                            <div class="fs-12 text-muted mb-1">Remove <?= e($def['name'] ?? $key) ?>?</div>
                            <label class="d-flex align-items-center gap-2 btn-touch px-1"><input type="radio" class="form-check-input mt-0" name="purge_values" value="no" checked>Keep the rows' values</label>
                            <label class="d-flex align-items-center gap-2 btn-touch px-1"><input type="radio" class="form-check-input mt-0" name="purge_values" value="yes">Purge the values too</label>
                            <button type="submit" class="btn btn-danger btn-touch w-100 mt-1" id="property-remove-<?= kid($key) ?>-btn">Remove</button></form>
                        <?php else: ?><div class="fs-12 text-muted">The title is the title: it cannot be removed.</div><?php endif; ?>
                    </div></details>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <?php if ($may['schema']): ?>
    <form method="post" action="/databases/properties/save.php" hx-post="/databases/properties/save.php" hx-target="#flash" class="card mb-3" id="property-add-form"><?= csrf_field() ?><input type="hidden" name="database" value="<?= e($did) ?>">
        <div class="card-header"><h5 class="card-title mb-0">Add a property</h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="property-add-field-key">Name</label><input type="text" name="key" id="property-add-field-key" class="form-control btn-touch mb-3" maxlength="100" required>
            <label class="form-label fs-12 text-muted" for="property-add-field-type">Type</label>
            <select name="type" id="property-add-field-type" class="form-select btn-touch mb-3"><?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?= e(TYPE_LABELS[$t] ?? $t) ?></option><?php endforeach; ?></select>
            <fieldset class="mb-3 sp-type-fields" data-for="select multi_select status"><label class="form-label fs-12 text-muted" for="property-add-field-options">Options (one per line; <em>Name | color</em> — gray, brown, orange, yellow, green, blue, purple, pink, red)</label><textarea name="options" id="property-add-field-options" class="form-control" rows="3"></textarea></fieldset>
            <fieldset class="mb-3 sp-type-fields" data-for="number"><label class="form-label fs-12 text-muted" for="property-add-field-format">Number format</label><select name="number_format" id="property-add-field-format" class="form-select btn-touch"><?php foreach (NUMBER_FORMATS as $f): ?><option value="<?= e($f) ?>"><?= e(str_replace('_', ' ', $f)) ?></option><?php endforeach; ?></select></fieldset>
            <fieldset class="mb-3 sp-type-fields" data-for="relation"><label class="form-label fs-12 text-muted" for="property-add-field-relation">Points at</label><select name="relation_database" id="property-add-field-relation" class="form-select btn-touch mb-2"><option value="">—</option><?php foreach ($targets as $t): ?><option value="<?= e($t['database_id']) ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select>
                <input type="hidden" name="two_way" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="property-add-field-two-way"><input type="checkbox" class="form-check-input mt-0" name="two_way" value="yes" id="property-add-field-two-way">Two-way: the other database shows the link back</label></fieldset>
            <fieldset class="mb-3 sp-type-fields" data-for="rollup"><label class="form-label fs-12 text-muted" for="property-add-field-rollup-relation">Through the relation</label><select name="rollup_relation" id="property-add-field-rollup-relation" class="form-select btn-touch mb-2"><option value="">—</option><?php foreach ($schema as $k => $x): ?><option value="<?= e($k) ?>"><?= e($x['name'] ?? $k) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted" for="property-add-field-rollup-property">Of the related property (its name or key)</label><input type="text" name="rollup_property" id="property-add-field-rollup-property" class="form-control btn-touch mb-2">
                <label class="form-label fs-12 text-muted" for="property-add-field-rollup-function">Calculate</label><select name="rollup_function" id="property-add-field-rollup-function" class="form-select btn-touch"><?php foreach (['count', 'sum', 'min', 'max', 'earliest_date', 'latest_date', 'percent_checked', 'show_original'] as $f): ?><option value="<?= e($f) ?>"><?= e(str_replace('_', ' ', $f)) ?></option><?php endforeach; ?></select></fieldset>
            <fieldset class="mb-3 sp-type-fields" data-for="unique_id"><label class="form-label fs-12 text-muted" for="property-add-field-prefix">Prefix (TSK gives TSK-1, TSK-2 …)</label><input type="text" name="prefix" id="property-add-field-prefix" class="form-control btn-touch" maxlength="12"></fieldset>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="property-add-save-btn">Add the property</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<link rel="stylesheet" href="/assets/css/databases.css">
<script src="/assets/js/databases.js"></script>
