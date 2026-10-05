<?php /** My trail (screen `trail`). Data: rows, page, more, filters, query, tz, resultsHtml, record (['space'|'channel'|'message'|'page', id]|null) */
$actions = ['' => 'Anything', 'space.' => 'Spaces', 'page.' => 'Pages', 'block.' => 'Edits', 'database.' => 'Databases', 'row.' => 'Rows', 'channel.' => 'Channels', 'message.' => 'Messages',
            'thread.' => 'Threads', 'comment.' => 'Comments', 'agent.' => 'Agents', 'member.' => 'Sign-ons', 'token.' => 'Tokens', 'prefs.' => 'Settings', 'directory.' => 'Directory refreshes'];
?>
<?= view('shared/header.php', ['id' => 'trail', 'title' => 'My trail', 'crumbs' => [['Home', '/'], ['My trail', null]], 'back' => back_link()]) ?>
<div class="main-content" id="trail-content">
    <div class="card stretch stretch-full" id="trail-card">
        <div class="card-header">
            <h5 class="card-title"><?= $record !== null ? 'The ' . e($record[0]) . '\'s history' : 'What you did' ?></h5>
            <form id="trail-filters" class="d-flex flex-wrap gap-2" method="get" action="/trail" hx-get="/trail" hx-target="#trail-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <?php if ($record !== null): ?><input type="hidden" name="<?= e($record[0]) ?>" value="<?= e((string) $record[1]) ?>"><?php endif; ?>
                <select name="action" id="trail-filter-action" class="form-select form-select-sm w-auto btn-touch" aria-label="What"><?php foreach ($actions as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['action'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select name="period" id="trail-filter-period" class="form-select form-select-sm w-auto btn-touch" aria-label="Period"><option value="">All time</option><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['since'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
