<?php /** Who sees the page and why (screen `page-share`). Data: p, rows, pub, picks, guests, departments, restricted, may (guest, publish), here, notice */ $pid = (string) $p['page_id']; ?>
<?= view('shared/header.php', ['id' => 'page-share', 'title' => 'Share ' . ($p['plain_title'] ?: 'the page'), 'crumbs' => [['Home', '/'], ['Pages', '/pages/'], [$p['plain_title'] ?: 'Untitled', '/pages/' . $pid], ['Share', null]], 'back' => back_link() ?? ['/pages/' . $pid, $p['plain_title'] ?: 'the page']]) ?>
<div class="main-content" id="page-share-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($restricted): ?><div class="alert alert-warning" id="page-share-restricted-note"><i class="feather-lock me-1"></i>Restricted: only the people named here reach <?= e($p['plain_title'] ?: 'this page') ?>.</div><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="page-share-table"><div class="card-header"><h5 class="card-title mb-0">Who sees it, and why</h5></div>
                <?= view('pages/partials/permission-table.php', ['p' => $p, 'rows' => $rows, 'may' => ['full' => true], 'here' => $here]) ?>
                <?php if ($p['space_id'] !== null): ?>
                <div class="card-body pt-2 d-flex gap-2">
                    <?php if (!$restricted): ?><form method="post" action="/pages/restrict.php" hx-post="/pages/restrict.php" hx-target="#flash" hx-confirm="Restrict <?= e($p['plain_title'] ?: 'this page') ?> to the people named here? The space's members lose it."><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><button type="submit" class="btn btn-light btn-touch" id="page-share-restrict-btn"><i class="feather-lock me-1"></i>Restrict access</button></form>
                    <?php else: ?><form method="post" action="/pages/unrestrict.php" hx-post="/pages/unrestrict.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><button type="submit" class="btn btn-light btn-touch" id="page-share-unrestrict-btn"><i class="feather-unlock me-1"></i>Unrestrict</button></form><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="page-share-add"><div class="card-header"><h5 class="card-title mb-0">Share with</h5></div><div class="card-body">
                <form method="post" action="/pages/share.php" hx-post="/pages/share.php" hx-target="#flash" id="share-form" class="row g-2">
                    <?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/share">
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="share-form-field-member">A person or an agent</label><select name="member" id="share-form-field-member" class="form-select btn-touch"><option value="">—</option><?php foreach ($picks as $m): ?><option value="<?= (int) $m['member_id'] ?>"><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="share-form-field-department">or a department</label><select name="department" id="share-form-field-department" class="form-select btn-touch"><option value="">—</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-7"><label class="form-label fs-12 text-muted" for="share-form-field-level">At</label><select name="level" id="share-form-field-level" class="form-select btn-touch"><?php foreach (PAGE_LEVEL_WORDS as $k => $w): ?><option value="<?= $k ?>" <?= $k === 'view' ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                    <div class="col-5 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-touch w-100" id="share-form-btn">Share</button></div>
                </form>
            </div></div>
            <?php if ($may['guest']): ?>
            <div class="card mb-3" id="page-share-guest"><div class="card-header"><h5 class="card-title mb-0">Share with a guest</h5></div><div class="card-body">
                <?php if ($guests === []): ?><div class="fs-12 text-muted">No guest in the directory yet (an external member holding Guest).</div><?php else: ?>
                <form method="post" action="/pages/share-guest.php" hx-post="/pages/share-guest.php" hx-target="#flash" hx-confirm="Share <?= e($p['plain_title'] ?: 'this page') ?> outside the business?" id="share-guest-form" class="row g-2">
                    <?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/share">
                    <div class="col-12"><select name="guest" id="share-guest-field-guest" class="form-select btn-touch" aria-label="Guest"><?php foreach ($guests as $g): ?><option value="<?= (int) $g['member_id'] ?>"><?= e($g['display_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-7"><select name="level" id="share-guest-field-level" class="form-select btn-touch" aria-label="Level"><?php foreach (GUEST_LEVELS as $k): ?><option value="<?= $k ?>"><?= e(PAGE_LEVEL_WORDS[$k]) ?></option><?php endforeach; ?></select></div>
                    <div class="col-5"><button type="submit" class="btn btn-outline-primary btn-touch w-100" id="share-guest-btn">Share</button></div>
                </form>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
            <div class="card mb-3" id="page-share-public"><div class="card-header"><h5 class="card-title mb-0">On the web</h5></div><div class="card-body fs-12">
                <?php if ($p['is_published']): ?><span class="badge bg-soft-primary text-primary"><i class="feather-globe"></i> published</span> <?php else: ?><span class="text-muted">Not published.</span> <?php endif; ?>
                <?php if ($may['publish']): ?><?= hx_link(with_back('/pages/' . $pid . '/publish', $here), $p['is_published'] ? 'Manage the link' : 'Publish', 'fw-semibold', 'id="page-share-publish-link"') ?><?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
