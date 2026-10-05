<?php /** Who is in a channel (screen `channel-members`). Data: c, members, cands (members, guests), may (add, guest, remove), me, here, tz, notice */ $cid = (int) $c['channel_id']; ?>
<?= view('shared/header.php', ['id' => 'channel-members', 'title' => 'Members of ' . $c['label'], 'crumbs' => [['Home', '/'], ['Channels', '/channels/'], [$c['label'], '/channels/' . $cid], ['Members', null]], 'back' => back_link() ?? ['/channels/' . $cid, $c['label']]]) ?>
<div class="main-content" id="channel-members-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7"><div class="card"><div class="card-header"><h5 class="card-title mb-0"><?= $c['kind'] === 'private' ? 'In the channel' : 'Following' ?> · <?= count($members) ?></h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0" id="channel-members-table"><thead><tr><th>Who</th><th class="d-none d-md-table-cell">Since</th><th class="text-end"></th></tr></thead><tbody>
            <?php foreach ($members as $m): $mid = $m['member_id']; ?>
            <tr id="channel-member-<?= $mid ?>"><td><span class="fw-semibold"><?= e($m['display_name']) ?></span><?= $m['is_agent'] ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?><?= $m['is_guest'] ? ' <span class="badge bg-soft-warning text-warning">guest</span>' : '' ?><?= $mid === $me ? ' <span class="fs-12 text-muted">(you)</span>' : '' ?><?= $m['is_active_now'] ? ' <span class="sp-dot-online" title="Active now"></span>' : '' ?></td>
                <td class="d-none d-md-table-cell text-muted fs-12"><?= e(format_ts($m['joined_at'], $tz, 'M j, Y')) ?></td>
                <td class="text-end"><?php if ($may['remove'] && $mid !== $me): ?><form method="post" action="/channels/members/remove.php" hx-post="/channels/members/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($m['display_name']) ?> from <?= e($c['label']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="member" value="<?= $mid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/members"><button type="submit" class="btn btn-light btn-sm btn-touch" id="channel-member-<?= $mid ?>-remove-btn">Remove</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div></div></div>
        <div class="col-lg-5">
            <?php if ($may['add']): ?>
            <form method="post" action="/channels/members/add.php" hx-post="/channels/members/add.php" hx-target="#flash" class="card mb-3" id="channel-member-add-form"><div class="card-header"><h5 class="card-title mb-0">Add a member or an agent</h5></div><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/members">
                <select name="member" class="form-select btn-touch mb-2" id="channel-member-add-field" required><option value="">Choose…</option><?php foreach ($cands['members'] as $m): ?><option value="<?= (int) $m['member_id'] ?>"><?= e($m['display_name']) ?><?= $m['is_agent'] ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="channel-member-add-btn">Add</button>
                <div class="fs-12 text-muted mt-2">Someone outside the space: the database says "Join the space first".</div>
            </div></form>
            <?php endif; ?>
            <?php if ($may['guest']): ?>
            <form method="post" action="/channels/members/add-guest.php" hx-post="/channels/members/add-guest.php" hx-target="#flash" hx-confirm="Add a guest to <?= e($c['label']) ?>? They will read everything in it." class="card" id="channel-guest-add-form"><div class="card-header"><h5 class="card-title mb-0">Add a guest</h5></div><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="channel" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/channels/<?= $cid ?>/members">
                <select name="guest" class="form-select btn-touch mb-2" id="channel-guest-add-field" required><option value="">Choose…</option><?php foreach ($cands['guests'] as $m): ?><option value="<?= (int) $m['member_id'] ?>"><?= e($m['display_name']) ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="channel-guest-add-btn">Add the guest</button>
            </div></form>
            <?php endif; ?>
        </div>
    </div>
</div>
