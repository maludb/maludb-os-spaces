<?php /** Make or change a space (screens `space-add`, `space-edit`). Data: cur, settings, emoji, here */
$id = $cur['space_id'] ?? null; $screen = $cur === null ? 'space-add' : 'space-edit';
$back = $cur === null ? ['/spaces/', 'Spaces'] : ['/spaces/' . (int) $id, $cur['name']];
$kind = $cur['kind'] ?? $settings['default_space_kind']; $icon = $cur['icon'] ?? ''; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Make a space' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$cur === null ? 'New' : $cur['name'], $cur === null ? null : '/spaces/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/spaces/save.php" hx-post="/spaces/save.php" hx-target="#flash" id="space-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="space" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The space</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-3 col-md-2">
                    <label class="form-label fs-12 text-muted" for="space-form-field-icon">Icon</label>
                    <details class="sp-emoji-picker" id="space-form-emoji">
                        <summary class="form-control btn-touch text-center fs-5" id="space-form-emoji-summary" aria-label="Pick an icon"><?= e($icon !== '' ? $icon : '🗂️') ?></summary>
                        <div class="sp-emoji-popover card shadow p-2" id="space-form-emoji-popover">
                            <div class="d-flex flex-wrap gap-1" id="space-form-emoji-grid">
                                <?php foreach ($emoji as $em): ?><button type="button" class="btn btn-light btn-sm sp-emoji-btn" data-emoji="<?= e($em['emoji']) ?>" title=":<?= e($em['shortcode']) ?>:"><?= e($em['emoji']) ?></button><?php endforeach; ?>
                            </div>
                            <input type="text" name="icon" id="space-form-field-icon" class="form-control btn-touch mt-2" maxlength="16" placeholder="or type one" value="<?= e($icon) ?>">
                        </div>
                    </details>
                </div>
                <div class="col-9 col-md-10"><label class="form-label fs-12 text-muted" for="space-form-field-name">Name</label><input type="text" name="name" id="space-form-field-name" class="form-control btn-touch" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="space-form-field-description">Description</label>
            <textarea name="description" id="space-form-field-description" class="form-control mb-3" rows="3" maxlength="2000"><?= e($cur['description'] ?? '') ?></textarea>
            <?php if ($cur === null): ?>
            <div class="fs-12 text-muted mb-1">Kind</div>
            <?php foreach (SPACE_KINDS as $k => $words): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="space-form-field-kind-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= $k ?>" id="space-form-field-kind-<?= $k ?>" <?= $kind === $k ? 'checked' : '' ?>><span><?= e($words) ?></span></label>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="fs-12 text-muted mb-1">Kind: <strong id="space-form-kind-current"><?= e($cur['kind']) ?></strong> — changed below, with a confirmation.</div>
            <?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">What members may do</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="space-form-field-member-level">A member's level on the space's pages</label>
            <select name="member_level" id="space-form-field-member-level" class="form-select btn-touch mb-3"><?php foreach (SPACE_LEVELS as $k => $w): ?><option value="<?= $k ?>" <?= ($cur['member_level'] ?? $settings['default_member_level']) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
            <div id="space-form-everyone" <?= $kind === 'open' ? '' : 'hidden' ?>>
                <label class="form-label fs-12 text-muted" for="space-form-field-everyone-level">Everyone in the business (an open space)</label>
                <select name="everyone_level" id="space-form-field-everyone-level" class="form-select btn-touch mb-3"><?php foreach (SPACE_EVERYONE_LEVELS as $k => $w): ?><option value="<?= $k ?>" <?= ($cur['everyone_level'] ?? $settings['default_everyone_level']) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
            </div>
            <input type="hidden" name="is_wiki" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="space-form-field-wiki"><input type="checkbox" class="form-check-input mt-0" name="is_wiki" value="yes" id="space-form-field-wiki" <?= !empty($cur['is_wiki']) ? 'checked' : '' ?>>A wiki: every page has an owner and is verified on a schedule</label>
            <label class="form-label fs-12 text-muted" for="space-form-field-months">Verify every</label>
            <select name="verify_months" id="space-form-field-months" class="form-select btn-touch"><?php foreach (WIKI_MONTHS as $m): ?><option value="<?= $m ?>" <?= (int) ($cur['wiki_default_verify_months'] ?? $settings['wiki_default_verify_months']) === $m ? 'selected' : '' ?>><?= $m ?> month<?= $m === 1 ? '' : 's' ?></option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="space-form-save-btn"><?= $cur === null ? 'Make the space' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="space-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && !$cur['is_default']): ?>
    <form method="post" action="/spaces/kind.php" hx-post="/spaces/kind.php" hx-target="#flash" hx-confirm="Change who may see and join <?= e($cur['name']) ?>?" id="space-kind-form" class="card mt-3"><div class="card-header"><h5 class="card-title mb-0">Change the kind</h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="space" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= (int) $id ?>/edit">
        <?php foreach (SPACE_KINDS as $k => $words): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="space-kind-field-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= $k ?>" id="space-kind-field-<?= $k ?>" <?= $cur['kind'] === $k ? 'checked' : '' ?>><span><?= e($words) ?></span></label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="space-kind-save-btn">Change the kind</button>
    </div></form>
    <?php endif; ?>
</div>
<script>
(function () {
    var grid = document.getElementById('space-form-emoji-grid'), input = document.getElementById('space-form-field-icon'), summary = document.getElementById('space-form-emoji-summary'), picker = document.getElementById('space-form-emoji');
    if (grid && input) {
        grid.addEventListener('click', function (e) { var b = e.target.closest('.sp-emoji-btn'); if (!b) return; input.value = b.dataset.emoji; summary.textContent = b.dataset.emoji; picker.removeAttribute('open'); });
        input.addEventListener('input', function () { summary.textContent = input.value || '🗂️'; });
    }
    document.querySelectorAll('input[name="kind"]').forEach(function (r) { r.addEventListener('change', function () { var ev = document.getElementById('space-form-everyone'); if (ev && r.form.id === 'space-form') { if (r.value === 'open') { ev.removeAttribute('hidden'); } else { ev.setAttribute('hidden', ''); } } }); });
})();
</script>
