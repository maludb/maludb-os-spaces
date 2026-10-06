<?php /** Home (screen `home`). Data: s (home_summary), tz, here. The phone order: Unread, Waiting for me, Needs my verification, Pending joins, Recently edited, Favorites, the note, Unanswered, the admin's cards. */
$may = $s['may'];
$sb = $s['sidebar'] ?? [];
$d = ['s' => $s, 'tz' => $tz, 'here' => $here];
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="row g-3" id="home-regions">
        <div class="col-12 col-lg-6"><?= view('home/partials/unread.php', $d) ?></div>
        <div class="col-12 col-lg-6"><?= view('home/partials/waiting.php', $d) ?></div>
        <div class="col-12 col-lg-6"><?= view('home/partials/verification.php', $d) ?></div>
        <?php if ($s['pending_joins'] !== null): ?><div class="col-12 col-lg-6"><?= view('home/partials/joins.php', $d) ?></div><?php endif; ?>
        <div class="col-12 col-lg-6"><?= view('home/partials/recent.php', $d) ?></div>
        <div class="col-12 col-lg-6"><?= view('home/partials/favorites.php', $d) ?></div>
        <?php if ($s['librarian_note'] !== null): ?><div class="col-12 col-lg-6"><?= view('home/partials/note.php', $d) ?></div><?php endif; ?>
        <?php if ($s['unanswered'] !== null): ?><div class="col-12 col-lg-6"><?= view('home/partials/unanswered.php', $d) ?></div><?php endif; ?>
        <?php if ($s['admin'] !== null): ?><div class="col-12"><?= view('home/partials/admin.php', $d) ?></div><?php endif; ?>
        <div class="col-12 col-lg-6">
            <div class="card" id="home-spaces"><div class="card-header"><h5 class="card-title mb-0"><?= $may['guest'] ? 'Shared with you' : 'Your spaces' ?></h5></div>
                <div class="list-group list-group-flush" id="home-spaces-list">
                <?php if ($may['guest']): ?>
                    <?php if (($sb['shared'] ?? []) === []): ?><div class="list-group-item text-muted fs-12" id="home-shared-empty">Pages shared with you appear here.</div><?php endif; ?>
                    <?php foreach ($sb['shared'] ?? [] as $p): ?><div class="list-group-item" id="home-shared-<?= e((string) $p['page_id']) ?>"><?= hx_link(with_back('/pages/' . $p['page_id'], $here), e((($p['icon'] ?? '') !== '' ? $p['icon'] . ' ' : '') . ($p['title'] !== '' ? $p['title'] : 'Untitled')), 'fw-semibold text-dark') ?></div><?php endforeach; ?>
                <?php else: ?>
                    <?php if (($sb['spaces'] ?? []) === []): ?><div class="list-group-item text-muted fs-12" id="home-spaces-empty">You are in no space yet.</div><?php endif; ?>
                    <?php foreach ($sb['spaces'] ?? [] as $sp): ?>
                        <div class="list-group-item" id="home-space-<?= (int) $sp['space_id'] ?>">
                            <div class="fw-semibold"><?= hx_link(with_back('/spaces/' . $sp['space_id'], $here), e(($sp['icon'] ?? '') . ' ' . $sp['name']), 'text-dark') ?> <span class="badge bg-soft-secondary text-dark"><?= e($sp['kind']) ?></span><?= !empty($sp['is_owner']) ? ' <span class="badge bg-soft-primary text-primary">owner</span>' : '' ?></div>
                            <div class="fs-12 text-muted"><?= count($sp['pages'] ?? []) ?> page<?= count($sp['pages'] ?? []) === 1 ? '' : 's' ?> · <?php foreach ($sp['channels'] ?? [] as $ch): ?><span class="me-2">#<?= e($ch['name']) ?></span><?php endforeach; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
