<?php /** The people picker (screen `dm-new`): one → dm_open, several → group_dm_open. Data: people, max, here */ ?>
<?= view('shared/header.php', ['id' => 'dm-new', 'title' => 'New message', 'crumbs' => [['Home', '/'], ['Direct messages', '/dm/'], ['New', null]], 'back' => back_link() ?? ['/dm/', 'Direct messages']]) ?>
<div class="main-content" id="dm-new-content">
    <form method="post" action="/dm/open-group.php" id="dm-new-form" class="card"><div class="card-header"><h5 class="card-title mb-0">Who</h5></div><div class="card-body">
        <?= csrf_field() ?>
        <input type="search" class="form-control btn-touch mb-2" id="dm-new-filter" placeholder="Find a person or an agent" aria-label="Find a person">
        <div class="list-group mb-3" id="dm-new-people">
            <?php foreach ($people as $p): $mid = $p['member_id']; ?>
            <label class="list-group-item d-flex align-items-center gap-2 btn-touch" for="dm-new-person-<?= $mid ?>" data-name="<?= e(strtolower($p['display_name'])) ?>"><input type="checkbox" class="form-check-input mt-0" name="members[]" value="<?= $mid ?>" id="dm-new-person-<?= $mid ?>"><span><?= e($p['display_name']) ?></span><?php if (($p['status_line'] ?? '') !== ''): ?><span class="fs-12 text-muted text-truncate" id="dm-new-person-<?= $mid ?>-status"><?= e($p['status_line']) ?></span><?php endif; ?><?= $p['is_agent'] ? '<span class="badge bg-soft-info text-info">agent</span>' : '' ?><?= $p['is_guest'] ? '<span class="badge bg-soft-warning text-warning">guest</span>' : '' ?><?= !empty($p['is_active_now']) ? '<span class="sp-dot-online"></span>' : '' ?></label>
            <?php endforeach; ?>
            <?php if ($people === []): ?><div class="list-group-item text-muted fs-12">Nobody you may message yet.</div><?php endif; ?>
        </div>
        <div class="fs-12 text-muted mb-2" id="dm-new-hint">One person starts a direct message; two to <?= (int) $max - 1 ?> a group.</div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="dm-new-open-btn">Open the conversation</button>
    </div></form>
</div>
<script>
(function () {
    var f = document.getElementById('dm-new-form'), filter = document.getElementById('dm-new-filter');
    if (!f) return;
    filter.addEventListener('input', function () { var q = filter.value.toLowerCase(); f.querySelectorAll('#dm-new-people label').forEach(function (l) { l.classList.toggle('d-none', q !== '' && l.dataset.name.indexOf(q) < 0); }); });
    f.addEventListener('submit', function () {
        var picked = [].slice.call(f.querySelectorAll('input[name="members[]"]:checked'));
        if (picked.length === 1) { f.action = '/dm/open.php'; picked[0].name = 'member'; } else { f.action = '/dm/open-group.php'; }
    });
})();
</script>
