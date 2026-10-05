<?php /** The one search (screen `search`). Data: parsed, asked, groups, total, page, notices, dead, tz, here, options */ ?>
<?= view('shared/header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]], 'back' => back_link()]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" hx-get="/search" hx-target="#page-content" hx-push-url="true" id="search-form" class="card mb-3"><div class="card-body">
        <div class="input-group mb-2">
            <input type="search" name="q" id="search-form-field-q" class="form-control btn-touch" value="<?= e(search_query_string(['q' => $parsed['q']])) ?>" placeholder="Search pages, rows, messages and comments" aria-label="Search" autocomplete="off" enterkeyhint="search">
            <button type="submit" class="btn btn-primary btn-touch" id="search-form-btn"><i class="feather-search me-1"></i>Search</button>
        </div>
        <details id="search-narrow"<?= array_filter([$parsed['in'], $parsed['from'], $parsed['has'], $parsed['before'], $parsed['after'], $parsed['is'], $parsed['space']], static fn ($v): bool => $v !== null) !== [] ? ' open' : '' ?>>
            <summary class="fs-12 fw-semibold btn-touch d-flex align-items-center">Narrow it down</summary>
            <div class="row g-2 mt-1">
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-is">Kind</label>
                    <select name="is" id="search-form-field-is" class="form-select btn-touch"><option value="">Anything</option><?php foreach (SEARCH_IS as $k): ?><option value="<?= $k ?>"<?= $parsed['is'] === $k ? ' selected' : '' ?>><?= e(SEARCH_GROUPS[$k][0]) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-has">Has</label>
                    <select name="has" id="search-form-field-has" class="form-select btn-touch"><option value="">Anything</option><option value="link"<?= $parsed['has'] === 'link' ? ' selected' : '' ?>>A link</option><option value="file"<?= $parsed['has'] === 'file' ? ' selected' : '' ?>>A file</option></select></div>
                <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-space">Space</label>
                    <select name="space" id="search-form-field-space" class="form-select btn-touch"><option value="">Every space</option><?php foreach ($options['spaces'] as $s): ?><option value="<?= (int) $s['space_id'] ?>"<?= (string) $parsed['space'] === (string) $s['space_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-in">In channel</label>
                    <select name="in" id="search-form-field-in" class="form-select btn-touch"><option value="">Any channel</option><?php foreach ($options['channels'] as $c): ?><option value="<?= (int) $c['channel_id'] ?>"<?= ltrim((string) $parsed['in'], '#') === (string) $c['channel_id'] || ltrim((string) $parsed['in'], '#') === $c['name'] ? ' selected' : '' ?>>#<?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-from">From</label>
                    <select name="from" id="search-form-field-from" class="form-select btn-touch"><option value="">Anyone</option><option value="@me"<?= $parsed['from'] === '@me' ? ' selected' : '' ?>>Me</option><?php foreach ($options['members'] as $m): ?><option value="<?= (int) $m['member_id'] ?>"<?= ltrim((string) $parsed['from'], '@') === (string) $m['member_id'] ? ' selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-after">After</label><input type="date" name="after" id="search-form-field-after" class="form-control btn-touch" value="<?= e((string) $parsed['after']) ?>"></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="search-form-field-before">Before</label><input type="date" name="before" id="search-form-field-before" class="form-control btn-touch" value="<?= e((string) $parsed['before']) ?>"></div>
            </div>
        </details>
    </div></form>
    <?php foreach ($notices as $nt): ?><div class="alert alert-warning fs-12 mb-2" role="status" data-search-notice><?= e($nt) ?></div><?php endforeach; ?>
    <?= view('search/partials/chips.php', ['parsed' => $parsed]) ?>
    <?php if (!$asked): ?>
        <div class="card" id="search-help"><div class="card-body">
            <div class="fw-semibold mb-2">What you can type</div>
            <div class="fs-12 text-muted mb-2">Words find pages, database rows, messages and comments you may read. Narrow them with modifiers:</div>
            <ul class="fs-12 mb-2 ps-3"><li><code>in:#ops</code> a channel</li><li><code>from:@priya</code> a person (<code>from:@me</code> is you)</li><li><code>has:link</code> or <code>has:file</code></li>
                <li><code>before:2026-09-01</code> and <code>after:2026-08-01</code></li><li><code>is:page</code>, <code>is:row</code>, <code>is:message</code> or <code>is:comment</code></li><li><code>space:Product</code></li></ul>
            <div class="fs-12 text-muted">A modifier alone lists the newest first, e.g. <?= hx_link('/search?q=is%3Apage', '<code>is:page</code>', '', 'id="search-example-is-page"') ?>. Press Enter to search.</div>
        </div></div>
    <?php else: ?>
        <?= view('search/partials/results.php', ['groups' => $groups, 'total' => $total, 'page' => $page, 'parsed' => $parsed, 'dead' => $dead, 'tz' => $tz, 'here' => $here]) ?>
    <?php endif; ?>
</div>
