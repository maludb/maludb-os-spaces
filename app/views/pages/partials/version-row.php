<?php /** One version row (page-history). Data: v, p, tz */ $vid = (int) $v['version_id']; ?>
<tr id="version-row-<?= $vid ?>">
    <td class="fw-semibold">v<?= (int) $v['version_no'] ?></td>
    <td class="fs-12"><?= e(format_ts($v['created_at'], $tz, 'M j, Y g:i A')) ?></td>
    <td><span class="badge bg-soft-secondary text-secondary"><?= e(str_replace('_', ' ', (string) $v['reason'])) ?></span></td>
    <td class="d-none d-md-table-cell fs-12 text-muted"><?= e($v['saved_by_name'] ?? 'the worker') ?></td>
    <td class="d-none d-md-table-cell fs-12 text-muted"><?= (int) $v['text_length'] ?> chars</td>
    <td class="text-end fs-12 text-muted">open · restore <span class="fs-11">(slice 3)</span></td>
</tr>
