<?php /** A space's home (screen `space-view`). Data: s, home (sections, pages, channels, members, wiki), request, pendingCount, timeline, may, here, tz, notice */
$id = (int) $s['space_id']; $archived = $s['archived_at'] !== null; $kindClass = ['open' => 'success', 'closed' => 'secondary', 'private' => 'dark'][$s['kind']] ?? 'secondary';
$buttons = '';
if ($may['owner'] && !$archived) {
    $buttons .= hx_link(with_back('/spaces/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="space-view-edit-btn"') . ' ';
}
if ($may['owner']) {
    $buttons .= hx_link(with_back('/spaces/' . $id . '/members', $here), 'Members', 'btn btn-light btn-touch', 'id="space-view-members-btn"') . ' '
        . hx_link(with_back('/spaces/' . $id . '/sections', $here), 'Sections', 'btn btn-light btn-touch', 'id="space-view-sections-btn"') . ' '
        . ($s['kind'] === 'closed' ? hx_link(with_back('/spaces/' . $id . '/requests', $here), 'Requests' . ($pendingCount > 0 ? ' <span class="badge bg-danger">' . (int) $pendingCount . '</span>' : ''), 'btn btn-light btn-touch', 'id="space-view-requests-btn"') . ' ' : '')
        . hx_link(with_back('/spaces/' . $id . '/templates', $here), 'Templates', 'btn btn-light btn-touch', 'id="space-view-templates-btn"');
}
?>
<?= view('shared/header.php', ['id' => 'space-view', 'title' => ($s['icon'] !== null && $s['icon'] !== '' ? $s['icon'] . ' ' : '') . $s['name'], 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], null]], 'back' => back_link()]) ?>
<div class="main-content" id="space-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="space-home-head"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="badge bg-soft-<?= $kindClass ?> text-<?= $kindClass ?>" id="space-view-kind"><?= e($s['kind']) ?></span>
            <?php if ($s['is_default']): ?><span class="badge bg-soft-info text-info">everyone is here</span><?php endif; ?>
            <?php if ($s['department_id'] !== null): ?><span class="badge bg-soft-light text-dark"><?= e($s['department_name']) ?> department</span><?php endif; ?>
            <?php if ($s['i_am_owner']): ?><span class="badge bg-soft-primary text-primary" id="space-view-owner-chip">you own it</span><?php elseif ($s['i_am_member']): ?><span class="badge bg-soft-secondary text-secondary" id="space-view-member-chip">you are in it</span><?php endif; ?>
            <?php if ($s['is_wiki']): ?><span class="badge bg-soft-warning text-warning">wiki · verify every <?= (int) $s['wiki_default_verify_months'] ?> months</span><?php endif; ?>
            <?php if ($archived): ?><span class="badge bg-dark" id="space-view-archived">archived</span><?php endif; ?>
            <span class="fs-12 text-muted ms-auto" id="space-view-facts"><?= (int) $s['member_count'] ?> members · <?= (int) $s['page_count'] ?> pages · <?= (int) $s['channel_count'] ?> channels<?= $s['owner_names'] ? ' · owned by ' . e($s['owner_names']) : '' ?></span>
        </div>
        <?php if (($s['description'] ?? '') !== ''): ?><div class="message-body" id="space-view-description"><?= e($s['description']) ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-3" id="space-view-actions">
            <?php if ($may['join']): ?><form method="post" action="/spaces/join.php" hx-post="/spaces/join.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch" id="space-view-join-btn">Join</button></form><?php endif; ?>
            <?php if ($request !== null && $request['status'] === 'pending'): ?>
                <form method="post" action="/spaces/request-withdraw.php" hx-post="/spaces/request-withdraw.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="space-view-withdraw-btn">Requested · withdraw</button></form>
            <?php endif; ?>
            <?php if ($may['leave']): ?><form method="post" action="/spaces/leave.php" hx-post="/spaces/leave.php" hx-target="#flash" hx-confirm="Leave <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="space-view-leave-btn">Leave</button></form><?php endif; ?>
            <?= $buttons ?>
            <?php if ($may['owner'] && !$archived && !$s['is_default']): ?><form method="post" action="/spaces/archive.php" hx-post="/spaces/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($s['name']) ?>? Everything stays readable; nothing changes in it until it is restored."><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="space-view-archive-btn">Archive</button></form><?php endif; ?>
            <?php if ($may['owner'] && $archived): ?><form method="post" action="/spaces/restore.php" hx-post="/spaces/restore.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch" id="space-view-restore-btn">Restore</button></form><?php endif; ?>
            <?php if ($may['delete']): ?><form method="post" action="/spaces/delete.php" hx-post="/spaces/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($s['name']) ?> for good? Its channels go with it."><?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="space-view-delete-btn">Delete</button></form><?php endif; ?>
        </div>
        <?php if ($may['request']): ?>
        <form method="post" action="/spaces/request.php" hx-post="/spaces/request.php" hx-target="#flash" id="request" class="row g-2 mt-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>">
            <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="space-request-field-message">Ask to join — a word for the owners (optional)</label><input type="text" name="message" id="space-request-field-message" class="form-control btn-touch" maxlength="500"></div>
            <div class="col-12 col-md-4"><button type="submit" class="btn btn-outline-primary btn-touch w-100" id="space-view-request-btn">Request to join</button></div>
        </form>
        <?php endif; ?>
        <?php if ($request !== null && $request['status'] !== 'pending'): ?><div class="fs-12 text-muted mt-2" id="space-view-request-state">Your last request was <?= e($request['status']) ?>.</div><?php endif; ?>
    </div></div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3" id="space-home-sections"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Pages</h5>
                <?php if ($may['write']): ?><?= hx_link('/pages/new?space=' . $id, '<i class="feather-plus me-1"></i>New page', 'btn btn-light btn-sm btn-touch ms-auto', 'id="space-view-new-page-btn"') ?><?php endif; ?></div>
                <div class="card-body">
                    <?php if ($home['sections'] === [] && $home['pages'] === []): ?><div class="text-muted fs-12" id="space-home-pages-empty">No page yet<?= $may['write'] ? ' — make the first one' : '' ?>.</div><?php endif; ?>
                    <?php foreach ($home['sections'] as $sec): ?>
                        <h6 class="fw-bold mt-2 mb-2" id="space-home-section-<?= (int) $sec['section_id'] ?>"><?= e($sec['name']) ?></h6>
                        <?php if ($sec['pages'] === []): ?><div class="text-muted fs-12 mb-2">Nothing here yet.</div><?php endif; ?>
                        <div class="row g-2 mb-2"><?php foreach ($sec['pages'] as $p): ?><div class="col-12 col-md-6"><?= view('spaces/partials/page-card.php', ['p' => $p, 'here' => $here]) ?></div><?php endforeach; ?></div>
                    <?php endforeach; ?>
                    <?php if ($home['pages'] !== []): ?>
                        <?php if ($home['sections'] !== []): ?><h6 class="fw-bold mt-2 mb-2 text-muted" id="space-home-section-none">Other pages</h6><?php endif; ?>
                        <div class="row g-2"><?php foreach ($home['pages'] as $p): ?><div class="col-12 col-md-6"><?= view('spaces/partials/page-card.php', ['p' => $p, 'here' => $here]) ?></div><?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mb-3" id="space-home-channels"><div class="card-header"><h5 class="card-title mb-0">Channels</h5></div>
                <div class="list-group list-group-flush">
                    <?php if ($home['channels'] === []): ?><div class="list-group-item text-muted fs-12" id="space-home-channels-empty">No channel you may see.</div><?php endif; ?>
                    <?php foreach ($home['channels'] as $c): ?>
                        <?= hx_link(with_back('/channels/' . (int) $c['channel_id'], $here), '<span class="fw-semibold">' . ($c['kind'] === 'private' ? '<i class="feather-lock fs-12 me-1"></i>' : '#') . e($c['name']) . '</span>' . ($c['is_default'] ? ' <span class="badge bg-soft-info text-info">default</span>' : '') . (($c['topic'] ?? '') !== '' ? ' <span class="fs-12 text-muted">· ' . e($c['topic']) . '</span>' : '') . '<span class="fs-12 text-muted float-end">' . (int) $c['member_count'] . ' following</span>', 'list-group-item list-group-item-action', 'id="space-home-channel-' . (int) $c['channel_id'] . '"') ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <?php if ($home['wiki'] !== null): ?>
            <div class="card mb-3" id="space-home-wiki"><div class="card-header"><h5 class="card-title mb-0">The wiki</h5></div><div class="card-body fs-12">
                <div><span class="badge bg-soft-success text-success"><?= (int) $home['wiki']['verified'] ?> verified</span> <span class="badge bg-soft-warning text-warning"><?= (int) $home['wiki']['expired'] ?> expired</span> <span class="badge bg-soft-secondary text-secondary"><?= (int) $home['wiki']['never'] ?> never</span></div>
                <div class="mt-2"><?= hx_link(with_back('/spaces/' . $id . '/wiki', $here), 'Open the wiki', 'fw-semibold', 'id="space-view-wiki-link"') ?> <span class="text-muted">(slice 2)</span></div>
            </div></div>
            <?php endif; ?>
            <div class="card mb-3" id="space-home-members"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Members</h5><span class="fs-12 text-muted ms-auto"><?= count($home['members']) ?></span></div>
                <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><tbody id="space-home-members-list">
                    <?php foreach (array_slice($home['members'], 0, 12) as $m): ?><?= view('spaces/partials/member-row.php', ['m' => $m, 's' => $s, 'may' => $may, 'here' => $here, 'compact' => true]) ?><?php endforeach; ?>
                </tbody></table></div>
                <?php if (count($home['members']) > 12 || $may['owner']): ?><div class="card-body pt-2"><?= hx_link(with_back('/spaces/' . $id . '/members', $here), 'Everyone' . ($may['owner'] ? ' · manage' : ''), 'fs-12 fw-semibold', 'id="space-home-members-all"') ?></div><?php endif; ?>
            </div>
            <?php if ($may['owner'] && $timeline !== []): ?><?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'space']) ?><?php endif; ?>
        </div>
    </div>
</div>
