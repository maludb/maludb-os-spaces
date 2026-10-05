<?php
/**
 * One property's input. Data: key, def, value (the resolved value), id (the base for the input ids), mode (cell|panel|create), members (people choices), autosubmit? (bool: a change submits the form — set by the form's hx-trigger).
 * Names are p[key] (a list p[key][]; a date p[key][start] / p[key][end]); a list or checkbox sends a hidden empty value first so clearing it is a value.
 */
$type = (string) $def['type'];
$name = 'p[' . $key . ']';
$cls = 'form-control btn-touch' . ($mode === 'cell' ? ' sp-cell-input' : '');
$label = (string) ($def['name'] ?? $key);
$aria = ' aria-label="' . e($label) . '"';
$fid = e($id);
?>
<?php if (in_array($type, ['rich_text'], true)): ?>
    <input type="text" name="<?= e($name) ?>" id="<?= $fid ?>" class="<?= $cls ?>" maxlength="20000" value="<?= e(display_value('rich_text', $value)) ?>"<?= $aria ?>>
<?php elseif ($type === 'title'): ?>
    <input type="text" name="<?= e($name) ?>" id="<?= $fid ?>" class="<?= $cls ?>" maxlength="300" value="<?= e(display_value('title', $value)) ?>"<?= $aria ?>>
<?php elseif ($type === 'number'): ?>
    <input type="number" step="any" name="<?= e($name) ?>" id="<?= $fid ?>" class="<?= $cls ?>" value="<?= e($value === null ? '' : (string) ($value + 0)) ?>"<?= $aria ?>>
<?php elseif (in_array($type, ['url', 'email', 'phone_number'], true)): ?>
    <input type="<?= $type === 'url' ? 'url' : ($type === 'email' ? 'email' : 'tel') ?>" name="<?= e($name) ?>" id="<?= $fid ?>" class="<?= $cls ?>" value="<?= e(is_string($value) ? $value : '') ?>"<?= $aria ?>>
<?php elseif ($type === 'checkbox'): ?>
    <input type="hidden" name="<?= e($name) ?>" value="0">
    <label class="d-inline-flex align-items-center gap-2 btn-touch"><input type="checkbox" name="<?= e($name) ?>" id="<?= $fid ?>" value="1" class="form-check-input mt-0" <?= $value ? 'checked' : '' ?><?= $aria ?>><span class="fs-12 text-muted"><?= $mode === 'cell' ? '' : 'Yes' ?></span></label>
<?php elseif (in_array($type, ['select', 'status'], true)): $cur = display_value($type, $value); ?>
    <select name="<?= e($name) ?>" id="<?= $fid ?>" class="form-select btn-touch<?= $mode === 'cell' ? ' sp-cell-input' : '' ?>"<?= $aria ?>>
        <option value="">—</option>
        <?php foreach (property_options($def) as $o): ?><option value="<?= e($o['name']) ?>" <?= strcasecmp($cur, (string) $o['name']) === 0 ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
    </select>
<?php elseif ($type === 'multi_select'): $cur = array_map(static fn ($o): string => strtolower(is_array($o) ? (string) ($o['name'] ?? '') : (string) $o), (array) ($value ?? [])); ?>
    <details class="sp-pop" id="<?= $fid ?>">
        <summary class="form-control btn-touch sp-pop-summary" role="button"<?= $aria ?>><?= render_value('multi_select', $def, $value) ?: '<span class="text-muted">Choose</span>' ?></summary>
        <div class="sp-pop-body card shadow p-2">
            <input type="hidden" name="<?= e($name) ?>[]" value="">
            <?php foreach (property_options($def) as $i => $o): ?>
                <label class="d-flex align-items-center gap-2 btn-touch px-1"><input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>[]" value="<?= e($o['name']) ?>" <?= in_array(strtolower((string) $o['name']), $cur, true) ? 'checked' : '' ?>><?= render_chip((string) $o['name'], $o['color'] ?? null) ?></label>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-1">Apply</button>
        </div>
    </details>
<?php elseif ($type === 'people'): $cur = array_map(static fn ($p): int => (int) ($p['id'] ?? 0), (array) ($value ?? [])); ?>
    <details class="sp-pop" id="<?= $fid ?>">
        <summary class="form-control btn-touch sp-pop-summary" role="button"<?= $aria ?>><?= render_value('people', $def, $value) ?: '<span class="text-muted">Choose</span>' ?></summary>
        <div class="sp-pop-body card shadow p-2">
            <input type="hidden" name="<?= e($name) ?>[]" value="">
            <input type="search" class="form-control btn-touch mb-1 sp-pop-filter" placeholder="Find a person" aria-label="Find a person">
            <div class="sp-pop-list">
            <?php foreach ($members as $m): ?>
                <label class="d-flex align-items-center gap-2 btn-touch px-1" data-name="<?= e(strtolower($m['display_name'])) ?>"><input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>[]" value="<?= (int) $m['member_id'] ?>" <?= in_array($m['member_id'], $cur, true) ? 'checked' : '' ?>><?= $m['is_agent'] ? '<i class="feather-cpu"></i> ' : '' ?><?= e($m['display_name']) ?></label>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-1">Apply</button>
        </div>
    </details>
<?php elseif ($type === 'date'): $v = is_array($value) ? $value : []; ?>
    <?php if ($mode === 'cell'): ?>
    <details class="sp-pop" id="<?= $fid ?>">
        <summary class="form-control btn-touch sp-pop-summary" role="button"<?= $aria ?>><?= e(display_value('date', $value)) ?: '<span class="text-muted">Pick</span>' ?></summary>
        <div class="sp-pop-body card shadow p-2">
            <label class="fs-12 text-muted" for="<?= $fid ?>-start">Start</label><input type="date" name="<?= e($name) ?>[start]" id="<?= $fid ?>-start" class="form-control btn-touch mb-1" value="<?= e(substr((string) ($v['start'] ?? ''), 0, 10)) ?>">
            <label class="fs-12 text-muted" for="<?= $fid ?>-end">End (optional)</label><input type="date" name="<?= e($name) ?>[end]" id="<?= $fid ?>-end" class="form-control btn-touch mb-1" value="<?= e(substr((string) ($v['end'] ?? ''), 0, 10)) ?>">
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-1">Apply</button>
        </div>
    </details>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-2 align-items-center" id="<?= $fid ?>">
        <input type="date" name="<?= e($name) ?>[start]" id="<?= $fid ?>-start" class="form-control btn-touch" style="max-width: 11rem" value="<?= e(substr((string) ($v['start'] ?? ''), 0, 10)) ?>" aria-label="<?= e($label) ?> start">
        <span class="text-muted fs-12">to</span>
        <input type="date" name="<?= e($name) ?>[end]" id="<?= $fid ?>-end" class="form-control btn-touch" style="max-width: 11rem" value="<?= e(substr((string) ($v['end'] ?? ''), 0, 10)) ?>" aria-label="<?= e($label) ?> end">
    </div>
    <?php endif; ?>
<?php endif; ?>
