<?php /** A published page (screen `public-page`). Data: business, page, bodyHtml, children, crumbs, token, noindex, props, root */
ob_start(); ?>
<article class="card" id="public-page"><div class="card-body sp-page">
    <?php if ($crumbs !== []): ?><nav class="fs-12 text-muted mb-2" id="public-breadcrumb"><a href="/p/<?= e($token) ?>">Top</a><?php foreach ($crumbs as $c): ?><?php if ($c['page_id'] !== $root): ?> › <a href="/p/<?= e($token) ?>/<?= e($c['page_id']) ?>"><?= e($c['plain_title'] ?: 'Untitled') ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
    <div class="d-flex align-items-start gap-2 mb-3"><?php if (($page['icon'] ?? '') !== ''): ?><span class="sp-page-icon"><?= e($page['icon']) ?></span><?php endif; ?><h1 class="sp-page-title mb-0" id="public-title"><?= e($page['plain_title'] !== '' ? $page['plain_title'] : 'Untitled') ?></h1></div>
    <?php if ($props !== []): ?><dl class="row fs-12 mb-3" id="public-properties"><?php foreach ($props as $k => $v): ?><dt class="col-4 text-muted"><?= e((string) $k) ?></dt><dd class="col-8"><?= e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></dd><?php endforeach; ?></dl><?php endif; ?>
    <div class="sp-body" id="public-body"><?= $bodyHtml ?></div>
    <?php if ($children !== []): ?><h2 class="h6 mt-4">Pages inside</h2><ul id="public-children"><?php foreach ($children as $c): ?><li><a href="/p/<?= e($token) ?>/<?= e($c['page_id']) ?>"><?= e(($c['icon'] ?? '') !== '' ? $c['icon'] . ' ' : '📄 ') . e($c['plain_title'] ?: 'Untitled') ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</div></article>
<?php $content = ob_get_clean(); echo view('public/layout.php', ['business' => $business, 'title' => $page['plain_title'] !== '' ? $page['plain_title'] : 'Untitled', 'content' => $content, 'noindex' => $noindex]); ?>
