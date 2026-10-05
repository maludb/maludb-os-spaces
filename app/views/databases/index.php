<?php /** The databases as cards (screen `database-list`). Data: rows, filters, spaces (to filter by), homes (to make one in), templates, may (create), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'database-list', 'title' => 'Databases', 'crumbs' => [['Home', '/'], ['Databases', null]],
    'action' => $may['create'] ? '<a href="#database-new" class="btn btn-primary btn-touch" id="database-list-add-btn"><i class="feather-plus me-1"></i>New database</a>' : '']) ?>
<div class="main-content" id="database-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/databases/" hx-get="/databases/" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#database-filter-q" hx-push-url="true" id="database-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-7"><label class="form-label fs-12 text-muted" for="database-filter-q">Title</label><input type="search" name="q" id="database-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="database-filter-space">Space</label><select name="space" id="database-filter-space" class="form-select btn-touch"><option value="">Any</option><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= $filters['space'] === (int) $s['space_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?php if ($rows === []): ?><div class="card mb-3"><div class="card-body text-muted" id="database-list-empty"><?= $filters['q'] !== '' ? 'No database matches.' : 'No database yet' . ($may['create'] ? ' — make the first one below.' : '.') ?></div></div><?php endif; ?>
    <div class="row g-3 mb-4" id="database-list">
        <?php foreach ($rows as $d): ?><div class="col-12 col-md-6 col-xl-4"><?= view('databases/partials/database-card.php', ['d' => $d, 'here' => $here, 'tz' => $tz]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($may['create']): ?>
    <form method="post" action="/databases/save.php" hx-post="/databases/save.php" hx-target="#flash" id="database-new" class="card mb-3"><?= csrf_field() ?>
        <div class="card-header"><h5 class="card-title mb-0">New database</h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="database-new-field-title">Title</label>
            <input type="text" name="title" id="database-new-field-title" class="form-control btn-touch mb-3" maxlength="300" required>
            <label class="form-label fs-12 text-muted" for="database-new-field-space">In a space</label>
            <select name="space" id="database-new-field-space" class="form-select btn-touch mb-3"><option value="">—</option><?php foreach ($homes['spaces'] as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= $filters['space'] === (int) $s['space_id'] ? 'selected' : '' ?>><?= e(($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') . e($s['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="database-new-field-parent">Or inside a page</label>
            <select name="parent" id="database-new-field-parent" class="form-select btn-touch mb-3"><option value="">—</option><?php foreach ($homes['pages'] as $p): ?><option value="<?= e($p['page_id']) ?>"><?= e($p['plain_title'] ?: 'Untitled') ?></option><?php endforeach; ?></select>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="database-new-field-inline"><input type="hidden" name="inline" value="no"><input type="checkbox" class="form-check-input mt-0" name="inline" value="yes" id="database-new-field-inline">Show it inside that page (inline)</label>
            <label class="form-label fs-12 text-muted" for="database-new-field-template">Start from a template</label>
            <select name="template" id="database-new-field-template" class="form-select btn-touch"><option value="">An empty database (a Name column)</option><?php foreach ($templates as $t): ?><option value="<?= e($t['page_id']) ?>"><?= e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['plain_title']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="database-new-save-btn">Make the database</button></div>
    </form>
    <?php endif; ?>
</div>
