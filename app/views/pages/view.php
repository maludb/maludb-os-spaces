<?php /** The reader (screen `page-view`). Data: p, bodyHtml, pub, may, here, tz, notice */ $pid = (string) $p['page_id']; ?>
<div class="page-header" id="page-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate"><?= e($p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled') ?></h5></div>
    </div>
    <div class="page-header-right ms-auto d-flex align-items-center gap-2">
        <?php if ($may['edit']): ?><?= hx_link(with_back('/pages/' . $pid . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit title', 'btn btn-light btn-touch d-none d-sm-inline-flex', 'id="page-view-edit-btn"') ?><?php endif; ?>
        <?= view('pages/partials/page-menu.php', ['p' => $p, 'may' => $may, 'pub' => $pub, 'here' => $here]) ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="page-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="page-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($p['archived_at'] !== null): ?><div class="alert alert-dark" id="page-view-trashed"><i class="feather-trash-2 me-1"></i>This page is in the trash<?= $p['archived_at'] ? ' since ' . e(format_date($p['archived_at'])) : '' ?>. Restore it from the menu.</div><?php endif; ?>
    <article class="card" id="page-article"><div class="card-body sp-page">
        <?= view('pages/partials/breadcrumb.php', ['p' => $p, 'here' => $here]) ?>
        <?php if ($p['cover_attachment_id'] !== null): ?><div class="sp-cover mb-3" id="page-cover"><img src="/files/<?= (int) $p['cover_attachment_id'] ?>" alt=""></div><?php endif; ?>
        <div class="d-flex align-items-start gap-2 mb-2">
            <?php if (($p['icon'] ?? '') !== ''): ?><span class="sp-page-icon" id="page-icon"><?= e($p['icon']) ?></span><?php endif; ?>
            <h1 class="sp-page-title mb-0" id="page-title"><?= e($p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled') ?></h1>
        </div>
        <div class="d-flex flex-wrap gap-1 mb-3 fs-12" id="page-badges">
            <?php if ($p['is_locked']): ?><span class="badge bg-dark" id="page-badge-locked"><i class="feather-lock"></i> locked</span><?php endif; ?>
            <?php if ($p['is_published']): ?><span class="badge bg-soft-primary text-primary" id="page-badge-published"><i class="feather-globe"></i> published</span><?php endif; ?>
            <?php if ($p['verification_state'] === 'verified'): ?><span class="badge bg-soft-success text-success" id="page-badge-verified">verified<?= $p['verified_at'] ? ' ' . e(format_date($p['verified_at'])) : '' ?><?= $p['verify_until'] ? ' · until ' . e(format_date($p['verify_until'])) : '' ?></span>
            <?php elseif ($p['verification_state'] === 'expired'): ?><span class="badge bg-soft-warning text-warning" id="page-badge-expired">verification expired<?= $p['verify_until'] ? ' ' . e(format_date($p['verify_until'])) : '' ?></span>
            <?php elseif ($p['wiki_owner_member_id'] !== null): ?><span class="badge bg-soft-secondary text-secondary" id="page-badge-unverified">unverified</span><?php endif; ?>
            <?php if ($p['permission_root_id'] === $pid && $p['space_id'] !== null): ?><span class="badge bg-soft-warning text-warning" id="page-badge-restricted">restricted</span><?php endif; ?>
            <?php if ($p['is_favorite']): ?><span class="badge bg-soft-warning text-warning" id="page-badge-favorite">★ favorite</span><?php endif; ?>
            <?php if ($p['is_template']): ?><span class="badge bg-soft-info text-info" id="page-badge-template">template</span><?php endif; ?>
            <?php if ($p['wiki_owner_member_id'] !== null): ?><span class="text-muted ms-1" id="page-wiki-owner">owner: <?= e($p['wiki_owner_name']) ?></span><?php endif; ?>
            <span class="text-muted ms-auto" id="page-edited">edited <?= e(format_ts($p['last_edited_at'], $tz, 'M j, g:i A')) ?><?= $p['editor_name'] ? ' by ' . e($p['editor_name']) : '' ?></span>
        </div>
        <?php if ($p['is_row']): ?><div class="alert alert-light border fs-12" id="page-row-note">A row of a database — its properties panel is slice 5's.</div><?php endif; ?>
        <div class="sp-body" id="page-body"><?= $bodyHtml !== '' ? $bodyHtml : '<p class="text-muted" id="page-body-empty">An empty page' . ($may['edit'] ? ' — the editor is slice 3; until then, a page starts from its form' : '') . '.</p>' ?></div>
        <?php if ($p['open_comment_count'] > 0): ?><div class="fs-12 text-muted mt-3" id="page-comments-count"><i class="feather-message-square me-1"></i><?= (int) $p['open_comment_count'] ?> open discussion<?= $p['open_comment_count'] === 1 ? '' : 's' ?> (slice 3 shows them)</div><?php endif; ?>
    </div></article>
</div>
