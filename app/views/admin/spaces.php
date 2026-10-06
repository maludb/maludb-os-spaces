<?php /** Every space (screen `admin-spaces`). Data: rows, filters (kind, archived), here, notice */
$kinds = ['' => 'Every kind', 'open' => 'Open', 'closed' => 'Closed', 'private' => 'Private']; ?>
<?= view('shared/header.php', ['id' => 'admin-spaces', 'title' => 'Every space', 'crumbs' => [['Home', '/'], ['Every space', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-spaces-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/admin/spaces" class="d-flex flex-wrap gap-2 mb-3 align-items-center" id="admin-spaces-filters">
        <select name="kind" class="form-select w-auto btn-touch" id="admin-spaces-filter-kind" aria-label="Kind" onchange="this.form.submit()"><?php foreach ($kinds as $v => $l): ?><option value="<?= e($v) ?>" <?= ($filters['kind'] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="admin-spaces-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="admin-spaces-filter-archived" <?= !empty($filters['archived']) ? 'checked' : '' ?> onchange="this.form.submit()">Archived too</label>
        <noscript><button class="btn btn-light btn-touch" type="submit">Filter</button></noscript>
    </form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="admin-spaces-empty">No space matches.</div></div><?php endif; ?>
    <div class="row g-3" id="admin-spaces-list">
    <?php foreach ($rows as $s): echo view('admin/partials/space-row.php', ['s' => $s, 'here' => $here]); endforeach; ?>
    </div>
</div>
