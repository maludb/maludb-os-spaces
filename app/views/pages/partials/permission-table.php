<?php /** Who sees the page and why (sp_page_permissions_explained). Data: p, rows, may (full), here */ $pid = (string) $p['page_id']; $words = ['view' => 'View', 'comment' => 'Comment', 'edit_content' => 'Edit content', 'edit' => 'Edit', 'full' => 'Full', 'none' => 'Nothing']; ?>
<div class="table-responsive"><table class="table table-hover mb-0" id="permission-table">
    <thead class="thead-light"><tr><th>Who</th><th>Level</th><th class="d-none d-md-table-cell">Why</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="4" class="text-muted text-center py-4" id="permission-empty">Nobody but you.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $r): $explicit = $r['source'] === 'this page' && in_array($r['principal_kind'], ['member', 'agent', 'department', 'guest'], true); ?>
        <tr id="permission-row-<?= $i + 1 ?>">
            <td><?= e($r['principal_name'] ?? '') ?>
                <?php if ($r['principal_kind'] === 'agent'): ?> <span class="badge bg-soft-info text-info">agent</span><?php elseif ($r['principal_kind'] === 'guest'): ?> <span class="badge bg-soft-warning text-warning">guest</span><?php elseif ($r['principal_kind'] === 'department'): ?> <span class="badge bg-soft-light text-dark">department</span><?php elseif ($r['principal_kind'] === 'everyone_in_space'): ?> <span class="badge bg-soft-secondary text-secondary">space</span><?php elseif ($r['principal_kind'] === 'everyone'): ?> <span class="badge bg-soft-secondary text-secondary">business</span><?php endif; ?></td>
            <td><span class="badge bg-soft-<?= $r['level'] === 'full' ? 'primary text-primary' : 'secondary text-secondary' ?>"><?= e($words[$r['level']] ?? $r['level']) ?></span></td>
            <td class="d-none d-md-table-cell fs-12 text-muted"><?= $r['source'] === 'inherited' ? 'inherited from ' . ($r['source_page_id'] ? hx_link(with_back('/pages/' . $r['source_page_id'] . '/share', $here), e($r['source_title'] ?: 'Untitled')) : e($r['source_title'])) : e($r['source'] === 'this page' ? 'shared on this page' : $r['source'] . ($r['source_title'] ? ' · ' . $r['source_title'] : '')) ?></td>
            <td class="text-end">
                <?php if ($may['full'] && $explicit && $r['principal_id'] !== null): ?>
                <form method="post" action="/pages/unshare.php" hx-post="/pages/unshare.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="page" value="<?= e($pid) ?>"><input type="hidden" name="principal" value="<?= (int) $r['principal_id'] ?>"><input type="hidden" name="return_to" value="/pages/<?= e($pid) ?>/share">
                    <button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="permission-row-<?= $i + 1 ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
