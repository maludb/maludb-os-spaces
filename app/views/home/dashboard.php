<?php /** Home (screen `home`, sso-shell.md). Data: s (home_summary), tz. Each region is an empty state naming its slice until it ships; the spaces card is real (sp_sidebar()). */
$may = $s['may'];
$sb = $s['sidebar'] ?? [];
$coming = static fn (string $id, string $icon, string $title, string $text, string $slice): string =>
    '<div class="card mb-3" id="' . e($id) . '"><div class="card-body"><div class="empty-state" id="' . e($id) . '-empty"><span class="avatar-text avatar-lg rounded"><i class="' . e($icon) . '"></i></span>'
    . '<div><div class="fw-semibold">' . e($title) . '</div><div class="fs-12 text-muted">' . e($text) . ' <span class="text-muted">(' . e($slice) . ')</span></div></div></div></div></div>';
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="row g-3">
        <div class="col-lg-6">
            <?= $s['unread'] === null ? $coming('home-unread', 'feather-hash', 'Unread', 'Channels with new messages, and the first unread line of each, appear here.', 'slice 4') : '' ?>
            <?= $s['mentions'] === null ? $coming('home-mentions', 'feather-at-sign', 'Waiting for you', 'Mentions and replies to you appear here.', 'slice 4') : '' ?>
            <?= $s['recent_pages'] === null ? $coming('home-recent', 'feather-file-text', 'Recently edited', 'Pages edited lately in your spaces appear here.', 'slice 2') : '' ?>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3" id="home-spaces"><div class="card-header"><h5 class="card-title mb-0">Your spaces</h5></div>
                <div class="list-group list-group-flush" id="home-spaces-list">
                <?php if (($sb['spaces'] ?? []) === []): ?>
                    <div class="list-group-item text-muted fs-12" id="home-spaces-empty">You are in no space yet.</div>
                <?php endif; ?>
                <?php foreach ($sb['spaces'] ?? [] as $sp): ?>
                    <div class="list-group-item" id="home-space-<?= (int) $sp['space_id'] ?>">
                        <div class="fw-semibold"><?= e(($sp['icon'] ?? '') . ' ' . $sp['name']) ?> <span class="badge bg-soft-secondary text-dark"><?= e($sp['kind']) ?></span><?= !empty($sp['is_owner']) ? ' <span class="badge bg-soft-primary text-primary">owner</span>' : '' ?></div>
                        <div class="fs-12 text-muted"><?= count($sp['pages'] ?? []) ?> page<?= count($sp['pages'] ?? []) === 1 ? '' : 's' ?> ·
                            <?php foreach ($sp['channels'] ?? [] as $ch): ?><span class="me-2">#<?= e($ch['name']) ?></span><?php endforeach; ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php if ($may['owner']): ?><?= $s['pending_joins'] === null ? $coming('home-joins', 'feather-user-plus', 'Requests to join', 'People asking to join a space you own appear here.', 'slice 1') : '' ?><?php endif; ?>
            <?= $s['verification_due'] === null ? $coming('home-verify', 'feather-check-circle', 'Pages to verify', 'Wiki pages you own whose verification expired appear here.', 'slice 6') : '' ?>
            <?php if ($may['admin']): ?><?= $s['admin'] === null ? $coming('home-admin', 'feather-shield', 'For the admin', 'Pending dispatches, published pages and the trash appear here.', 'slice 9') : '' ?><?php endif; ?>
        </div>
    </div>
</div>
