<?php /** A database through a view (screen `database-view`). Data: d, view, schema, body (the layout), may, q, here, base, members, tz, notice */ $did = $d['database_id']; ?>
<div class="page-header" id="database-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate"><?= e(($d['icon'] ?? '') !== '' ? $d['icon'] . ' ' : '▦ ') ?><?= e($d['plain_title'] !== '' ? $d['plain_title'] : 'Untitled') ?></h5></div>
    </div>
    <div class="page-header-right ms-auto d-flex align-items-center gap-2">
        <?php if ($may['schema']): ?><?= hx_link(with_back('/databases/' . $did . '/schema', $here) . '#database-details', '<i class="feather-edit-2 me-1"></i>Rename', 'btn btn-light btn-touch d-none d-sm-inline-flex', 'id="database-view-rename-btn"') ?><?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="database-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="database-view-content" data-database="<?= e($did) ?>" data-layout="<?= e($view['layout']) ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($d['archived_at'] !== null): ?><div class="alert alert-dark" id="database-trashed"><i class="feather-trash-2 me-1"></i>This database is in the trash. Restore it from Trash (Pages).</div><?php endif; ?>
    <?php if (!empty($d['breadcrumb'])): ?><div class="fs-12 text-muted mb-2" id="database-breadcrumb"><?php foreach ($d['breadcrumb'] as $c): ?><?= $c['visible'] ? hx_link('/pages/' . $c['page_id'], e($c['title'] ?: 'Untitled')) : e($c['title'] ?: 'Untitled') ?> › <?php endforeach; ?></div><?php endif; ?>
    <?php if ($d['description'] !== []): ?><p class="text-muted" id="database-description"><?= e(display_value('rich_text', $d['description'])) ?></p><?php endif; ?>
    <?= view('databases/partials/toolbar.php', ['d' => $d, 'views' => $d['views'], 'view' => $view, 'may' => $may, 'here' => $here, 'q' => $q, 'schema' => $schema, 'members' => $members, 'base' => $base]) ?>
    <?= $body ?>
</div>
<link rel="stylesheet" href="/assets/css/databases.css">
<script src="/assets/js/databases.js"></script>
