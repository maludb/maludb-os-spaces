<?php /** One member of a space, as a table row (space-view, space-members). Data: m, s, may (owner), here, compact? */ $mid = (int) $m['member_id']; $sid = (int) $s['space_id']; $compact = $compact ?? false; ?>
<tr id="member-row-<?= $mid ?>">
    <td class="text-nowrap"><span class="fw-semibold"><?= e($m['display_name']) ?></span>
        <?php if ($m['is_agent']): ?> <span class="badge bg-soft-info text-info">agent</span><?php endif; ?>
        <?php if ($m['is_guest']): ?> <span class="badge bg-soft-warning text-warning">guest</span><?php endif; ?>
        <?php if ($m['derived']): ?> <span class="badge bg-soft-light text-dark" title="A member through the department">via department</span><?php endif; ?></td>
    <td><span class="badge bg-soft-<?= $m['role'] === 'owner' ? 'primary text-primary' : 'secondary text-secondary' ?>" id="member-row-<?= $mid ?>-role"><?= e($m['role']) ?></span></td>
    <?php if (!$compact): ?><td class="d-none d-md-table-cell fs-12 text-muted"><?= e($m['joined_at'] !== null ? format_date($m['joined_at']) : '') ?></td><?php endif; ?>
    <?php if (!$compact && $may['owner']): ?>
    <td class="text-end text-nowrap">
        <?php if (!$m['derived'] || $m['role'] === 'owner'): ?>
        <form method="post" action="/spaces/members/owner.php" hx-post="/spaces/members/owner.php" hx-target="#flash" hx-confirm="<?= $m['role'] === 'owner' ? 'Step ' . e($m['display_name']) . ' down as owner?' : 'Make ' . e($m['display_name']) . ' an owner of ' . e($s['name']) . '?' ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $sid ?>"><input type="hidden" name="member" value="<?= $mid ?>"><input type="hidden" name="owner" value="<?= $m['role'] === 'owner' ? 'no' : 'yes' ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="member-row-<?= $mid ?>-owner-btn"><?= $m['role'] === 'owner' ? 'Remove owner' : 'Make owner' ?></button></form>
        <?php endif; ?>
        <?php if (!$m['derived'] && !$s['is_default']): ?>
        <form method="post" action="/spaces/members/remove.php" hx-post="/spaces/members/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($m['display_name']) ?> from <?= e($s['name']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="space" value="<?= $sid ?>"><input type="hidden" name="member" value="<?= $mid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="member-row-<?= $mid ?>-remove-btn" aria-label="Remove <?= e($m['display_name']) ?>"><i class="feather-x"></i></button></form>
        <?php endif; ?>
    </td>
    <?php endif; ?>
</tr>
