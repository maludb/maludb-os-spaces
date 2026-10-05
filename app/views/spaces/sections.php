<?php /** The sidebar's sections of a space (screen `space-sections`). Data: s, sections, loose, here, notice */ $id = (int) $s['space_id']; ?>
<?= view('shared/header.php', ['id' => 'space-sections', 'title' => $s['name'] . ' · Sections', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $id], ['Sections', null]], 'back' => back_link() ?? ['/spaces/' . $id, $s['name']]]) ?>
<div class="main-content" id="space-sections-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/spaces/sections/save.php" hx-post="/spaces/sections/save.php" hx-target="#flash" id="section-add-form" class="card mb-3"><div class="card-body row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/sections">
        <div class="col-8 col-md-10"><label class="form-label fs-12 text-muted" for="section-add-field-name">New section</label><input type="text" name="name" id="section-add-field-name" class="form-control btn-touch" maxlength="60" required placeholder="Docs"></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="section-add-btn">Add</button></div>
    </div></form>
    <?= view('spaces/partials/section-list.php', ['s' => $s, 'sections' => $sections, 'loose' => $loose, 'here' => $here]) ?>
    <div class="fs-12 text-muted">Drag a section by its handle to reorder; pick a section for a page to move it.</div>
</div>
<script>
(function () {
    var list = document.getElementById('section-sortable');
    if (!list || !window.Sortable) return;
    Sortable.create(list, { handle: '.sp-drag-handle', animation: 120, onEnd: function (evt) {
        var item = evt.item, prev = item.previousElementSibling;
        var section = item.dataset.section, after = prev && prev.dataset.section ? prev.dataset.section : '';
        if (!section) return;
        var fd = new FormData(); fd.append('space', '<?= $id ?>'); fd.append('section', section); fd.append('after', after); fd.append('return_to', '/spaces/<?= $id ?>/sections');
        fd.append('csrf_token', document.getElementById('csrf-token-meta').content);
        htmx.ajax('POST', '/spaces/sections/save.php', { target: '#flash', swap: 'innerHTML', values: Object.fromEntries(fd) });
    } });
})();
</script>
