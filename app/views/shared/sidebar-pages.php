<?php
/**
 * A list of pages in the sidebar's tree (the root pages of a section or a space, Private, or a page's children loaded lazily
 * by html/pages/children.php). Data: pages = [{page_id, title, icon, kind, has_children}], here (the current path).
 */
$here = $here ?? current_path();
?>
<?php foreach ($pages as $p): $pid = (string) $p['page_id']; $url = '/pages/' . $pid; ?>
    <li class="sp-tree-item" id="sidebar-page-<?= e($pid) ?>">
        <div class="sp-tree-row">
            <?php if (!empty($p['has_children'])): ?>
                <button type="button" class="sp-tree-toggle" aria-expanded="false" aria-controls="sidebar-children-<?= e($pid) ?>" aria-label="Open"
                        hx-get="/pages/children.php?page=<?= e($pid) ?>" hx-target="#sidebar-children-<?= e($pid) ?>" hx-swap="innerHTML" hx-trigger="click once">&#9656;</button>
            <?php else: ?><span class="sp-tree-toggle leaf"></span><?php endif; ?>
            <a href="<?= e($url) ?>" class="<?= $here === $url ? 'active' : '' ?>" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($url) ?>">
                <span><?= e($p['icon'] ?: (($p['kind'] ?? '') === 'database' ? '▦' : '▫')) ?></span><span class="sp-tree-label"><?= e($p['title'] !== '' ? $p['title'] : 'Untitled') ?></span>
            </a>
        </div>
        <?php if (!empty($p['has_children'])): ?><ul class="sp-tree-children" id="sidebar-children-<?= e($pid) ?>" hidden></ul><?php endif; ?>
    </li>
<?php endforeach; ?>
