<?php /** A page's breadcrumb (sp_page_ancestors). Data: p, here */ ?>
<nav class="fs-12 text-muted mb-2" id="page-breadcrumb" aria-label="Where this page is">
    <?php if ($p['is_private']): ?><span>Private</span><?php elseif ($p['space_id'] !== null): ?><?= hx_link(with_back('/spaces/' . (int) $p['space_id'], $here), e($p['space_name'] ?? 'the space')) ?><?php else: ?><span>Shared with me</span><?php endif; ?>
    <?php foreach ($p['breadcrumb'] ?? [] as $c): ?> › <?= $c['visible'] ? hx_link(with_back('/pages/' . $c['page_id'], $here), e($c['title'] ?: 'Untitled')) : '<span>' . e($c['title'] ?: 'Untitled') . '</span>' ?><?php endforeach; ?>
</nav>
