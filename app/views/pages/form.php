<?php /** New page / change a page (screens `page-add`, `page-edit`). Data: cur, spaces, parents, templates, emoji, pre, mayPrivate, here */
$pid = $cur['page_id'] ?? null; $screen = $cur === null ? 'page-add' : 'page-edit';
$back = $cur === null ? ['/pages/', 'Pages'] : ['/pages/' . $pid, $cur['plain_title'] ?: 'the page'];
$icon = $cur['icon'] ?? '';
$where = $pre['private'] ? 'private' : ($pre['parent'] !== null ? 'parent' : 'space'); ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New page' : 'Change ' . ($cur['plain_title'] ?: 'the page'), 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$cur === null ? 'New' : ($cur['plain_title'] ?: 'Untitled'), $cur === null ? null : '/pages/' . $pid]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/pages/save.php" hx-post="/pages/save.php" hx-target="#flash" id="page-form">
        <?= csrf_field() ?><?php if ($pid !== null): ?><input type="hidden" name="page" value="<?= e($pid) ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The page</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-3 col-md-2">
                    <label class="form-label fs-12 text-muted" for="page-form-field-icon">Icon</label>
                    <details class="sp-emoji-picker" id="page-form-emoji">
                        <summary class="form-control btn-touch text-center fs-5" id="page-form-emoji-summary"><?= e($icon !== '' ? $icon : '📄') ?></summary>
                        <div class="sp-emoji-popover card shadow p-2"><div class="d-flex flex-wrap gap-1" id="page-form-emoji-grid"><?php foreach ($emoji as $em): ?><button type="button" class="btn btn-light btn-sm sp-emoji-btn" data-emoji="<?= e($em['emoji']) ?>"><?= e($em['emoji']) ?></button><?php endforeach; ?></div>
                        <input type="text" name="icon" id="page-form-field-icon" class="form-control btn-touch mt-2" maxlength="16" placeholder="or type one" value="<?= e($icon) ?>"></div>
                    </details>
                </div>
                <div class="col-9 col-md-10"><label class="form-label fs-12 text-muted" for="page-form-field-title">Title</label><input type="text" name="title" id="page-form-field-title" class="form-control btn-touch" maxlength="300" required value="<?= e($cur['plain_title'] ?? '') ?>"></div>
            </div>
            <?php if ($cur !== null): ?>
            <label class="form-label fs-12 text-muted" for="page-form-field-cover">Cover (an image's attachment id — slice 3 brings the upload)</label>
            <input type="number" name="cover" id="page-form-field-cover" class="form-control btn-touch" min="1" value="<?= $cur['cover_attachment_id'] === null ? '' : (int) $cur['cover_attachment_id'] ?>">
            <?php endif; ?>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Where</h5></div><div class="card-body">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="page-form-where-space"><input type="radio" class="form-check-input mt-0" name="where" value="space" id="page-form-where-space" <?= $where === 'space' ? 'checked' : '' ?>>At a space's root</label>
            <select name="space" id="page-form-field-space" class="form-select btn-touch mb-3"><option value="">—</option><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= $pre['space'] === (int) $s['space_id'] ? 'selected' : '' ?>><?= e(($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') . e($s['name']) ?></option><?php endforeach; ?></select>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="page-form-where-parent"><input type="radio" class="form-check-input mt-0" name="where" value="parent" id="page-form-where-parent" <?= $where === 'parent' ? 'checked' : '' ?>>Under a page</label>
            <select name="parent" id="page-form-field-parent" class="form-select btn-touch mb-3"><option value="">—</option><?php foreach ($parents as $pp): ?><option value="<?= e($pp['page_id']) ?>" <?= $pre['parent'] === $pp['page_id'] ? 'selected' : '' ?>><?= e($pp['plain_title'] ?: 'Untitled') ?> (<?= e($pp['space_name'] ?? 'private') ?>)</option><?php endforeach; ?></select>
            <?php if ($mayPrivate): ?>
            <input type="hidden" name="private" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="page-form-where-private"><input type="radio" class="form-check-input mt-0" name="where" value="private" id="page-form-where-private" <?= $where === 'private' ? 'checked' : '' ?>>My private pages</label>
            <?php endif; ?>
            <div class="fs-12 text-muted">The radio says which of the three counts; the others are ignored.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Start from</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="page-form-field-template">A template</label>
            <select name="template" id="page-form-field-template" class="form-select btn-touch mb-3"><option value="">An empty page</option><?php foreach ($templates as $t): ?><option value="<?= e($t['page_id']) ?>" <?= $pre['template'] === $t['page_id'] ? 'selected' : '' ?>><?= e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['plain_title']) ?><?= $t['is_default'] ? '' : ' (' . e($t['space_name'] ?? '') . ')' ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="page-form-field-markdown">Or some text to begin with (Markdown — slice 3 brings the editor)</label>
            <textarea name="markdown" id="page-form-field-markdown" class="form-control" rows="4" maxlength="20000"></textarea>
        </div></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="page-form-save-btn"><?= $cur === null ? 'Make the page' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="page-form-cancel-link"') ?>
    </form>
</div>
<script>
(function () {
    var grid = document.getElementById('page-form-emoji-grid'), input = document.getElementById('page-form-field-icon'), summary = document.getElementById('page-form-emoji-summary'), picker = document.getElementById('page-form-emoji');
    if (grid && input) { grid.addEventListener('click', function (e) { var b = e.target.closest('.sp-emoji-btn'); if (!b) return; input.value = b.dataset.emoji; summary.textContent = b.dataset.emoji; picker.removeAttribute('open'); }); input.addEventListener('input', function () { summary.textContent = input.value || '📄'; }); }
    var form = document.getElementById('page-form');
    if (form && form.querySelector('input[name="where"]')) {
        // the radio decides: the other destinations are cleared before the post
        var apply = function () { var w = (form.querySelector('input[name="where"]:checked') || {}).value; var sp = form.querySelector('[name="space"]'), pa = form.querySelector('[name="parent"]'), pr = form.querySelector('input[name="private"][type="hidden"]');
            if (sp) sp.disabled = w !== 'space'; if (pa) pa.disabled = w !== 'parent'; if (pr) pr.value = w === 'private' ? 'yes' : 'no'; };
        form.querySelectorAll('input[name="where"]').forEach(function (r) { r.addEventListener('change', apply); });
        apply();
    }
})();
</script>
