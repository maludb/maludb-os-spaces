<?php /** The databases as cards (screen `database-list`). Data: rows, filters, spaces (to filter by), homes (to make one in), templates, may (create), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'database-list', 'title' => 'Databases', 'crumbs' => [['Home', '/'], ['Databases', null]],
    'action' => $may['create'] ? '<a href="#database-new" class="btn btn-primary btn-touch" id="database-list-add-btn"><i class="feather-plus me-1"></i>New database</a>' : '']) ?>
<div class="main-content" id="database-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/databases/" hx-get="/databases/" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#database-filter-q" hx-push-url="true" id="database-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-7"><label class="form-label fs-12 text-muted" for="database-filter-q">Title</label><input type="search" name="q" id="database-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="database-filter-space-open">Space</label><?= picker_field(['id' => 'database-filter-space', 'name' => 'space', 'source' => 'space', 'value' => $filters['space'], 'placeholder' => 'Any', 'title' => 'Spaces', 'search' => 'Search spaces…']) ?></div>
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
            <label class="form-label fs-12 text-muted" for="database-new-field-space-open">In a space</label>
            <div class="mb-3"><?= picker_field(['id' => 'database-new-field-space', 'name' => 'space', 'source' => 'space_page', 'value' => $filters['space'], 'placeholder' => '—', 'title' => 'Spaces', 'search' => 'Search spaces…']) ?></div>
            <label class="form-label fs-12 text-muted" for="database-new-field-parent-open">Or inside a page</label>
            <div class="mb-3"><?= picker_field(['id' => 'database-new-field-parent', 'name' => 'parent', 'source' => 'page', 'value' => null, 'params' => ['editable' => 1, 'pages_only' => 1], 'placeholder' => '—', 'title' => 'Pages', 'search' => 'Search pages…']) ?></div>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="database-new-field-inline"><input type="hidden" name="inline" value="no"><input type="checkbox" class="form-check-input mt-0" name="inline" value="yes" id="database-new-field-inline">Show it inside that page (inline)</label>
            <label class="form-label fs-12 text-muted" for="database-new-field-template">Start from a template</label>
            <select name="template" id="database-new-field-template" class="form-select btn-touch"><option value="">An empty database (a Name column)</option><?php foreach ($templates as $t): ?><option value="<?= e($t['page_id']) ?>"><?= e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['plain_title']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="database-new-save-btn">Make the database</button></div>
    </form>
    <?php endif; ?>
</div>
