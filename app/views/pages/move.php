<?php /** The destination picker (screen `page-move`). Data: p, spaces, parents, mayPrivate, here */ $pid = (string) $p['page_id']; ?>
<?= view('shared/header.php', ['id' => 'page-move', 'title' => 'Move ' . ($p['plain_title'] ?: 'the page'), 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['Move', null]], 'back' => back_link() ?? ['/pages/' . $pid, $p['plain_title'] ?: 'the page']]) ?>
<div class="main-content" id="page-move-content">
    <form method="post" action="/pages/move.php" hx-post="/pages/move.php" hx-target="#flash" id="move-form">
        <?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Where to</h5></div><div class="card-body">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="move-where-space"><input type="radio" class="form-check-input mt-0" name="where" value="space" id="move-where-space" checked>A space's root</label>
            <div class="mb-3"><?= picker_field(['id' => 'move-form-field-space', 'name' => 'space', 'source' => 'space_page', 'value' => $p['space_id'], 'placeholder' => '—', 'title' => 'Spaces', 'search' => 'Search spaces…']) ?></div>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="move-where-parent"><input type="radio" class="form-check-input mt-0" name="where" value="parent" id="move-where-parent">Under a page</label>
            <div class="mb-3"><?= picker_field(['id' => 'move-form-field-parent', 'name' => 'parent', 'source' => 'page', 'value' => null, 'params' => ['editable' => 1, 'except' => $pid], 'placeholder' => '—', 'title' => 'Pages', 'search' => 'Search pages…']) ?></div>
            <?php if ($mayPrivate): ?><input type="hidden" name="private" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="move-where-private"><input type="radio" class="form-check-input mt-0" name="where" value="private" id="move-where-private">My private pages</label><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="move-form-btn">Move</button>
        <?= hx_link('/pages/' . $pid, 'Cancel', 'btn btn-light btn-touch w-100') ?>
    </form>
</div>
<script>
(function () { var form = document.getElementById('move-form'); if (!form) return;
    var apply = function () { var w = (form.querySelector('input[name="where"]:checked') || {}).value; var sp = form.querySelector('[name="space"]'), pa = form.querySelector('[name="parent"]'), pr = form.querySelector('input[name="private"][type="hidden"]');
        if (sp) sp.disabled = w !== 'space'; if (pa) pa.disabled = w !== 'parent'; if (pr) pr.value = w === 'private' ? 'yes' : 'no'; };
    form.querySelectorAll('input[name="where"]').forEach(function (r) { r.addEventListener('change', apply); }); apply(); })();
</script>
