<?php /** The spaces as cards in three groups (screen `space-list`). Data: groups (mine, open, closed), filters, pending, may (create, admin), here, notice */ ?>
<?= view('shared/header.php', ['id' => 'space-list', 'title' => 'Spaces', 'crumbs' => [['Home', '/'], ['Spaces', null]],
    'action' => $may['create'] ? hx_link('/spaces/new', '<i class="feather-plus me-1"></i>New space', 'btn btn-primary btn-touch', 'id="space-list-add-btn"') : '']) ?>
<div class="main-content" id="space-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/spaces/" hx-get="/spaces/" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#space-filter-q" hx-push-url="true" id="space-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="space-filter-q">Name</label><input type="search" name="q" id="space-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="space-filter-kind">Kind</label><select name="kind" id="space-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (['open', 'closed', 'private'] as $k): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="space-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="space-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>>Archived</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?php foreach ([['mine', 'Mine', 'The spaces you are in' . ($may['admin'] ? ' — and, as the admin, every private one' : '') . '.'], ['open', 'Open to join', 'Anyone in the business may join these.'], ['closed', 'Closed', 'Ask to join; an owner lets you in.']] as [$key, $title, $hint]): ?>
    <h6 class="fw-bold mt-3 mb-1" id="space-list-<?= $key ?>-title"><?= e($title) ?> <span class="fs-12 text-muted fw-normal">· <?= e($hint) ?></span></h6>
    <div class="row g-3 mb-2" id="space-list-<?= $key ?>">
        <?php if ($groups[$key] === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted fs-12" id="space-list-<?= $key ?>-empty"><?= $key === 'mine' ? 'You are in no space yet.' : ($key === 'open' ? 'No open space to join.' : 'No closed space to ask for.') ?></div></div></div><?php endif; ?>
        <?php foreach ($groups[$key] as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('spaces/partials/space-card.php', ['s' => $s, 'here' => $here, 'pending' => isset($pending[$s['space_id']]), 'may' => $may]) ?></div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
