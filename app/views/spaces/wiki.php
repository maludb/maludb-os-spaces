<?php /** A wiki space's status (screen `wiki-view`). Data: s, rows, me, may (owner, verify_any), here, tz, notice */ $id = (int) $s['space_id']; $badge = ['verified' => 'success', 'expired' => 'warning', 'none' => 'secondary']; ?>
<?= view('shared/header.php', ['id' => 'wiki-view', 'title' => $s['name'] . ' · Wiki', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $id], ['Wiki', null]], 'back' => back_link() ?? ['/spaces/' . $id, $s['name']]]) ?>
<div class="main-content" id="wiki-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card" id="wiki-card"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Every page, the expired first</h5><span class="fs-12 text-muted ms-auto">verify every <?= (int) $s['wiki_default_verify_months'] ?> months</span></div>
    <div class="table-responsive"><table class="table table-hover mb-0" id="wiki-table">
        <thead class="thead-light"><tr><th>Page</th><th>State</th><th class="d-none d-md-table-cell">Owner</th><th class="d-none d-md-table-cell">Until</th><th class="d-none d-md-table-cell">Edited</th><th></th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-muted text-center py-4" id="wiki-empty">No page in this wiki yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $w): $pid = (string) $w['page_id']; $mine = $w['wiki_owner_member_id'] === $me; ?>
        <tr id="wiki-row-<?= e($pid) ?>">
            <td><?= hx_link(with_back('/pages/' . $pid, $here), e($w['title'] ?: 'Untitled'), 'fw-semibold text-dark') ?></td>
            <td><span class="badge bg-soft-<?= $badge[$w['verification_state']] ?? 'secondary' ?> text-<?= $badge[$w['verification_state']] ?? 'secondary' ?>" id="wiki-row-<?= e($pid) ?>-state"><?= e($w['verification_state'] === 'none' ? 'unverified' : $w['verification_state']) ?></span></td>
            <td class="d-none d-md-table-cell fs-12"><?= e($w['owner_name'] ?? '—') ?></td>
            <td class="d-none d-md-table-cell fs-12 text-muted"><?= $w['verify_until'] ? e(format_date($w['verify_until'])) : '—' ?></td>
            <td class="d-none d-md-table-cell fs-12 text-muted"><?= (int) $w['days_since_edit'] ?> days ago</td>
            <td class="text-end"><?php if ($mine || $may['verify_any']): ?><form method="post" action="/pages/verify.php" hx-post="/pages/verify.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/wiki"><button type="submit" class="btn btn-light btn-sm btn-touch" id="wiki-row-<?= e($pid) ?>-verify-btn">Verify</button></form><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2">Stale, orphaned, duplicated and broken pages are the reports of slice 6.</div>
</div>
