<?php /** The templates a space may apply (screen `space-templates`). Data: s, rows, here, tz */ $id = (int) $s['space_id']; ?>
<?= view('shared/header.php', ['id' => 'space-templates', 'title' => $s['name'] . ' · Templates', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $id], ['Templates', null]], 'back' => back_link() ?? ['/spaces/' . $id, $s['name']]]) ?>
<div class="main-content" id="space-templates-content">
    <?php if ($rows === []): ?><div class="card"><div class="card-body"><div class="empty-state" id="space-templates-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-copy"></i></span><div><div class="fw-semibold">No template yet</div><div class="fs-12 text-muted">A page becomes a template from its menu (slice 2); the workspace's templates show here too.</div></div></div></div></div><?php endif; ?>
    <div class="row g-3" id="space-templates-cards">
        <?php foreach ($rows as $t): $pid = (string) $t['page_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="template-card-<?= e($pid) ?>"><div class="card-body p-3">
            <div class="fw-semibold text-truncate"><?= hx_link(with_back('/pages/' . $pid, $here), e(($t['icon'] ?? '') !== '' ? $t['icon'] . ' ' : '') . e($t['title'] !== '' ? $t['title'] : 'Untitled'), 'text-dark') ?></div>
            <div class="fs-12 text-muted mt-1"><?= e(ucfirst((string) $t['kind'])) ?> template · <?= $t['space_id'] === null ? 'the workspace' : 'this space' ?> · <?= e(format_date($t['last_edited_at'])) ?></div>
            <div class="mt-2"><?= hx_link(with_back('/pages/' . $pid, $here), 'Open', 'btn btn-light btn-touch w-100') ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
