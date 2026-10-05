<?php /** Make or change a channel (screens `channel-add`, `channel-edit`). Data: cur, spaces, space (preselected id), may (manage, retention), here */
$id = $cur['channel_id'] ?? null; $screen = $cur === null ? 'channel-add' : 'channel-edit';
$back = $cur === null ? ['/channels/', 'Channels'] : ['/channels/' . (int) $id, $cur['label']]; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Make a channel' : 'Change ' . $cur['label'], 'crumbs' => [['Home', '/'], ['Channels', '/channels/'], [$cur === null ? 'New' : $cur['label'], $cur === null ? null : '/channels/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/channels/save.php" hx-post="/channels/save.php" hx-target="#flash" id="channel-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="channel" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The channel</h5></div><div class="card-body">
            <?php if ($cur === null): ?>
            <label class="form-label fs-12 text-muted" for="channel-form-field-space">Space</label>
            <select name="space" id="channel-form-field-space" class="form-select btn-touch mb-3" required><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= (int) $s['space_id'] === (int) $space ? 'selected' : '' ?>><?= e(($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') ?><?= e($s['name']) ?></option><?php endforeach; ?></select>
            <?php else: ?><div class="fs-12 text-muted mb-3">In <strong><?= e($cur['space_name']) ?></strong> — a channel stays in its space.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="channel-form-field-name">Name</label>
            <div class="input-group mb-1"><span class="input-group-text">#</span><input type="text" name="name" id="channel-form-field-name" class="form-control btn-touch" maxlength="80" required pattern="[a-z0-9][a-z0-9_-]{0,79}" value="<?= e($cur['name'] ?? '') ?>" <?= !empty($cur['is_default']) ? 'readonly' : '' ?> placeholder="launch-plan"></div>
            <div class="fs-12 text-muted mb-3">Lowercase letters, digits, dashes: <code id="channel-form-slug">#<?= e($cur['name'] ?? '…') ?></code></div>
            <div class="fs-12 text-muted mb-1">Kind</div>
            <?php foreach (['public' => 'Public — anyone in the space may read and follow it', 'private' => 'Private — only the people added to it'] as $k => $words): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="channel-form-field-kind-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= $k ?>" id="channel-form-field-kind-<?= $k ?>" <?= ($cur['kind'] ?? 'public') === $k ? 'checked' : '' ?> <?= !empty($cur['is_default']) || ($cur !== null && !$may['manage']) ? 'disabled' : '' ?>><span class="fs-12"><?= e($words) ?></span></label>
            <?php endforeach; ?>
            <?php if ($cur !== null && !$may['manage']): ?><input type="hidden" name="kind" value="<?= e($cur['kind']) ?>"><div class="fs-12 text-muted mb-2">The name and kind are the owner's to change.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted mt-2" for="channel-form-field-topic">Topic</label>
            <input type="text" name="topic" id="channel-form-field-topic" class="form-control btn-touch mb-3" maxlength="250" value="<?= e($cur['topic'] ?? '') ?>" placeholder="What is going on now">
            <label class="form-label fs-12 text-muted" for="channel-form-field-purpose">Purpose</label>
            <input type="text" name="purpose" id="channel-form-field-purpose" class="form-control btn-touch" maxlength="250" value="<?= e($cur['purpose'] ?? '') ?>" placeholder="What the channel is for">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="channel-form-save-btn"><?= $cur === null ? 'Make the channel' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="channel-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && $may['retention']): ?>
    <form method="post" action="/channels/retention.php" hx-post="/channels/retention.php" hx-target="#flash" hx-confirm="Change how long <?= e($cur['label']) ?> keeps messages?" id="channel-retention-form" class="card mt-3"><div class="card-header"><h5 class="card-title mb-0">Retention</h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="channel" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="/channels/<?= (int) $id ?>/edit">
        <label class="form-label fs-12 text-muted" for="channel-retention-field-days">Keep messages for (days; empty keeps forever)</label>
        <input type="number" name="days" id="channel-retention-field-days" class="form-control btn-touch mb-2" min="1" max="3650" value="<?= $cur['retention_days'] === null ? '' : (int) $cur['retention_days'] ?>">
        <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="channel-retention-save-btn">Save the retention</button>
    </div></form>
    <?php endif; ?>
</div>
<script>(function () { var i = document.getElementById('channel-form-field-name'), s = document.getElementById('channel-form-slug'); if (i && s) { i.addEventListener('input', function () { var v = i.value.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+/, ''); if (v !== i.value) { i.value = v; } s.textContent = '#' + (v || '…'); }); } })();</script>
