<?php /** The pages as cards (screen `page-list`). Data: rows, filters, spaces, may (create), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'page-list', 'title' => 'Pages', 'crumbs' => [['Home', '/'], ['Pages', null]],
    'action' => ($may['create'] ? hx_link('/pages/new', '<i class="feather-plus me-1"></i>New page', 'btn btn-primary btn-touch', 'id="page-list-add-btn"') . ' ' : '') . hx_link('/pages/trash', '<i class="feather-trash-2"></i>', 'btn btn-light btn-touch', 'id="page-list-trash-btn" aria-label="Trash"') . ' ' . hx_link('/templates/', '<i class="feather-layout"></i>', 'btn btn-light btn-touch', 'id="page-list-templates-btn" aria-label="Templates"')]) ?>
<div class="main-content" id="page-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/pages/" hx-get="/pages/" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#page-filter-q" hx-push-url="true" id="page-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="page-filter-q">Title</label><input type="search" name="q" id="page-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="page-filter-space">Space</label><select name="space" id="page-filter-space" class="form-select btn-touch"><option value="">Any</option><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['space_id'] ?>" <?= $filters['space'] === (int) $s['space_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-2"><label class="form-label fs-12 text-muted" for="page-filter-kind">Kind</label><select name="kind" id="page-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (['page', 'database', 'row'] as $k): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
        <div class="col-3 col-md-2"><label class="form-label fs-12 text-muted" for="page-filter-changed">Changed</label><select name="changed" id="page-filter-changed" class="form-select btn-touch"><option value="">Ever</option><?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days'] as $d => $l): ?><option value="<?= $d ?>" <?= $filters['changed'] === $d ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="page-list-empty"><?= $filters['q'] !== '' ? 'No page matches.' : 'No page yet' . ($may['create'] ? ' — make the first one.' : '.') ?></div></div><?php endif; ?>
    <div class="row g-3" id="page-list">
        <?php foreach ($rows as $p): ?><div class="col-12 col-md-6 col-xl-4"><?= view('pages/partials/page-card.php', ['p' => $p, 'here' => $here, 'tz' => $tz]) ?></div><?php endforeach; ?>
    </div>
</div>
