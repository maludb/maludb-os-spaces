<?php /** The editor (block-editor.md): the body region of page-view for someone with edit. Data: p, tree (JSON), bodyHtml (editor mode), settings, counts (comment counts), me, csrf, presence (others) */ $pid = (string) $p['page_id']; ?>
<div id="editor" class="sp-editor" data-page="<?= e($pid) ?>" data-rev="<?= (int) $p['content_rev'] ?>" data-level="<?= e($p['my_level']) ?>" data-row="<?= $p['is_row'] ? '1' : '0' ?>" data-max-bytes="<?= (int) $settings['max_attachment_bytes'] ?>">
    <script type="application/json" id="page-tree"><?= str_replace('</', '<\/', json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></script>
    <script type="application/json" id="editor-settings"><?= str_replace('</', '<\/', json_encode(['block_types' => $settings['block_types'], 'embed_hosts' => $settings['embed_hosts'], 'colors' => $settings['colors'], 'comment_counts' => $counts, 'me' => $me], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></script>
    <?= view('pages/partials/presence.php', ['others' => $presence]) ?>
    <div class="alert alert-warning d-none" id="changed-banner" role="status"><i class="feather-refresh-cw me-1"></i><span id="changed-banner-text">Changed by someone else</span> — <a href="/pages/<?= e($pid) ?>" id="changed-banner-reload">reload the page</a>.</div>
    <div class="alert alert-dark d-none" id="locked-banner" role="status"><i class="feather-lock me-1"></i>This page was locked: it is read-only now.</div>
    <noscript><div class="alert alert-light border" id="editor-noscript">Editing needs JavaScript. The page is shown as a reader.</div></noscript>
    <div class="sp-body sp-editor-body" id="page-body" data-children-of=""><?= $bodyHtml ?></div>
    <div class="sp-editor-tail"><button type="button" class="btn btn-light btn-sm btn-touch" id="editor-append-btn"><i class="feather-plus me-1"></i>Add a block</button></div>
    <?= view('pages/partials/slash-menu.php', ['types' => $settings['block_types']]) ?>
    <?= view('pages/partials/mention-picker.php') ?>
    <?= view('pages/partials/stale-box.php') ?>
    <input type="file" id="editor-file-input" class="d-none" accept="image/*,.pdf,.txt,.csv,.md,.json,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,audio/*,video/*">
</div>
<link rel="stylesheet" href="/assets/css/editor.css">
<script src="/assets/js/editor.js" defer></script>
