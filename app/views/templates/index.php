<?php /** The templates gallery (screen `template-list`). Data: rows, owned (space_id => bool), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'template-list', 'title' => 'Templates', 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], ['Templates', null]], 'back' => back_link() ?? ['/pages/', 'Pages']]) ?>
<div class="main-content" id="template-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="template-list-empty">No template yet. A space owner makes one from a page's menu.</div></div><?php endif; ?>
    <div class="row g-3" id="template-cards">
        <?php foreach ($rows as $t): $pid = (string) $t['page_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="template-card-<?= e($pid) ?>"><div class="card-body p-3 d-flex flex-column">
            <div class="fw-bold text-truncate"><?= hx_link(with_back('/pages/' . $pid, $here), e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['plain_title'] ?: 'Untitled'), 'text-dark') ?></div>
            <div class="fs-12 text-muted mt-1"><span class="badge bg-soft-<?= $t['kind'] === 'database' ? 'info text-info' : 'secondary text-secondary' ?>"><?= e($t['kind']) ?></span> <?= $t['is_default'] ? 'the workspace' : e($t['space_name'] ?? '') ?></div>
            <div class="d-flex gap-2 mt-auto pt-2">
                <?= hx_link('/pages/new?template=' . $pid, 'Use', 'btn btn-primary btn-touch flex-grow-1', 'id="template-card-' . e($pid) . '-use-btn"') ?>
                <?php if (!empty($owned[$t['space_id']])): ?><form method="post" action="/pages/template-publish.php" hx-post="/pages/template-publish.php" hx-target="#flash" hx-confirm="Stop <?= e($t['plain_title']) ?> being a template?"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="template" value="no"><input type="hidden" name="return_to" value="/templates/"><button type="submit" class="btn btn-light btn-touch" id="template-card-<?= e($pid) ?>-stop-btn">Stop</button></form><?php endif; ?>
            </div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
