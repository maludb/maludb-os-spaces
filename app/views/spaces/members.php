<?php /** Who is in a space (screen `space-members`). Data: s, members, picks, departments, may (owner), here, tz, notice */ $id = (int) $s['space_id']; ?>
<?= view('shared/header.php', ['id' => 'space-members', 'title' => $s['name'] . ' · Members', 'crumbs' => [['Home', '/'], ['Spaces', '/spaces/'], [$s['name'], '/spaces/' . $id], ['Members', null]], 'back' => back_link() ?? ['/spaces/' . $id, $s['name']]]) ?>
<div class="main-content" id="space-members-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['owner']): ?>
    <div class="card mb-3" id="space-members-add"><div class="card-header"><h5 class="card-title mb-0">Add to <?= e($s['name']) ?></h5></div><div class="card-body">
        <form method="post" action="/spaces/members/add.php" hx-post="/spaces/members/add.php" hx-target="#flash" id="member-add-form" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="space" value="<?= $id ?>"><input type="hidden" name="return_to" value="/spaces/<?= $id ?>/members">
            <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="member-add-field-member">A person or an agent</label>
                <select name="member" id="member-add-field-member" class="form-select btn-touch"><option value="">—</option><?php foreach ($picks as $p): ?><option value="<?= (int) $p['member_id'] ?>"><?= e($p['display_name']) ?><?= $p['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="member-add-field-department">or a whole department</label>
                <select name="department" id="member-add-field-department" class="form-select btn-touch"><option value="">—</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="member-add-field-role">As</label><select name="role" id="member-add-field-role" class="form-select btn-touch"><option value="member">member</option><option value="owner">owner</option></select></div>
            <div class="col-6 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="member-add-btn">Add</button></div>
        </form>
    </div></div>
    <?php endif; ?>
    <div class="card" id="space-members-card"><div class="table-responsive"><table class="table table-hover mb-0" id="space-members-table">
        <thead class="thead-light"><tr><th>Who</th><th>Role</th><th class="d-none d-md-table-cell">Since</th><?php if ($may['owner']): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
            <?php if ($members === []): ?><tr><td colspan="4" class="text-muted text-center py-4" id="space-members-empty">Nobody yet.</td></tr><?php endif; ?>
            <?php foreach ($members as $m): ?><?= view('spaces/partials/member-row.php', ['m' => $m, 's' => $s, 'may' => $may, 'here' => $here]) ?><?php endforeach; ?>
        </tbody>
    </table></div></div>
    <?php if ($s['department_id'] !== null): ?><div class="fs-12 text-muted mt-2" id="space-members-derived-note">The <?= e($s['department_name']) ?> department's people are here through the directory ("via department"): they join and leave with it, in HR.</div><?php endif; ?>
</div>
